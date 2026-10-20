<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Input;
use Chronos\Application\Workflows\WorkflowProjection;
use Chronos\Infrastructure\Persistence\Store;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkflowTimelineStatement extends PDOStatement
{
    protected function __construct(private readonly object $counter) {}
    public function execute(?array $params = null): bool
    {
        $this->counter->queries[] = $this->queryString;
        if (isset($this->counter->beforeExecute)) ($this->counter->beforeExecute)($this->queryString);
        return parent::execute($params);
    }
}

final class WorkflowTimelineTest extends TestCase
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
        $this->database->setAttribute(PDO::ATTR_STATEMENT_CLASS, [WorkflowTimelineStatement::class, [$this->counter]]);
        $this->database->exec('CREATE TABLE User(userId INTEGER PRIMARY KEY,firstname TEXT,lastname TEXT); CREATE TABLE System(systemId INTEGER PRIMARY KEY,name TEXT); CREATE TABLE Project(projectId INTEGER PRIMARY KEY,systemId INTEGER,userId INTEGER,name TEXT); CREATE TABLE ProjectUser(projectUserId INTEGER PRIMARY KEY,projectId INTEGER,userId INTEGER); CREATE TABLE Experiment(experimentId INTEGER PRIMARY KEY,projectId INTEGER,systemId INTEGER,internalId INTEGER,name TEXT); CREATE TABLE Evaluation(evaluationId INTEGER PRIMARY KEY,experimentId INTEGER,systemId INTEGER,internalId INTEGER,name TEXT); CREATE TABLE Job(jobId INTEGER PRIMARY KEY,evaluationId INTEGER,systemId INTEGER,internalId INTEGER); CREATE TABLE Event(eventId INTEGER PRIMARY KEY,title TEXT,time TEXT,eventText TEXT,eventType TEXT,relatedId INTEGER,userId INTEGER,nodeId TEXT)');
        $this->database->exec("INSERT INTO User VALUES(1,'Synthetic','Owner'),(2,'Other','Owner'); INSERT INTO System VALUES(1,'Synthetic system'); INSERT INTO Project VALUES(1,1,1,'Own &amp; project'),(2,1,2,'Shared project'),(3,1,2,'Private project'); INSERT INTO ProjectUser VALUES(1,2,1),(2,2,1); INSERT INTO Experiment VALUES(1,1,1,1,'Own experiment'),(2,1,1,2,'Sibling experiment'),(3,2,1,1,'Shared experiment'),(4,3,1,1,'Private experiment'); INSERT INTO Evaluation VALUES(1,1,1,1,'Own evaluation'),(2,2,1,1,'Sibling evaluation'),(3,3,1,1,'Shared evaluation'),(4,4,1,1,'Private evaluation'); INSERT INTO Job VALUES(1,1,1,1),(2,1,1,2),(3,2,1,1),(4,3,1,1),(5,4,1,1)");
        $insert = $this->database->prepare("INSERT INTO Event VALUES(?,'<b>Changed</b>',?,'Text &amp; details',?,?,?,NULL)");
        foreach ([[1,'project',1],[2,'experiment',1],[3,'evaluation',1],[4,'job',1],[5,'node',1],[6,'job',2],[7,'evaluation',2],[8,'job',3],[9,'experiment',2],[10,'project',2],[11,'job',4],[12,'experiment',3],[13,'evaluation',3],[14,'project',3],[15,'experiment',4],[16,'evaluation',4],[17,'job',5],[18,'user',1],[19,'job',999],[20,'evaluation',999],[21,'unknown',1],[22,'project',1]] as [$eventId,$type,$id]) {
            $insert->execute([$eventId, in_array($eventId, [1,22], true) ? '2026-01-02 12:00:00' : '2026-01-01 12:00:00', $type, $id, $eventId === 22 ? 999 : ($eventId === 3 ? null : 1)]);
        }
        $this->counter->queries = [];
    }

    private function events(string $type = 'project', int $id = 1, array $query = [], int $role = 0): array
    {
        $context = new Context(new Store($this->database), ['id' => 1, 'role' => $role], '/synthetic');
        return (new WorkflowProjection($context))->events($type, $id, Input::listOptions($query, ['id'=>'v.eventId','at'=>'v.time'], '-id'));
    }

    public static function hierarchies(): iterable
    {
        yield 'project with all descendants' => ['project', 1, [22,9,8,7,6,4,3,2,1]];
        yield 'experiment with its evaluations and jobs' => ['experiment', 1, [6,4,3,2]];
        yield 'evaluation with its jobs' => ['evaluation', 1, [6,4,3]];
        yield 'one job' => ['job', 1, [4]];
    }

    #[DataProvider('hierarchies')]
    public function testHierarchiesExcludeSiblingsUnknownTypesAndOrphans(string $type, int $id, array $ids): void
    {
        $result = $this->events($type, $id);
        self::assertSame($ids, array_column($result['items'], 'id'));
        self::assertSame(count($ids), $result['total']);
        self::assertSame(1, $result['page']);
        self::assertSame(20, $result['pageSize']);
        self::assertLessThanOrEqual(6, count($this->counter->queries));
    }

    public static function orders(): iterable
    {
        yield 'descending identifiers' => ['-id', [22,9,8,7,6,4,3,2,1]];
        yield 'ascending identifiers' => ['id', [1,2,3,4,6,7,8,9,22]];
        yield 'descending timestamps with ascending ID ties' => ['-at', [1,22,2,3,4,6,7,8,9]];
        yield 'ascending timestamps with ascending ID ties' => ['at', [2,3,4,6,7,8,9,1,22]];
    }

    #[DataProvider('orders')]
    public function testSortOrdersKeepTheExistingAscendingIdTie(string $sort, array $ids): void
    {
        self::assertSame($ids, array_column($this->events(query: ['sort'=>$sort])['items'], 'id'));
    }

    public function testPaginationKeepsExactTotalsAndClampsTheLastAndEmptyPages(): void
    {
        $middle = $this->events(query: ['pageSize'=>'3','page'=>'2']);
        self::assertSame([7,6,4], array_column($middle['items'], 'id'));
        self::assertSame(['total'=>9,'page'=>2,'pageSize'=>3], array_diff_key($middle, ['items'=>true]));
        $last = $this->events(query: ['pageSize'=>'3','page'=>'999']);
        self::assertSame([3,2,1], array_column($last['items'], 'id'));
        self::assertSame(3, $last['page']);
        $this->counter->queries = [];
        self::assertSame(['items'=>[],'total'=>0,'page'=>1,'pageSize'=>3], $this->events(id: 999, query: ['pageSize'=>'3','page'=>'999']));
        self::assertCount(1, $this->counter->queries);
    }

    public function testAccessChecksRespectSharedMembershipAdministratorsAndMissingParents(): void
    {
        self::assertSame([13,12,11,10], array_column($this->events(id: 2)['items'], 'id'));
        self::assertSame(4, $this->events(id: 2)['total'], 'Duplicate memberships must not duplicate events.');
        self::assertSame([], $this->events(id: 3)['items']);
        self::assertSame([17,16,15,14], array_column($this->events(id: 3, role: 1)['items'], 'id'));
        $this->database->exec('DELETE FROM Evaluation WHERE evaluationId=1');
        self::assertSame([22,9,8,7,2,1], array_column($this->events()['items'], 'id'));
        $this->database->exec('DELETE FROM System');
        self::assertSame([], $this->events(role: 1)['items']);
    }

    public function testBodiesDatesActorsAndHierarchyLinksRetainTheirDtos(): void
    {
        $result = $this->events();
        self::assertSame('', $result['items'][0]['actorName']);
        $job = array_values(array_filter($result['items'], static fn(array $item): bool => $item['id'] === 4))[0];
        self::assertSame('Changed', $job['title']);
        self::assertSame('Text & details', $job['text']);
        self::assertSame('2026-01-01T12:00:00+01:00', $job['at']);
        self::assertSame('Synthetic Owner', $job['actorName']);
        self::assertSame([
            ['type'=>'project','id'=>1,'label'=>'Own & project'],
            ['type'=>'experiment','id'=>1,'label'=>'Own experiment'],
            ['type'=>'evaluation','id'=>1,'label'=>'Own evaluation'],
            ['type'=>'job','id'=>1,'label'=>'Job #1'],
        ], $job['resources']);
        self::assertSame('', array_values(array_filter($result['items'], static fn(array $item): bool => $item['id'] === 3))[0]['actorName']);
    }

    public function testDistinctJobLinksUseOneBatchRatherThanQueriesPerEvent(): void
    {
        $job = $this->database->prepare('INSERT INTO Job VALUES(?,1,1,?)');
        $event = $this->database->prepare("INSERT INTO Event VALUES(?,'Job changed','2026-01-03 12:00:00','Body','job',?,1,NULL)");
        for ($id=100; $id<=125; ++$id) { $job->execute([$id,$id]); $event->execute([$id,$id]); }
        $this->counter->queries = [];
        $result = $this->events();
        self::assertSame(range(125,106,-1), array_column($result['items'], 'id'));
        self::assertSame(35, $result['total']);
        self::assertCount(3, $this->counter->queries);
        self::assertSame(['type'=>'job','id'=>125,'label'=>'Job #125'], $result['items'][0]['resources'][3]);
    }

    public function testSelectionRechecksMembershipAfterCounting(): void
    {
        $this->changeBeforeSelection(fn() => $this->database->exec('DELETE FROM ProjectUser WHERE projectId=2'));
        self::assertSame([], $this->events(id: 2)['items']);
    }

    public function testSelectionRechecksOwnershipAfterCounting(): void
    {
        $this->changeBeforeSelection(fn() => $this->database->exec('UPDATE Project SET userId=2 WHERE projectId=1'));
        self::assertSame([], $this->events()['items']);
    }

    public function testSelectionRechecksCurrentParentRelationships(): void
    {
        $this->changeBeforeSelection(fn() => $this->database->exec('UPDATE Job SET evaluationId=4 WHERE jobId=1'));
        self::assertSame([22,9,8,7,6,3,2,1], array_column($this->events()['items'], 'id'));
    }

    private function changeBeforeSelection(callable $change): void
    {
        $this->counter->beforeExecute = function (string $sql) use ($change): void {
            if (!str_starts_with($sql, 'SELECT v.*,u.firstname')) return;
            unset($this->counter->beforeExecute);
            $change();
        };
    }
}
