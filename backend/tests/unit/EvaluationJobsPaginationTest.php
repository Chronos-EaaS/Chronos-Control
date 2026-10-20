<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Shared\Dto;
use Chronos\Http\Api\Ui\WorkflowRoutes;
use Chronos\Http\HttpError;
use Chronos\Http\Request;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Realtime\LiveProjection;
use Chronos\Infrastructure\Realtime\LiveTickets;
use Chronos\Infrastructure\Security\SessionEpoch;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EvaluationJobsPaginationTest extends TestCase
{
    private PDO $database;
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/chronos-evaluation-jobs-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root . '/sessions', 0700);
        session_save_path($this->root . '/sessions');
        session_id(bin2hex(random_bytes(16))); session_start();
        $this->database = class_exists(\Pdo\Sqlite::class)
            ? new \Pdo\Sqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
            : new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $concat = static fn(string $separator, mixed ...$values): string => implode($separator, array_filter($values, static fn(mixed $value): bool => $value !== null));
        if ($this->database instanceof \Pdo\Sqlite) $this->database->createFunction('CONCAT_WS', $concat);
        else $this->database->sqliteCreateFunction('CONCAT_WS', $concat);
        $this->database->exec('CREATE TABLE User(userId INTEGER PRIMARY KEY,username TEXT,password TEXT,firstname TEXT,lastname TEXT,email TEXT,role INTEGER,gender INTEGER,enabled INTEGER,activated INTEGER)');
        $this->database->exec("INSERT INTO User VALUES(1,'owner','synthetic-hash','Synthetic','Owner','owner@example.test',0,0,1,1),(2,'outsider','other-hash','Other','Owner','other@example.test',0,0,1,1)");
        $this->database->exec('CREATE TABLE System(systemId INTEGER PRIMARY KEY,name TEXT)');
        $this->database->exec("INSERT INTO System VALUES(1,'Synthetic system')");
        $this->database->exec('CREATE TABLE Project(projectId INTEGER PRIMARY KEY,systemId INTEGER,userId INTEGER,name TEXT)');
        $this->database->exec("INSERT INTO Project VALUES(1,1,1,'Own project'),(2,1,2,'Other project')");
        $this->database->exec('CREATE TABLE ProjectUser(projectUserId INTEGER PRIMARY KEY,projectId INTEGER,userId INTEGER)');
        $this->database->exec('CREATE TABLE Experiment(experimentId INTEGER PRIMARY KEY,projectId INTEGER,systemId INTEGER,internalId INTEGER,name TEXT,resultId TEXT)');
        $this->database->exec("INSERT INTO Experiment VALUES(1,1,1,1,'Own experiment',NULL),(2,2,1,2,'Other experiment',NULL)");
        $this->database->exec('CREATE TABLE Evaluation(evaluationId INTEGER PRIMARY KEY,experimentId INTEGER,systemId INTEGER,internalId INTEGER,name TEXT)');
        $this->database->exec("INSERT INTO Evaluation VALUES(1,1,1,1,'Large evaluation'),(2,2,1,2,'Private evaluation'),(3,1,1,3,'Other own evaluation')");
        $this->database->exec('CREATE TABLE Job(jobId INTEGER PRIMARY KEY,evaluationId INTEGER,systemId INTEGER,userId INTEGER,internalId INTEGER,description TEXT,environment TEXT,status INTEGER,progress INTEGER,phases INTEGER,currentPhase INTEGER,configuration TEXT,created TEXT,started TEXT,finished TEXT)');
        $insert = $this->database->prepare("INSERT INTO Job VALUES(?,?,1,1,?,'Synthetic job','system-local',?,?,2,NULL,'{}',NULL,NULL,NULL)");
        for ($id = 1; $id <= 1001; ++$id) $insert->execute([$id, 1, $id, $id % 2 === 0 ? 3 : 0, $id % 3]);
        $insert->execute([2001, 2, 1, 3, 0]);
        $insert->execute([3001, 3, 1, 3, 0]);
        $this->database->exec('CREATE INDEX JobEvaluationOrder ON Job(evaluationId,internalId,jobId)');
        $this->database->exec('CREATE TABLE Node(nodeId TEXT,hostname TEXT,lastUpdate TEXT,currentJob INTEGER)');
        $this->database->exec('CREATE TABLE Setting(settingId INTEGER PRIMARY KEY,systemId INTEGER,section TEXT,item TEXT,value TEXT)');
        $this->database->exec("INSERT INTO Setting VALUES(1,0,'other','rowsPerPage','5')");
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION = []; session_destroy(); }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    private function read(string $path, array $query = []): array
    {
        $request = new Request('GET', '/api/ui' . $path . ($query === [] ? '' : '?' . http_build_query($query)));
        return (new WorkflowRoutes($request, new Store($this->database), ['id' => 1, 'role' => 0], $this->root))->handle($path)['body']['data'];
    }

    public function testScopedAllRowsReturnsEveryJobWithStableSortAndNoOtherEvaluationRows(): void
    {
        $result = $this->read('/evaluations/1/jobs', ['pageSize' => 'all', 'sort' => '-progress']);
        $expected = range(1, 1001);
        usort($expected, static fn(int $left, int $right): int => ($right % 3) <=> ($left % 3) ?: $left <=> $right);
        self::assertSame($expected, array_column($result['items'], 'id'));
        self::assertSame([1], array_values(array_unique(array_column($result['items'], 'evaluationId'))));
        self::assertSame(1001, $result['total']);
        self::assertSame(1001, $result['pageSize']);
        self::assertSame(1, $result['page']);
    }

    public function testAllRowsKeepsStatusFiltersAndReportsEmptyResults(): void
    {
        $finished = $this->read('/evaluations/1/jobs', ['pageSize' => 'all', 'status' => 'finished', 'sort' => '-id']);
        self::assertSame(range(1000, 2, -2), array_column($finished['items'], 'id'));
        self::assertSame(500, $finished['total']);
        self::assertSame([3], array_values(array_unique(array_column($finished['items'], 'status'))));
        self::assertSame(['items' => [], 'total' => 0, 'page' => 1, 'pageSize' => 1], $this->read('/evaluations/1/jobs', ['pageSize' => 'all', 'status' => 'aborted']));
    }

    public function testNumericScopedAndGlobalPaginationStillUseTheirExistingBounds(): void
    {
        self::assertSame([1, 2, 3, 4, 5], array_column($this->read('/evaluations/1/jobs')['items'], 'id'));
        $page = $this->read('/evaluations/1/jobs', ['pageSize' => '100', 'page' => '2']);
        self::assertSame(range(101, 200), array_column($page['items'], 'id'));
        self::assertSame(1001, $page['total']); self::assertSame(2, $page['page']); self::assertSame(100, $page['pageSize']);
        $global = $this->read('/jobs', ['pageSize' => '100']);
        self::assertCount(100, $global['items']); self::assertSame(1002, $global['total']);
        $this->expectException(HttpError::class);
        try { $this->read('/jobs', ['pageSize' => 'all']); }
        catch (HttpError $error) { self::assertSame(422, $error->status); self::assertArrayHasKey('pageSize', $error->fields); throw $error; }
    }

    public function testAllRowsCannotBypassTheEvaluationProjectAcl(): void
    {
        $this->expectException(HttpError::class);
        try { $this->read('/evaluations/2/jobs', ['pageSize' => 'all']); }
        catch (HttpError $error) { self::assertSame(403, $error->status); throw $error; }
    }

    public function testLiveProjectionUsesTheSameAllRowsContract(): void
    {
        $row = $this->database->query('SELECT * FROM User WHERE userId=1')->fetch(PDO::FETCH_ASSOC);
        $_SESSION['v3Identity'] = ['epoch' => SessionEpoch::current($this->root), 'credentials' => hash('sha256', json_encode([1, 'synthetic-hash', 0, true, true], JSON_THROW_ON_ERROR))];
        $tickets = new LiveTickets($this->root, $this->database);
        $lease = $tickets->consume($tickets->issueForCurrentSession(Dto::user($row))['ticket']);
        $projection = new LiveProjection($this->root, $this->database);
        $result = $projection->read($lease, '/evaluations/1/jobs', ['pageSize' => 'all']);
        self::assertTrue($result['ok']); self::assertCount(1001, $result['data']['items']);
        self::assertSame(range(1, 1001), array_column($result['data']['items'], 'id'));
        self::assertSame(403, $projection->read($lease, '/evaluations/2/jobs', ['pageSize' => 'all'])['status']);
        self::assertSame(422, $projection->read($lease, '/jobs', ['pageSize' => 'all'])['status']);
    }
}
