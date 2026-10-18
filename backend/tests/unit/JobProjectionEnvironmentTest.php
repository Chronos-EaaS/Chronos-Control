<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Shared\Context;
use Chronos\Application\Workflows\WorkflowProjection;
use Chronos\Infrastructure\Persistence\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobProjectionEnvironmentTest extends TestCase
{
    public static function storedEnvironments(): iterable
    {
        yield 'local job' => ['system-local', false];
        yield 'local name containing cem' => ['system-cem-cluster', false];
        yield 'unprefixed historical local job' => ['Historical local environment', false];
        yield 'CEM job' => ['cem-cluster', true];
    }

    #[DataProvider('storedEnvironments')]
    public function testStoredJobEnvironmentControlsSetupIndependentlyOfCurrentSystemSettings(string $environment, bool $cem): void
    {
        if (!extension_loaded('pdo_sqlite')) self::markTestSkipped('The isolated job projection regression requires pdo_sqlite.');
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE System(systemId INTEGER PRIMARY KEY,cem INTEGER)');
        $pdo->exec('INSERT INTO System VALUES(1,0)');
        $pdo->exec('CREATE TABLE User(userId INTEGER PRIMARY KEY,username TEXT,firstname TEXT,lastname TEXT,email TEXT,role INTEGER,gender INTEGER,enabled INTEGER,activated INTEGER)');
        $pdo->exec("INSERT INTO User VALUES(1,'owner','Synthetic','Owner','fixture@example.test',0,0,1,1)");
        $pdo->exec('CREATE TABLE Job(jobId INTEGER PRIMARY KEY,evaluationId INTEGER,internalId INTEGER)');
        $pdo->exec('INSERT INTO Job VALUES(1,1,1),(2,1,2),(3,1,3)');
        $pdo->exec('CREATE TABLE Node(nodeId TEXT,hostname TEXT,lastUpdate TEXT,currentJob INTEGER)');
        $pdo->exec('CREATE TABLE Setting(settingId INTEGER PRIMARY KEY,systemId INTEGER,section TEXT,item TEXT,value TEXT)');
        $store = new Store($pdo);
        $store->insert('Node', ['nodeId' => 'fixture-node', 'hostname' => 'synthetic-client', 'lastUpdate' => date('Y-m-d H:i:s'), 'currentJob' => 2]);
        $row = ['jobId' => 2, 'userId' => 1, 'systemId' => 1, 'evaluationId' => 1, 'internalId' => 2,
            'experimentId' => 1, 'projectId' => 1, 'description' => 'Synthetic job', 'environment' => $environment,
            'status' => 2, 'progress' => 45, 'phases' => 2, 'currentPhase' => 3,
            'configuration' => '{"parameters":{"workers":0,"enabled":false}}',
            'created' => '2026-01-15 12:00:00', 'started' => '2026-01-15 12:01:00', 'finished' => null];
        $before = $row;
        $projection = new WorkflowProjection(new Context($store, ['id' => 1, 'role' => 0], ''));
        $first = null;
        foreach ([0, 1, 0] as $currentSystemCem) {
            $pdo->exec('UPDATE System SET cem=' . $currentSystemCem);
            $result = $projection->job($row);
            self::assertSame($cem, $result['cem']);
            self::assertSame($cem, $result['setupRequired']);
            self::assertSame($environment, $result['environment']);
            self::assertSame('synthetic-client', $result['client']);
            self::assertSame('connected', $result['clientStatus']);
            self::assertNotNull($result['lastContactAt']);
            self::assertSame(1, $result['previousId']);
            self::assertSame(3, $result['nextId']);
            self::assertSame(['workers' => 0, 'enabled' => false], $result['configuration']['parameters']);
            self::assertSame(1, $result['owner']['id']);
            self::assertSame(2, $result['phases']);
            self::assertSame(3, $result['currentPhase']);
            if ($first !== null) self::assertSame($first, $result, 'Changing the current system must not rewrite a job response.');
            $first = $result;
        }
        self::assertSame($before, $row, 'Projection must not mutate the stored job contract.');
    }
}
