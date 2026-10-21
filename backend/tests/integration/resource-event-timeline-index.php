<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/application-autoload.php';

use Chronos\Infrastructure\Persistence\Migrations\ResourceEventTimelineIndexMigration;
use Chronos\Infrastructure\Persistence\Migrations\ScopedEventTimelineIndexesMigration;
use Chronos\Infrastructure\Persistence\Migrations\EventTimelineIndexMigration;
use Chronos\Infrastructure\Persistence\Migrations\LiveUpdatesMigration;
use Chronos\Infrastructure\Persistence\Migrations\Migration;
use Chronos\Infrastructure\Persistence\Migrations\MigrationCatalog;
use Chronos\Infrastructure\Persistence\Migrations\MigrationException;
use Chronos\Infrastructure\Persistence\Migrations\MigrationRunner;
use Chronos\Infrastructure\Persistence\Migrations\UserEnabledMigration;

// Destructive tests have one fixed container-only synthetic target and no app config.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run' || getenv('CHRONOS_TEST_MIGRATIONS') !== '1' || !is_file('/.dockerenv')) {
    throw new RuntimeException('Run only in the explicitly enabled isolated migration fixture.');
}
$database = new PDO('mysql:host=chronos-migration-db;dbname=chronos_migration_test;charset=utf8mb4', 'chronos_migration', 'chronos_migration_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($database->query('SELECT DATABASE()')->fetchColumn() !== 'chronos_migration_test'
    || !str_starts_with((string) $database->query('SELECT CURRENT_USER()')->fetchColumn(), 'chronos_migration@')) {
    throw new RuntimeException('Refusing to change an unexpected database or account.');
}
$checks = 0;
function checkResourceTimeline(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
function refuseResourceTimeline(callable $operation, string $message): void
{
    try { $operation(); }
    catch (MigrationException) { checkResourceTimeline(true, $message); return; }
    throw new RuntimeException('Expected index migration refusal: ' . $message);
}
function clearResourceTimeline(PDO $database): void
{
    $objects = $database->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach (['VIEW', 'BASE TABLE'] as $kind) {
        foreach ($objects as [$name, $type]) {
            if ($type !== $kind) continue;
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) throw new RuntimeException('Unexpected fixture object.');
            $database->exec(($kind === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . '`' . $name . '`');
        }
    }
}
function resetResourceTimeline(PDO $database): MigrationRunner
{
    clearResourceTimeline($database);
    $database->exec(file_get_contents(dirname(__DIR__, 2) . '/resources/chronos.sql'));
    $database->exec('SET SESSION autocommit=1');
    $database->exec("INSERT INTO `User` (userId,username,password,email,lastname,firstname,gender,role,alive,activated,created) VALUES (1,'synthetic','synthetic','synthetic@example.test','Fixture','Timeline',0,1,1,1,'2026-10-01 00:00:00')");
    $database->exec("INSERT INTO `Event` (eventId,title,time,eventText,eventType,relatedId,userId,nodeId) VALUES (1,'First','2026-10-20 10:00:00','kept','User',1,1,'test-node'),(2,'Second','2026-10-20 10:00:00','kept','User',1,1,'test-node'),(3,'Earlier','2026-10-19 10:00:00','kept','User',1,1,'test-node')");
    $runner = new MigrationRunner($database, resourceTimelineCatalog());
    $runner->migrateTo(ScopedEventTimelineIndexesMigration::VERSION);
    return $runner;
}
function resourceTimelineCatalog(): array
{
    return [new UserEnabledMigration(), new LiveUpdatesMigration(), new EventTimelineIndexMigration(), new ScopedEventTimelineIndexesMigration(), new ResourceEventTimelineIndexMigration()];
}
function resourceTimelineIndexes(PDO $database): array
{
    $rows = $database->query('SHOW INDEX FROM `Event`')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) unset($row['Cardinality']);
    unset($row);
    usort($rows, static fn(array $left, array $right): int => [$left['Key_name'], (int) $left['Seq_in_index']] <=> [$right['Key_name'], (int) $right['Seq_in_index']]);
    return $rows;
}
function resourceTimelineRecords(PDO $database): array
{
    return [$database->query('SELECT * FROM `Event` ORDER BY eventId')->fetchAll(PDO::FETCH_ASSOC),
        $database->query('SELECT * FROM `User` ORDER BY userId')->fetchAll(PDO::FETCH_ASSOC)];
}

final class InterruptedResourceTimelineMigration implements Migration
{
    public function __construct(private readonly string $fault) {}
    public function version(): string { return ResourceEventTimelineIndexMigration::VERSION; }
    public function capture(PDO $database): array { return (new ResourceEventTimelineIndexMigration())->capture($database); }
    public function validateContext(array $context): void { (new ResourceEventTimelineIndexMigration())->validateContext($context); }
    public function assertState(PDO $database, array $context, bool $applied): void { (new ResourceEventTimelineIndexMigration())->assertState($database, $context, $applied); }
    public function apply(PDO $database, array $context): void
    {
        if ($this->fault === 'before-up') throw new RuntimeException('Injected timeline DDL interruption');
        (new ResourceEventTimelineIndexMigration())->apply($database, $context);
        if ($this->fault === 'after-up') throw new RuntimeException('Injected timeline DDL interruption');
    }
    public function revert(PDO $database, array $context): void
    {
        if ($this->fault === 'before-down') throw new RuntimeException('Injected timeline DDL interruption');
        (new ResourceEventTimelineIndexMigration())->revert($database, $context);
        if ($this->fault === 'after-down') throw new RuntimeException('Injected timeline DDL interruption');
    }
}

try {
    $runner = resetResourceTimeline($database);
    $database->exec('CREATE INDEX `fixture_unrelated_composite` ON `Event` (`eventType`,`relatedId`,`userId`)');
    $before = resourceTimelineIndexes($database);
    $records = resourceTimelineRecords($database);
    $ordering = $database->query("SELECT eventId FROM `Event` WHERE eventType='User' AND relatedId=1 ORDER BY eventId DESC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
    $status = $runner->migrateTo();
    checkResourceTimeline($status['clean'] && $status['version'] === ResourceEventTimelineIndexMigration::VERSION, 'The current catalog applies the timeline index after the existing live schema.');
    checkResourceTimeline($runner->migrateTo() === $status, 'Repeating the index migration is idempotent.');
    checkResourceTimeline($database->query("SHOW INDEX FROM `Event` WHERE Key_name='chronos_event_resource_timeline'")->rowCount() === 4, 'The new index covers resource predicate, identifier paging and timestamp covering.');
    checkResourceTimeline($database->query("SELECT eventId FROM `Event` WHERE eventType='User' AND relatedId=1 ORDER BY eventId DESC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN) === $ordering, 'The resource index retains deterministic descending event-ID ordering.');
    checkResourceTimeline(resourceTimelineRecords($database) === $records, 'Index creation changes no event or account data.');
    $runner->migrateTo(ScopedEventTimelineIndexesMigration::VERSION);
    checkResourceTimeline(resourceTimelineIndexes($database) === $before && resourceTimelineRecords($database) === $records, 'Rollback restores all original indexes and rows, including an unrelated composite index.');
    checkResourceTimeline($database->query("SHOW TABLES LIKE 'LiveChange'")->fetchColumn() === 'LiveChange', 'Index rollback retains the preceding WebSocket outbox.');

    // Equivalent custom names, descending indexes and covering suffixes are reused.
    foreach (['`eventType`,`relatedId`,`eventId`,`time`', '`eventType`,`relatedId`,`eventId` DESC,`time`', '`eventType` DESC,`relatedId` DESC,`eventId`,`time` DESC', '`eventType`,`relatedId`,`eventId`,`time`,`userId`'] as $columns) {
        $runner = resetResourceTimeline($database);
        $database->exec('CREATE INDEX `custom_timeline_index` ON `Event` (' . $columns . ") COMMENT 'Preserved custom index'");
        $before = resourceTimelineIndexes($database);
        $runner->migrateTo();
        checkResourceTimeline(resourceTimelineIndexes($database) === $before, 'An equivalent existing index is adopted without duplication: ' . $columns);
        $runner->migrateTo(ScopedEventTimelineIndexesMigration::VERSION);
        checkResourceTimeline(resourceTimelineIndexes($database) === $before, 'Rollback preserves the exact pre-existing index: ' . $columns);
    }
    $runner = resetResourceTimeline($database);
    $database->exec('CREATE INDEX `chronos_event_resource_timeline` ON `Event` (`eventType`,`relatedId`,`eventId`,`time`)');
    $before = resourceTimelineIndexes($database);
    $runner->migrateTo();
    $runner->migrateTo(ScopedEventTimelineIndexesMigration::VERSION);
    checkResourceTimeline(resourceTimelineIndexes($database) === $before, 'An equivalent pre-existing reserved-name index is preserved during rollback.');

    foreach ([false, true] as $customIndex) {
        $runner = resetResourceTimeline($database);
        $database->exec('CREATE INDEX `CHRONOS_EVENT_RESOURCE_TIMELINE` ON `Event` (`eventType`,`relatedId`,`eventId`,`time`)');
        if ($customIndex) $database->exec('CREATE INDEX `custom_usable_timeline` ON `Event` (`eventType`,`relatedId`,`eventId`,`time`)');
        $before = resourceTimelineIndexes($database);
        $runner->migrateTo();
        checkResourceTimeline(resourceTimelineIndexes($database) === $before, 'Case-insensitive adoption preserves the original spelling of a usable reserved index.');
        $runner->migrateTo(ScopedEventTimelineIndexesMigration::VERSION);
        checkResourceTimeline(resourceTimelineIndexes($database) === $before, 'Rollback preserves an uppercase pre-existing reserved index, with or without another usable index.');

        $runner = resetResourceTimeline($database);
        $database->exec('CREATE INDEX `CHRONOS_EVENT_RESOURCE_TIMELINE` ON `Event` (`eventId`,`time`)');
        if ($customIndex) $database->exec('CREATE INDEX `custom_usable_timeline` ON `Event` (`eventType`,`relatedId`,`eventId`,`time`)');
        $before = resourceTimelineIndexes($database);
        refuseResourceTimeline(fn() => $runner->migrateTo(), 'A conflicting uppercase reserved name is rejected before journal insertion.');
        checkResourceTimeline(resourceTimelineIndexes($database) === $before && $runner->status()['version'] === ScopedEventTimelineIndexesMigration::VERSION
            && (int) $database->query('SELECT COUNT(*) FROM `ChronosMigration`')->fetchColumn() === 4, 'An uppercase name collision preserves every index and the previous clean journal boundary.');
    }

    foreach (['(`eventId`,`time`)', '(`eventType`,`relatedId`,`eventId`,`time`) INVISIBLE', '(`eventType`,`relatedId`,`time`,`eventId`)', '(`eventType`(8),`relatedId`,`eventId`,`time`)'] as $definition) {
        $runner = resetResourceTimeline($database);
        $database->exec('CREATE INDEX `chronos_event_resource_timeline` ON `Event` ' . $definition);
        $database->exec('CREATE INDEX `custom_usable_timeline` ON `Event` (`eventType`,`relatedId`,`eventId`,`time`)');
        $before = resourceTimelineIndexes($database);
        refuseResourceTimeline(fn() => $runner->migrateTo(), 'A conflicting reserved name cannot be overwritten, even when another usable index exists.');
        checkResourceTimeline(resourceTimelineIndexes($database) === $before && $runner->status()['version'] === ScopedEventTimelineIndexesMigration::VERSION, 'A name collision preserves the original schema and previous journal boundary.');
    }

    foreach (['new', 'existing'] as $origin) {
        foreach (['before-up', 'after-up', 'before-down', 'after-down'] as $fault) {
            $runner = resetResourceTimeline($database);
            if ($origin === 'existing') $database->exec('CREATE INDEX `custom_timeline_index` ON `Event` (`eventType`,`relatedId`,`eventId`,`time`)');
            $originalIndexes = resourceTimelineIndexes($database);
            $records = resourceTimelineRecords($database);
            $target = str_ends_with($fault, '-up') ? ResourceEventTimelineIndexMigration::VERSION : ScopedEventTimelineIndexesMigration::VERSION;
            if ($target === ScopedEventTimelineIndexesMigration::VERSION) $runner->migrateTo();
            $checksums = [];
            foreach (resourceTimelineCatalog() as $migration) {
                $source = new ReflectionClass($migration);
                $name = $migration instanceof ResourceEventTimelineIndexMigration ? 'InterruptedResourceTimelineMigration' : $source->getShortName();
                $checksums[$name] = hash_file('sha256', $source->getFileName());
            }
            $interrupted = new MigrationRunner($database, [new UserEnabledMigration(), new LiveUpdatesMigration(), new EventTimelineIndexMigration(), new ScopedEventTimelineIndexesMigration(), new InterruptedResourceTimelineMigration($fault)], 1, $checksums);
            try {
                $interrupted->migrateTo($target);
                throw new RuntimeException('Expected timeline interruption.');
            } catch (RuntimeException $error) {
                checkResourceTimeline($error->getMessage() === 'Injected timeline DDL interruption', 'The injected crash reaches the correct DDL boundary.');
            }
            $physical = resourceTimelineIndexes($database);
            checkResourceTimeline(!$runner->status()['clean'] && resourceTimelineIndexes($database) === $physical, 'Inspecting an interrupted index migration performs no DDL.');
            checkResourceTimeline($runner->migrateTo($target)['version'] === $target && resourceTimelineRecords($database) === $records, 'A crash before or after atomic index DDL can resume safely: ' . $origin . ' ' . $fault);
            $runner->migrateTo(ScopedEventTimelineIndexesMigration::VERSION);
            checkResourceTimeline(resourceTimelineIndexes($database) === $originalIndexes, 'Recovery never removes an adopted index or changes unrelated original indexes.');
        }
    }

    $runner = resetResourceTimeline($database);
    $runner->migrateTo();
    $database->exec('ALTER TABLE `Event` ALTER INDEX `chronos_event_resource_timeline` INVISIBLE');
    refuseResourceTimeline(fn() => $runner->status(), 'A changed applied index cannot satisfy the runtime schema gate.');
    refuseResourceTimeline(fn() => $runner->migrateTo(ScopedEventTimelineIndexesMigration::VERSION), 'Rollback refuses to delete an index whose definition has drifted.');
    $database->exec('ALTER TABLE `Event` ALTER INDEX `chronos_event_resource_timeline` VISIBLE');
    $database->exec('ALTER TABLE `User` ALTER COLUMN `enabled` SET DEFAULT 1');
    refuseResourceTimeline(fn() => $runner->assertCurrent(), 'The additive index migration retains the previous account-schema drift checks.');
    $database->exec('ALTER TABLE `User` ALTER COLUMN `enabled` DROP DEFAULT');
    checkResourceTimeline($runner->migrateTo('0')['version'] === '0', 'The complete current catalog can still restore the original baseline.');
    checkResourceTimeline($database->query('SELECT alive FROM `User`')->fetchColumn() === 1, 'Complete rollback preserves account data.');
    echo $checks . ' resource timeline index migration assertions passed on PHP ' . PHP_VERSION . ".\n";
} finally {
    clearResourceTimeline($database);
}
