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
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

final class EvaluationJobsStatement extends PDOStatement
{
    protected function __construct(private readonly object $counter) {}
    public function execute(?array $params = null): bool
    {
        $this->counter->queries[] = $this->queryString;
        return parent::execute($params);
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EvaluationJobsPaginationTest extends TestCase
{
    private PDO $database;
    private string $root;
    private object $counter;

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
        $this->counter = (object) ['queries' => []];
        $this->database->setAttribute(PDO::ATTR_STATEMENT_CLASS, [EvaluationJobsStatement::class, [$this->counter]]);
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

    public function testSummaryReturnsEveryJobWithBoundedQueriesAndNoDetailPayload(): void
    {
        $this->database->exec('ALTER TABLE Job ADD COLUMN result TEXT');
        $this->database->prepare('UPDATE Job SET configuration=?,result=?')->execute([json_encode(['large' => str_repeat('x', 16384)]), str_repeat('r', 32768)]);
        $this->counter->queries = [];
        $result = $this->read('/evaluations/1/jobs', ['pageSize' => 'all', 'view' => 'summary']);
        self::assertSame(range(1, 1001), array_column($result['items'], 'id'));
        self::assertSame(1001, $result['total']);
        self::assertCount(4, $this->counter->queries, 'One authorization check and one list query must cover any evaluation size.');
        self::assertSame(['id','internalId','description','evaluationId','status','statusName','progress','phases','currentPhase','setupRequired','permissions'], array_keys($result['items'][0]));
        self::assertLessThan(400000, strlen(json_encode($result, JSON_THROW_ON_ERROR)), 'List updates must not resend job configuration or results.');
        self::assertStringNotContainsString('j.*', $this->counter->queries[3]);
        self::assertStringNotContainsString('configuration', $this->counter->queries[3]);
        self::assertStringNotContainsString('result', $this->counter->queries[3]);
        $this->database->exec('DELETE FROM Job WHERE jobId<>1');
        $this->counter->queries = [];
        self::assertCount(1, $this->read('/evaluations/1/jobs', ['pageSize' => 'all', 'view' => 'summary'])['items']);
        self::assertCount(4, $this->counter->queries, 'Query count must not depend on the job count.');
    }

    public function testSummaryKeepsRawAgentProgressPhaseFiltersAndDownloadAvailability(): void
    {
        mkdir($this->root . '/webroot/data/evaluation', 0700, true);
        file_put_contents($this->root . '/webroot/data/evaluation/2.zip', 'synthetic archive');
        $this->database->exec("UPDATE Job SET currentPhase=3,phases=2,progress=0,environment='cem-synthetic' WHERE jobId=2");
        $result = $this->read('/evaluations/1/jobs', ['pageSize' => 'all', 'view' => 'summary', 'status' => 'finished', 'sort' => 'internalId']);
        self::assertSame(range(2, 1000, 2), array_column($result['items'], 'id'));
        self::assertSame(3, $result['items'][0]['currentPhase']);
        self::assertSame(2, $result['items'][0]['phases']);
        self::assertSame(0, $result['items'][0]['progress'], 'A finished legacy job can retain zero raw phase progress.');
        self::assertTrue($result['items'][0]['setupRequired']);
        self::assertTrue($result['items'][0]['permissions']['canDownload']);
        self::assertFalse($result['items'][1]['permissions']['canDownload']);
        self::assertSame(['items'=>[],'total'=>0,'page'=>1,'pageSize'=>1], $this->read('/evaluations/1/jobs', ['pageSize'=>'all','view'=>'summary','status'=>'aborted']));
    }

    public function testSummaryIsExplicitAndCannotBypassValidationOrAuthorization(): void
    {
        $full = $this->read('/evaluations/1/jobs', ['pageSize' => '1']);
        self::assertArrayHasKey('configuration', $full['items'][0]);
        self::assertArrayHasKey('owner', $full['items'][0]);
        foreach ([['/jobs',['view'=>'summary'],422],['/evaluations/1/jobs',['view'=>'unknown'],422],['/evaluations/2/jobs',['view'=>'summary'],403]] as [$path,$query,$status]) {
            try { $this->read($path, $query); self::fail('Expected the request to be rejected.'); }
            catch (HttpError $error) { self::assertSame($status, $error->status); }
        }
    }

    public function testSummarySortsDisplayedOverallProgressWhileDefaultResponsesKeepRawPhaseSorting(): void
    {
        $this->database->exec('ALTER TABLE Experiment ADD COLUMN status INTEGER DEFAULT 0');
        $this->database->exec('ALTER TABLE Experiment ADD COLUMN phases INTEGER DEFAULT 31');
        $this->database->exec('DELETE FROM Job WHERE evaluationId=1 AND jobId>8');
        $update = $this->database->prepare('UPDATE Job SET status=?,progress=?,phases=?,currentPhase=? WHERE jobId=?');
        foreach ([[3,0,0,5,1],[2,50,0,3,2],[2,0,0,4,3],[0,100,0,5,4],[-1,40,0,3,5],[2,50,2,3,6],[2,49,2,3,7],[2,51,2,3,8]] as $values) $update->execute($values);
        foreach (['progress'=>[4,7,6,8,5,2,3,1],'-progress'=>[1,3,2,5,6,8,7,4]] as $sort => $expected) {
            $summary = $this->read('/evaluations/1/jobs', ['pageSize'=>'all','view'=>'summary','sort'=>$sort]);
            self::assertSame($expected, array_column($summary['items'], 'id'), 'Summary progress sorts the visible rounded overall percentage with stable ID ties.');
        }
        foreach (['progress'=>[1,3,5,7,2,6,8,4],'-progress'=>[4,8,2,6,7,5,1,3]] as $sort => $expected) {
            $full = $this->read('/evaluations/1/jobs', ['pageSize'=>'all','sort'=>$sort]);
            self::assertSame($expected, array_column($full['items'], 'id'), 'The default API retains raw agent stage-progress ordering.');
            self::assertArrayHasKey('configuration', $full['items'][0]);
        }
    }

    public function testLiveProjectionUsesTheSameAllRowsContract(): void
    {
        $row = $this->database->query('SELECT * FROM User WHERE userId=1')->fetch(PDO::FETCH_ASSOC);
        $_SESSION['v3Identity'] = ['epoch' => SessionEpoch::current($this->root), 'credentials' => hash('sha256', json_encode([1, 'synthetic-hash', 0, true, true], JSON_THROW_ON_ERROR))];
        $tickets = new LiveTickets($this->root, $this->database);
        $lease = $tickets->consume($tickets->issueForCurrentSession(Dto::user($row))['ticket']);
        $projection = new LiveProjection($this->root, $this->database);
        $result = $projection->read($lease, '/evaluations/1/jobs', ['pageSize' => 'all', 'view' => 'summary']);
        self::assertTrue($result['ok']); self::assertCount(1001, $result['data']['items']);
        self::assertSame(range(1, 1001), array_column($result['data']['items'], 'id'));
        self::assertArrayNotHasKey('configuration', $result['data']['items'][0]);
        self::assertSame(403, $projection->read($lease, '/evaluations/2/jobs', ['pageSize' => 'all'])['status']);
        self::assertSame(422, $projection->read($lease, '/jobs', ['pageSize' => 'all'])['status']);
    }
}
