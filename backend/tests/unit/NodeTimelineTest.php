<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Cem\NodeService;
use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\EventProjection;
use Chronos\Http\Api\Ui\AdminRoutes;
use Chronos\Http\HttpError;
use Chronos\Http\Request;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Authentication;
use Chronos\Infrastructure\Security\Sessions;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class NodeTimelineStatement extends PDOStatement
{
    protected function __construct(private readonly object $counter) {}
    public function execute(?array $params = null): bool
    {
        $this->counter->queries[] = $this->queryString;
        return parent::execute($params);
    }
}

final class NodeTimelineTest extends TestCase
{
    private PDO $database;
    private object $counter;

    protected function setUp(): void
    {
        $this->database = class_exists(\Pdo\Sqlite::class)
            ? new \Pdo\Sqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
            : new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $concat = static fn(string $separator, mixed ...$values): string => implode($separator, array_filter($values, static fn(mixed $value): bool => $value !== null));
        if ($this->database instanceof \Pdo\Sqlite) $this->database->createFunction('CONCAT_WS', $concat);
        else $this->database->sqliteCreateFunction('CONCAT_WS', $concat);
        $this->counter = (object) ['queries' => []];
        $this->database->setAttribute(PDO::ATTR_STATEMENT_CLASS, [NodeTimelineStatement::class, [$this->counter]]);
        $this->database->exec('CREATE TABLE User(userId INTEGER PRIMARY KEY,firstname TEXT,lastname TEXT); CREATE TABLE System(systemId INTEGER PRIMARY KEY,name TEXT); CREATE TABLE Project(projectId INTEGER PRIMARY KEY,systemId INTEGER,userId INTEGER,name TEXT); CREATE TABLE ProjectUser(projectUserId INTEGER PRIMARY KEY,projectId INTEGER,userId INTEGER); CREATE TABLE Experiment(experimentId INTEGER PRIMARY KEY,projectId INTEGER,systemId INTEGER,internalId INTEGER,name TEXT,resultId TEXT); CREATE TABLE Evaluation(evaluationId INTEGER PRIMARY KEY,experimentId INTEGER,systemId INTEGER,internalId INTEGER,name TEXT); CREATE TABLE Job(jobId INTEGER PRIMARY KEY,evaluationId INTEGER,systemId INTEGER,internalId INTEGER,status INTEGER,result TEXT); CREATE TABLE Event(eventId INTEGER PRIMARY KEY,title TEXT,time TEXT,eventText TEXT,eventType TEXT,relatedId INTEGER,userId INTEGER,nodeId TEXT); CREATE TABLE Node(nodeId TEXT PRIMARY KEY,hostname TEXT,environment TEXT,version TEXT,os TEXT,ip TEXT,currentJob INTEGER,cpu REAL,memoryUsed INTEGER,memoryTotal INTEGER,healthStatus TEXT,lastUpdate TEXT); CREATE TABLE Setting(settingId INTEGER PRIMARY KEY,systemId INTEGER,section TEXT,item TEXT,value TEXT)');
        $this->database->exec("INSERT INTO User VALUES(1,'Synthetic','Administrator'),(2,'Private','Owner'); INSERT INTO System VALUES(1,'Synthetic system'); INSERT INTO Project VALUES(1,1,2,'Private &amp; project'); INSERT INTO Experiment VALUES(1,1,1,1,'Synthetic experiment',NULL); INSERT INTO Evaluation VALUES(1,1,1,1,'Synthetic evaluation'); INSERT INTO Node VALUES('test-node','Synthetic node','cem-fixture','3.0-dev','Synthetic OS','127.0.0.1',NULL,12.5,256,1024,'Synthetic warning','2026-10-20 12:34:56')");
        $this->counter->queries = [];
    }

    private function context(): Context
    {
        return new Context(new Store($this->database), ['id' => 1, 'role' => 1], '/synthetic');
    }

    private function originalEvents(): array
    {
        $rows = (new Store($this->database))->rows('SELECT e.*,u.firstname AS actorFirstName,u.lastname AS actorLastName FROM Event e LEFT JOIN User u ON u.userId=e.userId WHERE e.nodeId=? ORDER BY e.time DESC,e.eventId DESC LIMIT 50', ['test-node']);
        $projection = new EventProjection($this->context());
        return array_map(static fn(array $row): array => $projection->event($row) + ['jobId' => $row['relatedId'] === null ? null : (int) $row['relatedId']], $rows);
    }

    public function testFiftyDistinctJobsKeepOriginalDtosAndOrderingWithConstantQueryCount(): void
    {
        $job = $this->database->prepare('INSERT INTO Job VALUES(?,1,1,?,3,?)');
        $event = $this->database->prepare("INSERT INTO Event VALUES(?,'<b>Node changed</b>','2026-10-20 12:34:56','Details &amp; status','node',?,1,'test-node')");
        for ($id = 1; $id <= 55; ++$id) {
            $job->execute([$id, $id, str_repeat('Synthetic large result ', 2000)]);
            $event->execute([$id, $id]);
        }
        $this->database->exec("INSERT INTO Event VALUES(9999,'Different node','2026-10-21 12:34:56','Foreign','node',1,1,'other-node')");
        $expected = $this->originalEvents();
        $this->counter->queries = [];
        $result = (new NodeService($this->context()))->nodeDetail('test-node');
        self::assertSame($expected, $result['events']);
        self::assertSame(range(55, 6, -1), array_column($result['events'], 'id'));
        self::assertCount(4, $this->counter->queries);
        self::assertSame('test-node', $result['node']['id']);
        self::assertSame('Synthetic node', $result['node']['hostname']);
        self::assertSame(25, $result['node']['memoryPercent']);
        self::assertSame('Node changed', $result['events'][0]['title']);
        self::assertSame('Details & status', $result['events'][0]['text']);
        self::assertSame('Synthetic Administrator', $result['events'][0]['actorName']);
        self::assertSame('2026-10-20T12:34:56+02:00', $result['events'][0]['at']);
        self::assertSame(55, $result['events'][0]['jobId']);
        self::assertSame([
            ['type'=>'project','id'=>1,'label'=>'Private & project'],
            ['type'=>'experiment','id'=>1,'label'=>'Synthetic experiment'],
            ['type'=>'evaluation','id'=>1,'label'=>'Synthetic evaluation'],
            ['type'=>'job','id'=>55,'label'=>'Job #55'],
            ['type'=>'node','id'=>'test-node','label'=>'Node test-node'],
        ], $result['events'][0]['resources']);
    }

    public function testDeletedActorsAndMissingJobsRetainEventsAndTheirNodeLinks(): void
    {
        $this->database->exec("INSERT INTO Event VALUES(1,'No actor','2026-10-20 12:34:56','Synthetic','node',999,NULL,'test-node'),(2,'Deleted actor','2026-10-20 12:34:56','Synthetic','node',NULL,999,'test-node'),(3,'Job event','2026-10-19 12:34:56','Synthetic','job',999,1,'test-node')");
        $expected = $this->originalEvents();
        $this->counter->queries = [];
        $result = (new NodeService($this->context()))->nodeDetail('test-node');
        self::assertSame($expected, $result['events']);
        self::assertSame([2, 1, 3], array_column($result['events'], 'id'));
        self::assertSame(['', '', 'Synthetic Administrator'], array_column($result['events'], 'actorName'));
        self::assertSame([null, 999, 999], array_column($result['events'], 'jobId'));
        self::assertSame([['type'=>'node','id'=>'test-node','label'=>'Node test-node']], $result['events'][0]['resources']);
        self::assertLessThanOrEqual(4, count($this->counter->queries));
    }

    public function testEmptyNodeTimelineReturnsNoEvents(): void
    {
        $result = (new NodeService($this->context()))->nodeDetail('test-node');
        self::assertSame([], $result['events']);
        self::assertCount(3, $this->counter->queries);
    }

    public function testMissingNodeKeepsNotFoundResponse(): void
    {
        $this->expectException(HttpError::class);
        try { (new NodeService($this->context()))->nodeDetail('missing-node'); }
        catch (HttpError $error) { self::assertSame(404, $error->status); throw $error; }
    }

    public function testNodeHttpRouteStillRejectsNonAdministratorsBeforeReadingData(): void
    {
        // Authentication is unused by this GET path; retain the real route gate.
        $auth = (new \ReflectionClass(Authentication::class))->newInstanceWithoutConstructor();
        $routes = new AdminRoutes(new Request('GET', '/api/ui/cem/nodes/test-node'), new Store($this->database), ['id'=>1,'role'=>0], '/synthetic', new Sessions($auth));
        $this->expectException(HttpError::class);
        try { $routes->handle('/cem/nodes/test-node'); }
        catch (HttpError $error) {
            self::assertSame(403, $error->status);
            self::assertSame([], $this->counter->queries);
            throw $error;
        }
    }
}
