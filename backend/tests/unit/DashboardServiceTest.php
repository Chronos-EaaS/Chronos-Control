<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Dashboard\DashboardService;
use Chronos\Application\Dashboard\DashboardTimeline;
use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\EventProjection;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Persistence\Store;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class DashboardQueryStatement extends PDOStatement
{
    protected function __construct(private readonly object $counter) {}
    public function execute(?array $params = null): bool
    {
        $this->counter->queries[] = $this->queryString;
        if (isset($this->counter->beforeExecute)) ($this->counter->beforeExecute)($this->queryString);
        return parent::execute($params);
    }
}

final class DashboardServiceTest extends TestCase
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
        $this->database->setAttribute(PDO::ATTR_STATEMENT_CLASS, [DashboardQueryStatement::class, [$this->counter]]);
        $this->database->exec('CREATE TABLE User(userId INTEGER PRIMARY KEY,firstname TEXT,lastname TEXT); CREATE TABLE System(systemId INTEGER PRIMARY KEY,name TEXT); CREATE TABLE Project(projectId INTEGER PRIMARY KEY,systemId INTEGER,userId INTEGER,name TEXT); CREATE TABLE ProjectUser(projectUserId INTEGER PRIMARY KEY,projectId INTEGER,userId INTEGER); CREATE TABLE Experiment(experimentId INTEGER PRIMARY KEY,projectId INTEGER,systemId INTEGER,internalId INTEGER,name TEXT,resultId TEXT); CREATE TABLE Evaluation(evaluationId INTEGER PRIMARY KEY,experimentId INTEGER,systemId INTEGER,internalId INTEGER,name TEXT); CREATE TABLE Job(jobId INTEGER PRIMARY KEY,evaluationId INTEGER,systemId INTEGER,internalId INTEGER,status INTEGER); CREATE TABLE Event(eventId INTEGER PRIMARY KEY,title TEXT,time TEXT,eventText TEXT,eventType TEXT,relatedId INTEGER,userId INTEGER,nodeId TEXT)');
        $this->database->exec("INSERT INTO User VALUES(1,'Synthetic','Owner'),(2,'Other','Owner'); INSERT INTO System VALUES(1,'Synthetic system'); INSERT INTO Project VALUES(1,1,1,'Own &amp; project'),(2,1,2,'Shared project'),(3,1,2,'Private project'),(4,1,1,'Empty project'); INSERT INTO ProjectUser VALUES(1,2,1),(2,2,1); INSERT INTO Experiment VALUES(1,1,1,1,'Own experiment',NULL),(2,2,1,1,'Shared experiment',NULL),(3,3,1,1,'Private experiment',NULL); INSERT INTO Evaluation VALUES(1,1,1,1,'Completed evaluation'),(2,1,1,2,'Running evaluation'),(3,2,1,1,'Failed evaluation'),(4,2,1,2,'Unknown status'),(5,1,1,3,'Empty evaluation'),(6,3,1,1,'Private evaluation'); INSERT INTO Job VALUES(1,1,1,1,3),(2,1,1,2,-1),(3,2,1,1,0),(4,2,1,2,1),(5,2,1,3,2),(6,3,1,1,-2),(7,4,1,1,99),(8,6,1,1,3)");
        $insert = $this->database->prepare("INSERT INTO Event VALUES(?,'<b>Synthetic title</b>','2026-10-20 12:34:56','Text &amp; details',?,?,1,?)");
        foreach ([[1,'project',1,null],[2,'experiment',2,null],[3,'evaluation',3,null],[4,'job',1,null],[5,'node',8,'private-node'],[6,'user',1,null],[7,'user',2,null],[8,'system',1,null],[9,'job',999,null],[10,'job',8,null],[11,'evaluation',5,null]] as $event) $insert->execute($event);
        $this->counter->queries = [];
    }

    private function context(int $role = 0, int $id = 1): Context
    {
        return new Context(new Store($this->database), ['id' => $id, 'role' => $role], '/synthetic');
    }

    private function dashboard(array $query = [], int $role = 0, int $id = 1): array
    {
        return (new DashboardService($this->context($role, $id)))->dashboard($query);
    }

    public function testOwnAndSharedCountsKeepAllStatusRulesWithoutDuplicateMemberships(): void
    {
        $result = $this->dashboard();
        self::assertSame(['projects'=>3,'experiments'=>2,'evaluationsCompleted'=>3,'evaluationsRunning'=>2,'jobs'=>7,'activeJobs'=>3,'failedJobs'=>1], $result['counts']);
        self::assertSame([11,6,4,3,2,1], array_column($result['events'], 'id'));
        self::assertLessThanOrEqual(8, count($this->counter->queries));
        self::assertSame('Synthetic title', $result['events'][0]['title']);
        self::assertSame('Text & details', $result['events'][0]['text']);
        self::assertSame('2026-10-20T12:34:56+02:00', $result['events'][0]['at']);
        self::assertSame('Synthetic Owner', $result['events'][0]['actorName']);
        self::assertSame([
            ['type'=>'project','id'=>1,'label'=>'Own & project'],
            ['type'=>'experiment','id'=>1,'label'=>'Own experiment'],
            ['type'=>'evaluation','id'=>1,'label'=>'Completed evaluation'],
            ['type'=>'job','id'=>1,'label'=>'Job #1'],
        ], $result['events'][2]['resources']);
    }

    public function testAdministratorAllScopeAndNodeLinksKeepExistingAccessRules(): void
    {
        $own = $this->dashboard([], 1);
        self::assertSame(3, $own['counts']['projects']);
        self::assertSame([11,6,5,4,3,2,1], array_column($own['events'], 'id'));
        self::assertSame(['type'=>'node','id'=>'private-node','label'=>'Node private-node'], $own['events'][2]['resources'][4]);
        self::assertSame(['type'=>'project','id'=>3,'label'=>'Private project'], $own['events'][2]['resources'][0]);
        $all = $this->dashboard(['all'=>'true'], 1);
        self::assertSame(['projects'=>4,'experiments'=>3,'evaluationsCompleted'=>4,'evaluationsRunning'=>2,'jobs'=>8,'activeJobs'=>3,'failedJobs'=>1], $all['counts']);
        self::assertSame([11,10,7,6,5,4,3,2,1], array_column($all['events'], 'id'));
    }

    public function testNonAdministratorCannotRequestAllProjects(): void
    {
        $this->expectException(HttpError::class);
        try { $this->dashboard(['all'=>'true']); }
        catch (HttpError $error) { self::assertSame(403, $error->status); throw $error; }
    }

    public function testNoVisibleProjectsAndEmptyDatabaseHaveIntegerZeroCounts(): void
    {
        self::assertSame(['counts'=>['projects'=>0,'experiments'=>0,'evaluationsCompleted'=>0,'evaluationsRunning'=>0,'jobs'=>0,'activeJobs'=>0,'failedJobs'=>0],'events'=>[]], $this->dashboard([], 0, 999));
        foreach (['Event','Job','Evaluation','Experiment','Project','ProjectUser'] as $table) $this->database->exec('DELETE FROM ' . $table);
        self::assertSame(['counts'=>['projects'=>0,'experiments'=>0,'evaluationsCompleted'=>0,'evaluationsRunning'=>0,'jobs'=>0,'activeJobs'=>0,'failedJobs'=>0],'events'=>[]], $this->dashboard(['all'=>'true'], 1));
    }

    public function testTwentyDistinctJobsUseOneBoundedLinkQueryAndStableTimelineLimit(): void
    {
        $this->database->exec('DELETE FROM Event');
        $job = $this->database->prepare('INSERT INTO Job VALUES(?,1,1,?,3)');
        $event = $this->database->prepare("INSERT INTO Event VALUES(?,'Job changed','2026-10-20 12:34:56','Synthetic','job',?,1,NULL)");
        for ($id=100; $id<=125; ++$id) { $job->execute([$id,$id]); $event->execute([$id,$id]); }
        $this->counter->queries = [];
        $result = $this->dashboard();
        self::assertSame(range(125,106,-1), array_column($result['events'], 'id'));
        self::assertCount(5, $this->counter->queries);
        self::assertSame(['type'=>'job','id'=>125,'label'=>'Job #125'], $result['events'][0]['resources'][3]);
    }

    public function testBroadScopesKeepTheSameTimelineWithOneBatchedLinkQuery(): void
    {
        $this->database->exec('DELETE FROM Event');
        $job = $this->database->prepare('INSERT INTO Job VALUES(?,1,1,?,3)');
        for ($id=100; $id<=1200; ++$id) $job->execute([$id,$id]);
        $event = $this->database->prepare("INSERT INTO Event VALUES(?,'Job changed','2026-10-20 12:34:56','Synthetic','job',?,1,NULL)");
        for ($id=100; $id<=125; ++$id) $event->execute([$id,$id]);
        $this->counter->queries = [];
        $result = $this->dashboard();
        self::assertSame(range(125,106,-1), array_column($result['events'], 'id'));
        self::assertCount(4, $this->counter->queries);
        self::assertSame(['type'=>'job','id'=>125,'label'=>'Job #125'], $result['events'][0]['resources'][3]);
        // If a concurrent import invalidates the previously small counts, the
        // resource query's cap falls back without dropping any authorized events.
        $timeline = new DashboardTimeline($this->context());
        self::assertSame($result['events'], $timeline->latest(['project'=>3,'experiment'=>2,'evaluation'=>5,'job'=>1], false));
    }

    public function testSmallScopeFindsOldActivityBehindNewerForeignHistory(): void
    {
        $event = $this->database->prepare("INSERT INTO Event VALUES(?,'Foreign job','2026-10-21 12:34:56','Private','job',8,2,NULL)");
        for ($id=100; $id<4196; ++$id) $event->execute([$id]);
        $this->counter->queries = [];
        $result = $this->dashboard();
        self::assertSame([11,6,4,3,2,1], array_column($result['events'], 'id'));
        self::assertLessThanOrEqual(8, count($this->counter->queries));
    }

    public function testActivitySurvivesDeletedAndUnknownActors(): void
    {
        $this->database->exec('UPDATE Event SET userId=999 WHERE eventId=11; UPDATE Event SET userId=NULL WHERE eventId=6');
        $result = $this->dashboard();
        self::assertSame([11,6,4,3,2,1], array_column($result['events'], 'id'));
        self::assertSame('', $result['events'][0]['actorName']);
        self::assertSame('', $result['events'][1]['actorName']);
        self::assertSame('Synthetic Owner', $result['events'][2]['actorName']);
    }

    public function testTargetedTimelineRechecksRevokedMembershipAtEventSelection(): void
    {
        $this->changeBeforeEventSelection(fn() => $this->database->exec('DELETE FROM ProjectUser WHERE projectId=2'));
        $result = $this->dashboard();
        self::assertSame([11,6,4,1], array_column($result['events'], 'id'));
    }

    public function testTargetedTimelineRechecksChangedOwnershipAtEventSelection(): void
    {
        $this->changeBeforeEventSelection(fn() => $this->database->exec('UPDATE Project SET userId=2 WHERE projectId=1'));
        $result = $this->dashboard();
        self::assertSame([6,3,2], array_column($result['events'], 'id'));
    }

    public function testTargetedTimelineRechecksDeletedParentAtEventSelection(): void
    {
        $this->changeBeforeEventSelection(fn() => $this->database->exec('DELETE FROM Evaluation WHERE evaluationId=1'));
        $result = $this->dashboard();
        self::assertSame([11,6,3,2,1], array_column($result['events'], 'id'));
    }

    private function changeBeforeEventSelection(callable $change): void
    {
        $this->counter->beforeExecute = function (string $sql) use ($change): void {
            if (!str_starts_with($sql, 'SELECT v.*,a.firstname')) return;
            unset($this->counter->beforeExecute);
            $change();
        };
    }

    public function testBatchProjectionMatchesIndividualAclAndMissingResourceBehavior(): void
    {
        // Include unauthorized and orphaned links explicitly: bulk loading must not
        // rely on its caller having already performed the dashboard's filtering.
        $this->database->exec("INSERT INTO Project VALUES(9,999,1,'Missing system'); INSERT INTO Event VALUES(12,'Missing system','2026-10-20 12:34:56','Synthetic','project',9,1,NULL)");
        $events = (new Store($this->database))->rows('SELECT v.*,u.firstname actorFirstName,u.lastname actorLastName FROM Event v LEFT JOIN User u ON u.userId=v.userId ORDER BY eventId');
        foreach ([0,1] as $role) {
            $individual = new EventProjection($this->context($role));
            $expected = array_map($individual->event(...), $events);
            $this->counter->queries = [];
            self::assertSame($expected, (new EventProjection($this->context($role)))->events($events));
            self::assertCount(4, $this->counter->queries);
        }
    }
}
