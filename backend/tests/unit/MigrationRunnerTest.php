<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Infrastructure\Persistence\Migrations\MigrationCatalog;
use Chronos\Infrastructure\Persistence\Migrations\MigrationException;
use Chronos\Infrastructure\Persistence\Migrations\MigrationRunner;
use Chronos\Infrastructure\Persistence\Migrations\UserEnabledMigration;
use Chronos\Infrastructure\Persistence\Migrations\LiveUpdatesMigration;
use Chronos\Infrastructure\Persistence\Migrations\EventTimelineIndexMigration;
use Chronos\Infrastructure\Persistence\Migrations\ScopedEventTimelineIndexesMigration;
use Chronos\Infrastructure\Persistence\Migrations\ResourceEventTimelineIndexMigration;
use Chronos\Infrastructure\Persistence\Migrations\SystemMaintainersMigration;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    public function testReleaseSchemaVersionIsAvailableWithoutDatabaseConfiguration(): void
    {
        self::assertSame(SystemMaintainersMigration::VERSION, MigrationCatalog::latestVersion());
        self::assertSame('0', MigrationCatalog::BASELINE);
        self::assertSame([UserEnabledMigration::VERSION, LiveUpdatesMigration::VERSION, EventTimelineIndexMigration::VERSION, ScopedEventTimelineIndexesMigration::VERSION, ResourceEventTimelineIndexMigration::VERSION, SystemMaintainersMigration::VERSION], array_map(static fn($migration): string => $migration->version(), MigrationCatalog::migrations()));
    }

    public function testOtherDatabaseDriversCannotAccidentallyRunMysqlMigrations(): void
    {
        $database = new PDO('sqlite::memory:');
        $database->exec('CREATE TABLE User(alive INTEGER)');
        try {
            new MigrationRunner($database);
            self::fail('MySQL-only migrations must reject SQLite.');
        } catch (MigrationException $error) {
            self::assertSame('Database migrations require MySQL 8.', $error->getMessage());
        }
        self::assertSame(['User'], $database->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN));
    }
}
