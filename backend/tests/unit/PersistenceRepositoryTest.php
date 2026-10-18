<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Infrastructure\Persistence\Connection;
use Chronos\Infrastructure\Persistence\Model\Job;
use Chronos\Infrastructure\Persistence\Model\Setting;
use Chronos\Infrastructure\Persistence\Query\ContainFilter;
use Chronos\Infrastructure\Persistence\Query\OrderFilter;
use Chronos\Infrastructure\Persistence\Query\QueryFilter;
use Chronos\Infrastructure\Persistence\Query\UpdateSet;
use Chronos\Infrastructure\Persistence\Repository\JobRepository;
use Chronos\Infrastructure\Persistence\Repository\SettingRepository;
use Chronos\Infrastructure\Persistence\RepositoryRegistry;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PersistenceRepositoryTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConfigurationAndRepositoryLoadingDoNotOpenTheDatabase(): void
    {
        Connection::configure('deliberately-unavailable-driver:database');
        $repository = RepositoryRegistry::getUserRepository();
        self::assertSame($repository, RepositoryRegistry::getUserRepository());
        self::assertSame('User', $repository->getModelTable());
        $this->expectException(PDOException::class);
        $repository->getDB();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRepositoriesShareTheExplicitLazyConnection(): void
    {
        Connection::configure('sqlite::memory:');
        $first = Connection::get();
        self::assertSame($first, RepositoryRegistry::getUserRepository()->getDB());
        self::assertSame($first, RepositoryRegistry::getSessionRepository()->getDB());
        self::assertSame(PDO::ERRMODE_EXCEPTION, $first->getAttribute(PDO::ATTR_ERRMODE));
        Connection::configure('sqlite::memory:');
        self::assertNotSame($first, Connection::get());
        self::assertSame(Connection::get(), RepositoryRegistry::getUserRepository()->getDB());
    }

    public static function modelRepositories(): iterable
    {
        foreach (['System', 'Experiment', 'Evaluation', 'Job', 'Event', 'User', 'Setting', 'Session', 'Node'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('modelRepositories')]
    public function testEveryRetainedModelHydratesAllStoredColumns(string $name): void
    {
        $class = 'Chronos\\Infrastructure\\Persistence\\Repository\\' . $name . 'Repository';
        $reflection = new ReflectionClass($class);
        $columns = $reflection->getConstant('COLUMNS');
        $primary = $reflection->getConstant('PRIMARY_KEY');
        $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE `' . $name . '` (' . implode(',', array_map(
            static fn(string $column): string => '`' . $column . '` TEXT' . ($column === $primary ? ' PRIMARY KEY' : ''), $columns)) . ')');
        $row = [];
        foreach ($columns as $index => $column) $row[$column] = $index === 0 ? '37' : 'Stored ' . $column . " & Zürich 🧪'";
        $statement = $db->prepare('INSERT INTO `' . $name . '` VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $statement->execute(array_values($row));
        $repository = new $class($db);
        $model = $repository->get('37');
        self::assertInstanceOf('Chronos\\Infrastructure\\Persistence\\Model\\' . $name, $model);
        self::assertSame($row, $model->getKeyValueDict());
        self::assertSame('37', $model->getId());
        self::assertSame($primary, $model->getPrimaryKey());
        self::assertNull($repository->get('missing'));
        self::assertSame(['37'], $repository->idFilter([]));
        self::assertSame($row, $repository->filter([], true)->getKeyValueDict());
    }

    public function testInsertUpdateAndDeleteKeepZeroEmptyAndQuotedSettings(): void
    {
        $db = $this->settingsDatabase();
        $repository = new SettingRepository($db);
        $setting = new Setting(null, 'setup', 'zero', '0', 0);
        self::assertSame($setting, $repository->save($setting));
        self::assertSame(1, (int) $setting->getId());
        self::assertSame('0', $repository->get($setting->getId())->getValue());
        $setting->setValue("O'Reilly & Zürich");
        self::assertInstanceOf(PDOStatement::class, $repository->update($setting));
        self::assertSame("O'Reilly & Zürich", $repository->get($setting->getId())->getValue());
        $setting->setValue('');
        $repository->update($setting);
        self::assertSame('', $repository->get($setting->getId())->getValue());
        self::assertTrue($repository->delete($setting));
        self::assertNull($repository->get($setting->getId()));
        self::assertFalse($repository->delete(null));
    }

    public function testFilterOperatorsNullsMembershipAndOrderingPreserveTheirContracts(): void
    {
        $db = $this->settingsDatabase();
        $repository = new SettingRepository($db);
        foreach ([[null, '0'], [null, '1'], ['other', '2']] as [$section, $value]) {
            $repository->save(new Setting(null, $section, 'sample', $value, 1));
        }
        self::assertCount(2, $repository->filter(['filter' => new QueryFilter('section', null, '=')]));
        self::assertCount(1, $repository->filter(['filter' => new QueryFilter('section', null, '<>')]));
        self::assertCount(2, $repository->filter(['filter' => new QueryFilter('value', '0', '>')]));
        self::assertSame([], $repository->filter(['filter' => new ContainFilter('value', [])]));
        self::assertCount(3, $repository->filter(['filter' => new ContainFilter('value', [], null, true)]));
        $values = $repository->filter(['filter' => new ContainFilter('value', ['0', '2']), 'order' => new OrderFilter('value', 'DESC')]);
        self::assertSame(['2', '0'], array_map(static fn(Setting $value): string => $value->getValue(), $values));
        self::assertSame('2', $repository->filter(['order' => new OrderFilter('value', 'DESC LIMIT 1')], true)->getValue());
    }

    public function testBoundMassAssignmentsAndDeletionAffectOnlyTheMatchingRows(): void
    {
        $db = $this->settingsDatabase();
        $repository = new SettingRepository($db);
        $repository->save(new Setting(null, 'session', 'selected', 'before', 1));
        $repository->save(new Setting(null, 'session', 'other', 'untouched', 2));
        self::assertTrue($repository->massUpdate(['filter' => new QueryFilter('systemId', 1, '='),
            'update' => [new \Chronos\Infrastructure\Persistence\Query\UpdateSet('value', "renewed ' safely"), new \Chronos\Infrastructure\Persistence\Query\UpdateSet('section', 'after')]]));
        self::assertSame("renewed ' safely", $repository->filter(['filter' => new QueryFilter('item', 'selected', '=')], true)->getValue());
        self::assertSame('untouched', $repository->filter(['filter' => new QueryFilter('item', 'other', '=')], true)->getValue());
        self::assertInstanceOf(PDOStatement::class, $repository->massDeletion(['filter' => new QueryFilter('section', 'after', '=')]));
        self::assertSame(['other'], array_map(static fn(Setting $setting): string => $setting->getItem(), $repository->filter([])));
    }

    public function testJobObjectsRetainTheTrustedProcessorConfigurationContract(): void
    {
        $job = new Job(7, 1, 'Job', 1, 'local', 0, 5, '{"parameters":{"size":2}}', 3, 100,
            '{"latency":4}', '2026-01-15 12:00:00', null, null, 1, 1, 'configuration');
        self::assertSame(7, $job->getId());
        self::assertSame('{"latency":4}', $job->getResult());
        $job->setConfiguration(['parameters' => ['size' => 4]]);
        self::assertSame(['parameters' => ['size' => 4]], $job->getConfiguration());
        self::assertSame($job->getConfiguration(), $job->getKeyValueDict()['configuration']);
    }

    public function testReservationLocksOneEligibleJobUntilItsTimeoutAndThenAdvances(): void
    {
        $db = $this->jobsDatabase();
        $repository = new JobRepository($db);
        $before = (int) round(microtime(true) * 1000);
        $options = ['filter' => [new QueryFilter('status', '0', '='), new QueryFilter('environment', 'fixture', '=')]];
        self::assertSame('1', $repository->filterWithTimeout($options, 60, true)->getId());
        $lockedUntil = (int) $db->query('SELECT lockColumn FROM Job WHERE jobId=1')->fetchColumn();
        self::assertGreaterThanOrEqual($before + 60000, $lockedUntil);
        self::assertLessThanOrEqual((int) round(microtime(true) * 1000) + 60000, $lockedUntil);
        self::assertSame('2', $repository->filterWithTimeout($options, 60, true)->getId());
        self::assertNull($repository->filterWithTimeout($options, 60, true));
        self::assertFalse($db->inTransaction());
        self::assertTrue((bool) array_filter($db->preparedQueries, static fn(string $query): bool => str_ends_with($query, ' LIMIT 1 FOR UPDATE')));
    }

    public function testFailedReservationRollsBackAndLeavesTheJobAvailable(): void
    {
        $db = $this->jobsDatabase();
        $repository = new JobRepository($db);
        $db->failLockUpdate = true;
        try {
            $repository->filterWithTimeout(['filter' => new QueryFilter('status', '0', '=')], 60, true);
            self::fail('The injected write error must propagate.');
        } catch (PDOException $error) {
            self::assertSame('Injected reservation write failure', $error->getMessage());
        }
        self::assertFalse($db->inTransaction());
        self::assertSame(0, (int) $db->query('SELECT lockColumn FROM Job WHERE jobId=1')->fetchColumn());
        $db->failLockUpdate = false;
        self::assertSame('1', $repository->filterWithTimeout(['filter' => new QueryFilter('status', '0', '=')], 60, true)->getId());
    }

    private function settingsDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE Setting (settingId INTEGER PRIMARY KEY AUTOINCREMENT, section TEXT, item TEXT, value TEXT, systemId INTEGER)');
        return $db;
    }

    private function jobsDatabase(): ReservationSqliteConnection
    {
        $db = new ReservationSqliteConnection();
        $columns = (new ReflectionClass(JobRepository::class))->getConstant('COLUMNS');
        $db->exec('CREATE TABLE Job (' . implode(',', array_map(static fn(string $column): string => '`' . $column . '` TEXT', $columns)) . ',lockColumn INTEGER DEFAULT 0)');
        $insert = $db->prepare('INSERT INTO Job (jobId,status,environment) VALUES (?,?,?)');
        foreach ([['1', '0', 'fixture'], ['2', '0', 'fixture'], ['3', '3', 'fixture'], ['4', '0', 'other']] as $row) $insert->execute($row);
        return $db;
    }
}

/** Execute transaction/timeout logic locally; real MySQL locking is checked by the agent HTTP fixture. */
final class ReservationSqliteConnection extends PDO
{
    public array $preparedQueries = [];
    public bool $failLockUpdate = false;

    public function __construct() { parent::__construct('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedQueries[] = $query;
        if ($this->failLockUpdate && str_starts_with($query, 'UPDATE `Job` SET lockColumn')) throw new PDOException('Injected reservation write failure');
        // SQLite has no FOR UPDATE. Keep the emitted MySQL statement observable.
        return parent::prepare(preg_replace('/ FOR UPDATE$/', '', $query), $options);
    }
}
