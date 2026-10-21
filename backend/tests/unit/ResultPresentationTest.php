<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Results\ResultPresentation;
use Chronos\Application\Results\ResultJobs;
use Chronos\Application\Extensions\Extensions;
use Chronos\Application\Shared\Context;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Persistence\Store;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResultPresentationStatement extends PDOStatement
{
    protected function __construct(private readonly object $counter) {}
    public function execute(?array $params = null): bool
    {
        $this->counter->queries[] = $this->queryString;
        return parent::execute($params);
    }
}

final class ResultPresentationTest extends TestCase
{
    private PDO $database;
    private object $counter;
    private string $root;

    protected function setUp(): void
    {
        $this->database = class_exists(\Pdo\Sqlite::class)
            ? new \Pdo\Sqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION])
            : new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $concat = static fn(string $separator, mixed ...$values): string => implode($separator, array_filter($values, static fn(mixed $value): bool => $value !== null));
        if ($this->database instanceof \Pdo\Sqlite) $this->database->createFunction('CONCAT_WS', $concat);
        else $this->database->sqliteCreateFunction('CONCAT_WS', $concat);
        $this->counter = (object) ['queries'=>[]];
        $this->database->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ResultPresentationStatement::class, [$this->counter]]);
        $this->database->exec('CREATE TABLE User(userId INTEGER PRIMARY KEY,firstname TEXT,lastname TEXT); CREATE TABLE System(systemId INTEGER PRIMARY KEY,name TEXT); CREATE TABLE Project(projectId INTEGER PRIMARY KEY,systemId INTEGER,userId INTEGER,name TEXT); CREATE TABLE ProjectUser(projectUserId INTEGER PRIMARY KEY,projectId INTEGER,userId INTEGER); CREATE TABLE Experiment(experimentId INTEGER PRIMARY KEY,projectId INTEGER,systemId INTEGER,internalId INTEGER,name TEXT,resultId TEXT); CREATE TABLE Evaluation(evaluationId INTEGER PRIMARY KEY,experimentId INTEGER,systemId INTEGER,internalId INTEGER,name TEXT); CREATE TABLE Setting(systemId INTEGER,section TEXT,item TEXT,value TEXT); CREATE TABLE Job(jobId INTEGER PRIMARY KEY,userId INTEGER,description TEXT,systemId INTEGER,environment TEXT,phases INTEGER,currentPhase INTEGER,configuration TEXT,status INTEGER,progress INTEGER,result TEXT,created TEXT,started TEXT,finished TEXT,evaluationId INTEGER,internalId INTEGER,configurationIdentifier TEXT)');
        $this->database->exec("INSERT INTO User VALUES(1,'Result','Owner'); INSERT INTO System VALUES(1,'Synthetic system'); INSERT INTO Project VALUES(1,1,1,'Own project'),(2,1,2,'Private project'); INSERT INTO Experiment VALUES(1,1,1,1,'Own experiment','system-1'),(2,2,1,1,'Private experiment','system-1'); INSERT INTO Evaluation VALUES(1,1,1,1,'Own evaluation'),(2,2,1,1,'Private evaluation'); INSERT INTO Setting VALUES(0,'other','rowsPerPage','2')");
        $this->root = sys_get_temp_dir() . '/chronos-results-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/webroot/systems/1', 0700, true);
        $this->writeConfigurations();
        foreach ([[1,3,0,'Job 10'],[2,3,4,'Job 2'],[3,1,99,'Queued'],[4,-2,10,'Failed']] as [$id,$status,$latency,$name]) $this->job($id, $status, $latency, $name);
        $this->counter->queries = [];
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $path) {
            if ($path->isDir()) rmdir($path->getPathname()); else unlink($path->getPathname());
        }
        rmdir($this->root);
    }

    private function writeConfigurations(): void
    {
        file_put_contents($this->root . '/webroot/systems/1/resultConfigurations.json', json_encode(['version'=>'1.0','elements'=>['system-1'=>['name'=>'Default','all'=>[['id'=>'latency','type'=>'bar-plot','name'=>'Latency','parameter'=>'latency']], 'job'=>[['id'=>'job-latency','type'=>'line-plot','name'=>'Job latency','parameter'=>'latency']]]]], JSON_THROW_ON_ERROR));
    }

    private function job(int $id, int $status, int $latency, string $name = 'Job', int $evaluationId = 1): void
    {
        $this->database->prepare('INSERT INTO Job VALUES(?,1,?,1,\'local\',6,6,?,?,100,?,\'2026-10-20 10:00:00\',NULL,?, ?,?,\'fixture\')')->execute([$id,$name,json_encode(['title'=>'Configuration','parameters'=>['workers'=>1,'run'=>$id]], JSON_THROW_ON_ERROR),$status,json_encode(['latency'=>$latency,'nested'=>['value'=>0]], JSON_THROW_ON_ERROR),$status===3?'2026-10-20 12:34:56':null,$evaluationId,$id]);
    }

    private function service(int $actorId = 1): ResultPresentation
    {
        return new ResultPresentation(new Context(new Store($this->database), ['id'=>$actorId,'role'=>0], $this->root), $this->root);
    }

    public function testExplicitViewsMatchTheCombinedChartsAndRawPayload(): void
    {
        $service = $this->service();
        $combined = $service->evaluation(1, []);
        $charts = $service->evaluation(1, ['view'=>'charts']);
        $raw = $service->evaluation(1, ['view'=>'raw']);
        self::assertSame($combined['charts'], $charts['charts']);
        self::assertEquals($combined['jobs'], $raw['jobs']);
        self::assertSame($combined['jobPage'], $raw['jobPage']);
        self::assertSame([], $charts['jobs']); self::assertArrayNotHasKey('jobPage', $charts);
        self::assertSame([], $raw['charts']); self::assertSame([], $raw['configurations']);
        foreach ([$combined,$charts,$raw] as $view) {
            self::assertSame(4, $view['totalJobs']); self::assertSame(2, $view['finishedJobs']); self::assertSame(1, $view['activeJobs']);
        }
        self::assertSame([2.0], $charts['charts'][0]['data']['datasets'][0]['data']);
    }

    public function testChartSnapshotsSkipRawPagingAndUnfinishedJobBlobs(): void
    {
        foreach (range(10,1010) as $id) $this->job($id, 1, 1);
        $this->counter->queries = [];
        $result = $this->service()->evaluation(1, ['view'=>'charts']);
        self::assertSame(1005, $result['totalJobs']); self::assertSame(2, $result['finishedJobs']);
        self::assertCount(2, $result['charts']); self::assertSame([], $result['jobs']);
        self::assertLessThanOrEqual(5, count($this->counter->queries));
        self::assertCount(1, array_filter($this->counter->queries, static fn(string $sql): bool => str_contains($sql, 'FROM `Job`') && str_contains($sql, 'AND status=3')));
        self::assertStringNotContainsString('rowsPerPage', implode("\n", $this->counter->queries));
        self::assertLessThan(3000, strlen(json_encode($result, JSON_THROW_ON_ERROR)), 'Queued raw JSON must not inflate the initial chart snapshot.');
    }

    public function testDefaultRawPagesBoundJsonReadsAndNeverRequireChartDefinitions(): void
    {
        foreach (range(10,1010) as $id) $this->job($id, 3, $id);
        file_put_contents($this->root . '/webroot/systems/1/resultConfigurations.json', 'invalid configuration');
        $this->counter->queries = [];
        $result = $this->service()->evaluation(1, ['view'=>'raw','page'=>'3','pageSize'=>'10','sort'=>'-id']);
        self::assertSame(range(990,981), array_column($result['jobs'], 'id'));
        self::assertSame(['total'=>1005,'page'=>3,'pageSize'=>10,'sort'=>'-id'], $result['jobPage']);
        self::assertSame([], $result['charts']); self::assertSame([], $result['configurations']);
        self::assertLessThanOrEqual(5, count($this->counter->queries));
        $reads = array_values(array_filter($this->counter->queries, static fn(string $sql): bool => str_contains($sql, 'configuration,result')));
        self::assertCount(1, $reads); self::assertStringContainsString('LIMIT 10 OFFSET 20', $reads[0]);
        self::assertStringNotContainsString('SELECT *', $reads[0]);
    }

    public static function rawOrders(): iterable
    {
        foreach (['id','-id','internalId','-internalId','status','-status','name','-name','parameters','-parameters','result','-result','finishedAt','-finishedAt'] as $sort) yield $sort=>[$sort];
    }

    #[DataProvider('rawOrders')]
    public function testRawOrdersAndPagingKeepCombinedSemantics(string $sort): void
    {
        $query = ['sort'=>$sort,'pageSize'=>'2','page'=>'2'];
        $combined = $this->service()->evaluation(1, $query);
        $raw = $this->service()->evaluation(1, ['view'=>'raw',...$query]);
        self::assertEquals($combined['jobs'], $raw['jobs']); self::assertSame($combined['jobPage'], $raw['jobPage']);
    }

    public function testRawSearchPreservesParametersMetricsGermanDatesAndNaturalNames(): void
    {
        foreach (['nested','workers','20.10.2026','Job 2','"value":0',"%_' OR 1=1 --"] as $search) {
            $query = ['search'=>$search,'sort'=>'name'];
            $combined = $this->service()->evaluation(1, $query);
            $raw = $this->service()->evaluation(1, ['view'=>'raw',...$query]);
            self::assertEquals($combined['jobs'], $raw['jobs']); self::assertSame($combined['jobPage'], $raw['jobPage']);
        }
        self::assertSame([2,1], array_column($this->service()->evaluation(1, ['view'=>'raw','search'=>'Job','sort'=>'name'])['jobs'], 'id'));
        self::assertSame([], $this->service()->evaluation(1, ['view'=>'raw','search'=>'missing','page'=>'999'])['jobs']);
    }

    public function testJobViewsKeepTheSameChartsAndSingleRawRecord(): void
    {
        $combined = $this->service()->job(1, []);
        $charts = $this->service()->job(1, ['view'=>'charts']);
        $raw = $this->service()->job(1, ['view'=>'raw']);
        self::assertSame($combined['charts'], $charts['charts']); self::assertCount(1, $charts['charts']);
        self::assertEquals($combined['jobs'], $raw['jobs']); self::assertSame(1, $raw['jobPage']['total']);
        self::assertSame([], $charts['jobs']); self::assertSame([], $raw['charts']);
    }

    public function testBuiltinDecodingIsSharedAcrossChartsWithoutChangingTrustedProcessorInputs(): void
    {
        $row = $this->database->query('SELECT * FROM Job WHERE jobId=1')->fetch(PDO::FETCH_ASSOC);
        $original = ResultJobs::fromRow($row, json_decode($row['configuration'], true, 64, JSON_THROW_ON_ERROR));
        $cache = [];
        $first = ResultJobs::forBuiltinPlots([[$original]], $cache);
        $next = ResultJobs::forBuiltinPlots([[$original]], $cache);
        self::assertCount(1, $cache); self::assertSame($first[0][0], $next[0][0]);
        self::assertIsArray($first[0][0]->getResult()); self::assertSame(0, $first[0][0]->getResult()['latency']);
        self::assertSame($row['result'], $original->getResult()); self::assertNotSame($original, $first[0][0]);
    }

    public function testBuiltinCachesAreInvalidatedAfterTrustedProcessorModelChanges(): void
    {
        $directory = $this->root . '/webroot/systems/1';
        mkdir($directory . '/results/custom-mutation', 0700, true);
        file_put_contents($directory . '/extensions.v3.json', json_encode(['format'=>1,'plots'=>[['type'=>'custom-mutation','name'=>'Synthetic mutation','baseType'=>'bar-plot','processor'=>'results/custom-mutation/process.php']]], JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/results/custom-mutation/process.php', '<?php $jobs[0][0]->setResult(\'{"latency":20}\'); $plotData = \'{"labels":["Synthetic"],"datasets":[{"data":[20]}]}\';');
        $row = $this->database->query('SELECT * FROM Job WHERE jobId=2')->fetch(PDO::FETCH_ASSOC);
        $job = ResultJobs::fromRow($row, json_decode($row['configuration'], true, 64, JSON_THROW_ON_ERROR));
        $groups = [[$job]]; $extensions = new Extensions($this->root, 1, [1]); $decoded = [];
        $render = new \ReflectionMethod(ResultPresentation::class, 'chart');
        $definition = ['id'=>'test','name'=>'Test','type'=>'bar-plot','parameter'=>'latency'];
        $service = $this->service();
        $first = $render->invokeArgs($service, [1,$definition,$groups,'all',&$extensions,&$decoded]);
        self::assertSame([4.0], $first['data']['datasets'][0]['data']); self::assertCount(1, $decoded);
        $definition['type'] = 'custom-mutation';
        $custom = $render->invokeArgs($service, [1,$definition,$groups,'all',&$extensions,&$decoded]);
        self::assertArrayNotHasKey('error', $custom); self::assertSame([], $decoded); self::assertSame('{"latency":20}', $job->getResult());
        $definition['type'] = 'bar-plot';
        $next = $render->invokeArgs($service, [1,$definition,$groups,'all',&$extensions,&$decoded]);
        self::assertSame([20.0], $next['data']['datasets'][0]['data']);
    }

    public function testBuiltinCacheRetainsMetricsAlongsideDeeplyNestedUnrelatedValues(): void
    {
        $nested = 'leaf'; foreach (range(1,100) as $_) $nested = ['child'=>$nested];
        $result = json_encode(['latency'=>6,'unrelated'=>$nested], JSON_THROW_ON_ERROR);
        $this->database->prepare('UPDATE Job SET result=? WHERE jobId IN (1,2)')->execute([$result]);
        $charts = $this->service()->evaluation(1, ['view'=>'charts']);
        self::assertSame([6.0], $charts['charts'][0]['data']['datasets'][0]['data']);
        self::assertArrayNotHasKey('error', $charts['charts'][0]);
    }

    public function testRawViewsEnforceProjectAccessBeforeAnyPayloadQuery(): void
    {
        $this->job(5,3,42,'Private',2); $this->counter->queries = [];
        try { $this->service()->evaluation(2, ['view'=>'raw']); self::fail('Private evaluation must be denied.'); }
        catch (HttpError $error) { self::assertSame(403, $error->status); }
        self::assertCount(3, $this->counter->queries);
        self::assertStringNotContainsString('FROM `Job`', implode("\n", $this->counter->queries));
        $this->database->exec('INSERT INTO ProjectUser VALUES(1,2,1)');
        self::assertSame([5], array_column($this->service()->evaluation(2, ['view'=>'raw'])['jobs'],'id'));
    }

    public static function linkedAncestors(): iterable
    {
        yield 'webroot' => ['webroot']; yield 'systems' => ['webroot/systems'];
    }

    #[DataProvider('linkedAncestors')]
    public function testChartViewsRejectLinkedAncestorsWhileRawDatabaseBrowsingStillWorks(string $ancestor): void
    {
        $service = $this->service(); $raw = $service->evaluation(1, ['view'=>'raw']);
        $source = $this->root . '/' . $ancestor; $moved = $this->root . '/moved-ancestor';
        rename($source, $moved);
        try {
            symlink($moved, $source);
            foreach (['charts','combined'] as $view) {
                try { $service->evaluation(1, ['view'=>$view]); self::fail('Linked chart ancestors must be rejected.'); }
                catch (HttpError $error) { self::assertSame(409, $error->status); self::assertSame('system_files_unavailable', $error->errorCode); }
            }
            self::assertEquals($raw, $service->evaluation(1, ['view'=>'raw']));
        } finally {
            if (is_link($source)) unlink($source);
            rename($moved, $source);
        }
    }

    #[DataProvider('badViews')]
    public function testProjectionModesRejectInvalidInput(mixed $view): void
    {
        try { $this->service()->evaluation(1, ['view'=>$view]); self::fail('Invalid view must be rejected.'); }
        catch (HttpError $error) { self::assertSame(422, $error->status); }
    }

    public static function badViews(): iterable
    {
        yield ['charts,raw']; yield [['raw']]; yield [1]; yield ['raw; DROP TABLE Job'];
    }
}
