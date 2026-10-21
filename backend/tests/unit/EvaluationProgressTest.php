<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Shared\Context;
use Chronos\Application\Workflows\WorkflowProjection;
use Chronos\Infrastructure\Persistence\Store;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvaluationProgressStatement extends PDOStatement
{
    protected function __construct(private readonly object $counter) {}
    public function execute(?array $params = null): bool
    {
        $this->counter->queries[] = $this->queryString;
        return parent::execute($params);
    }
}

final class EvaluationProgressTest extends TestCase
{
    private PDO $database;
    private object $counter;
    private WorkflowProjection $projection;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('CREATE TABLE Job(jobId INTEGER PRIMARY KEY,evaluationId INTEGER,status INTEGER,progress INTEGER,phases INTEGER,currentPhase INTEGER,environment TEXT)');
        $this->counter = (object) ['queries' => []];
        $this->database->setAttribute(PDO::ATTR_STATEMENT_CLASS, [EvaluationProgressStatement::class, [$this->counter]]);
        $this->projection = new WorkflowProjection(new Context(new Store($this->database), ['id'=>1,'role'=>0], '/synthetic'));
    }

    public static function achievedWork(): iterable
    {
        yield 'finished historical zero' => [3,0,0,null,'system-local',100];
        yield 'scheduled ignores stale phase' => [0,100,0,5,'cem-cluster',0];
        yield 'execute just started retains earlier work' => [2,0,0,3,'system-local',40];
        yield 'CEM setup is another completed stage' => [2,0,0,3,'cem-cluster',50];
        yield 'only exact stored CEM prefix enables setup' => [2,0,0,3,'CEM-cluster',40];
        yield 'warmup exclusion shrinks stage count and rounds like the job row' => [2,50,2,3,'system-local',38];
        yield 'CEM setup progress' => [1,60,0,null,'cem-cluster',10];
        yield 'failed CEM setup keeps achieved work' => [-2,30,0,null,'cem-cluster',5];
        yield 'aborted job does not become fully complete' => [-1,40,0,3,'system-local',48];
        yield 'failed execution keeps earlier phases' => [-2,50,0,4,'system-local',70];
        yield 'excluded current stage still counts earlier enabled stages' => [2,0,11,4,'system-local',50];
        yield 'no enabled stage does not divide by zero' => [2,100,31,5,'system-local',0];
        yield 'CEM with all agent stages excluded' => [2,0,31,3,'cem-cluster',100];
        yield 'negative legacy percentages are bounded' => [2,-25,0,1,'system-local',0];
        yield 'oversize legacy percentages are bounded' => [2,150,0,5,'system-local',100];
        yield 'unknown legacy phase does not invent work' => [2,90,0,99,'system-local',0];
    }

    #[DataProvider('achievedWork')]
    public function testSummaryMeasuresAchievedWorkAndPreservesStoredAgentValues(int $status, int $progress, int $phases, ?int $phase, string $environment, int $expected): void
    {
        $values = [1,1,$status,$progress,$phases,$phase,$environment];
        $this->database->prepare('INSERT INTO Job VALUES(?,?,?,?,?,?,?)')->execute($values);
        $summary = $this->projection->counts(1);
        self::assertSame($expected, $summary['progress']);
        self::assertSame(1, $summary['total']);
        self::assertSame($status === -1 ? 1 : 0, $summary['aborted']);
        self::assertSame(array_combine(['jobId','evaluationId','status','progress','phases','currentPhase','environment'], $values), $this->database->query('SELECT * FROM Job')->fetch(PDO::FETCH_ASSOC));
    }

    public function testAverageWeightsEachJobEquallyAndCountsTerminalStatesSeparately(): void
    {
        $this->database->exec("INSERT INTO Job VALUES(1,1,2,0,0,3,'system-local'),(2,1,2,0,0,3,'cem-local'),(3,1,-1,0,0,NULL,'system-local'),(4,1,3,0,0,NULL,'system-local'),(5,1,-2,50,0,4,'system-local'),(6,1,1,60,0,NULL,'cem-local')");
        self::assertSame(['total'=>6,'finished'=>1,'failed'=>1,'active'=>3,'aborted'=>1,'progress'=>45], $this->projection->counts(1));
        self::assertSame(['total'=>0,'finished'=>0,'failed'=>0,'active'=>0,'aborted'=>0,'progress'=>0], $this->projection->counts(999));
    }

    public function testSingleAndBatchSummariesRoundFractionalProgressConsistentlyWithJobRows(): void
    {
        $this->database->exec("INSERT INTO Job VALUES(1,1,2,50,2,3,'system-local'),(2,2,2,2,2,1,'system-local'),(3,3,2,1,2,1,'system-local')");
        $expected = [38,1,0];
        foreach ($expected as $index => $progress) self::assertSame($progress, $this->projection->counts($index + 1)['progress']);
        $batch = $this->projection->evaluations(array_map(fn(int $id): array => $this->evaluation($id), [1,2,3]));
        self::assertSame($expected, array_column($batch, 'progress'));
    }

    public function testEvaluationPageUsesTwoAggregatesIndependentOfItsLength(): void
    {
        $rows = [];
        $insert = $this->database->prepare("INSERT INTO Job VALUES(?,?,3,0,0,NULL,'system-local')");
        for ($id = 100; $id >= 1; --$id) {
            $rows[] = $this->evaluation($id);
            $insert->execute([$id,$id]);
        }
        $rows[] = $this->evaluation(999);
        $this->counter->queries = [];
        $result = $this->projection->evaluations($rows);
        self::assertCount(2, $this->counter->queries);
        self::assertSame([...range(100,1),999], array_column($result, 'id'));
        self::assertSame(100, $result[0]['progress']);
        self::assertTrue($result[0]['permissions']['canDownload']);
        self::assertSame(['system-local'], $result[0]['environments']);
        self::assertSame(0, $result[100]['progress']);
        self::assertSame(['total'=>0,'finished'=>0,'failed'=>0,'active'=>0,'aborted'=>0], $result[100]['counts']);
        self::assertSame([], $result[100]['environments']);
        self::assertFalse($result[100]['permissions']['canDownload']);
        $this->counter->queries = [];
        self::assertSame([], $this->projection->evaluations([]));
        self::assertSame([], $this->counter->queries);
    }

    public function testBatchAndSingleEvaluationDtosMatchIncludingEnvironmentsAndPermissions(): void
    {
        $this->database->exec("INSERT INTO Job VALUES(1,1,3,0,0,NULL,'system-local'),(2,1,-1,40,0,3,'cem-cluster'),(3,1,-1,40,0,3,'cem-cluster'),(4,2,-2,50,0,4,'system-local')");
        $batch = $this->projection->evaluations([$this->evaluation(2),$this->evaluation(1)]);
        self::assertSame($batch[0], $this->projection->evaluation($this->evaluation(2)));
        self::assertSame($batch[1], $this->projection->evaluation($this->evaluation(1)));
        self::assertSame(['cem-cluster','system-local'], $batch[1]['environments']);
        self::assertSame('cem-cluster, system-local', $batch[1]['environment']);
        self::assertTrue($batch[1]['permissions']['canArchive']);
        self::assertFalse($batch[1]['permissions']['canAbort']);
        self::assertTrue($batch[1]['permissions']['canReschedule']);
        self::assertFalse($batch[1]['permissions']['canDownload']);
    }

    private function evaluation(int $id): array
    {
        return ['evaluationId'=>$id,'name'=>'Synthetic evaluation','description'=>'Stored &amp; description','experimentId'=>1,'projectId'=>1,'systemId'=>1,'internalId'=>$id,'isArchived'=>0,'isStarred'=>0,'resultId'=>'default'];
    }
}
