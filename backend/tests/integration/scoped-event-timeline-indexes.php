<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/application-autoload.php';

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
function checkScopedTimeline(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
function refuseScopedTimeline(callable $operation, string $message): void
{
    try { $operation(); }
    catch (MigrationException) { checkScopedTimeline(true, $message); return; }
    throw new RuntimeException('Expected index migration refusal: ' . $message);
}
function clearScopedTimeline(PDO $database): void
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
function resetScopedTimeline(PDO $database): MigrationRunner
{
    clearScopedTimeline($database);
    $database->exec(file_get_contents(dirname(__DIR__, 2) . '/resources/chronos.sql'));
    $database->exec('SET SESSION autocommit=1');
    $database->exec("INSERT INTO `User` (userId,username,password,email,lastname,firstname,gender,role,alive,activated,created) VALUES (1,'synthetic','synthetic','synthetic@example.test','Fixture','Timeline',0,1,1,1,'2026-10-01 00:00:00')");
    $database->exec("INSERT INTO `Event` (eventId,title,time,eventText,eventType,relatedId,userId,nodeId) VALUES (1,'First','2026-10-20 10:00:00','kept','User',1,1,'test-node'),(2,'Second','2026-10-20 10:00:00','kept','User',1,1,'test-node'),(3,'Earlier','2026-10-19 10:00:00','kept','User',1,1,'test-node')");
    $runner = new MigrationRunner($database, scopedTimelineCatalog());
    $runner->migrateTo(EventTimelineIndexMigration::VERSION);
    return $runner;
}
function scopedTimelineCatalog(): array
{
    return [new UserEnabledMigration(), new LiveUpdatesMigration(), new EventTimelineIndexMigration(), new ScopedEventTimelineIndexesMigration()];
}
function scopedTimelineIndexes(PDO $database): array
{
    $rows = $database->query('SHOW INDEX FROM `Event`')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) unset($row['Cardinality']);
    unset($row);
    usort($rows, static fn(array $left, array $right): int => [$left['Key_name'], (int) $left['Seq_in_index']] <=> [$right['Key_name'], (int) $right['Seq_in_index']]);
    return $rows;
}
function scopedTimelineRecords(PDO $database): array
{
    return [$database->query('SELECT * FROM `Event` ORDER BY eventId')->fetchAll(PDO::FETCH_ASSOC),
        $database->query('SELECT * FROM `User` ORDER BY userId')->fetchAll(PDO::FETCH_ASSOC)];
}

final class InterruptedScopedTimelineMigration implements Migration
{
    public function __construct(private readonly string $fault) {}
    public function version(): string { return ScopedEventTimelineIndexesMigration::VERSION; }
    public function capture(PDO $database): array { return (new ScopedEventTimelineIndexesMigration())->capture($database); }
    public function validateContext(array $context): void { (new ScopedEventTimelineIndexesMigration())->validateContext($context); }
    public function assertState(PDO $database, array $context, bool $applied): void { (new ScopedEventTimelineIndexesMigration())->assertState($database, $context, $applied); }
    public function apply(PDO $database, array $context): void
    {
        if ($this->fault === 'before-up') throw new RuntimeException('Injected timeline DDL interruption');
        (new ScopedEventTimelineIndexesMigration())->apply($database, $context);
        if ($this->fault === 'after-up') throw new RuntimeException('Injected timeline DDL interruption');
    }
    public function revert(PDO $database, array $context): void
    {
        if ($this->fault === 'before-down') throw new RuntimeException('Injected timeline DDL interruption');
        (new ScopedEventTimelineIndexesMigration())->revert($database, $context);
        if ($this->fault === 'after-down') throw new RuntimeException('Injected timeline DDL interruption');
    }
}

try {
    $runner = resetScopedTimeline($database);
    $database->exec('CREATE INDEX `fixture_unrelated_composite` ON `Event` (`eventType`,`relatedId`,`userId`)');
    $before = scopedTimelineIndexes($database);
    $records = scopedTimelineRecords($database);
    $ordering = $database->query("SELECT eventId FROM `Event` WHERE nodeId='test-node' ORDER BY time DESC,eventId DESC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
    $status = $runner->migrateTo();
    checkScopedTimeline($status['clean'] && $status['version'] === ScopedEventTimelineIndexesMigration::VERSION, 'The current catalog applies the timeline index after the existing live schema.');
    checkScopedTimeline($runner->migrateTo() === $status, 'Repeating the index migration is idempotent.');
    checkScopedTimeline($database->query("SHOW INDEX FROM `Event` WHERE Key_name='chronos_event_node_timeline'")->rowCount() === 3, 'The new index covers node predicate and both timeline ordering columns.');
    checkScopedTimeline($database->query("SELECT eventId FROM `Event` WHERE nodeId='test-node' ORDER BY time DESC,eventId DESC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN) === $ordering, 'Equal timestamps retain deterministic descending event-ID ordering.');
    checkScopedTimeline(scopedTimelineRecords($database) === $records, 'Index creation changes no event or account data.');
    $runner->migrateTo(EventTimelineIndexMigration::VERSION);
    checkScopedTimeline(scopedTimelineIndexes($database) === $before && scopedTimelineRecords($database) === $records, 'Rollback restores all original indexes and rows, including an unrelated composite index.');
    checkScopedTimeline($database->query("SHOW TABLES LIKE 'LiveChange'")->fetchColumn() === 'LiveChange', 'Index rollback retains the preceding WebSocket outbox.');

    // Equivalent custom names, descending indexes and covering suffixes are reused.
    foreach (['`nodeId`,`time`,`eventId`', '`nodeId`,`time` DESC,`eventId` DESC', '`nodeId` DESC,`time`,`eventId`', '`nodeId`,`time`,`eventId`,`userId`'] as $columns) {
        $runner = resetScopedTimeline($database);
        $database->exec('CREATE INDEX `custom_timeline_index` ON `Event` (' . $columns . ") COMMENT 'Preserved custom index'");
        $before = scopedTimelineIndexes($database);
        $runner->migrateTo();
        checkScopedTimeline(scopedTimelineIndexes($database) === $before, 'An equivalent existing index is adopted without duplication: ' . $columns);
        $runner->migrateTo(EventTimelineIndexMigration::VERSION);
        checkScopedTimeline(scopedTimelineIndexes($database) === $before, 'Rollback preserves the exact pre-existing index: ' . $columns);
    }
    $runner = resetScopedTimeline($database);
    $database->exec('CREATE INDEX `chronos_event_node_timeline` ON `Event` (`nodeId`,`time`,`eventId`)');
    $before = scopedTimelineIndexes($database);
    $runner->migrateTo();
    $runner->migrateTo(EventTimelineIndexMigration::VERSION);
    checkScopedTimeline(scopedTimelineIndexes($database) === $before, 'An equivalent pre-existing reserved-name index is preserved during rollback.');

    foreach ([false, true] as $customIndex) {
        $runner = resetScopedTimeline($database);
        $database->exec('CREATE INDEX `CHRONOS_EVENT_NODE_TIMELINE` ON `Event` (`nodeId`,`time`,`eventId`)');
        if ($customIndex) $database->exec('CREATE INDEX `custom_usable_timeline` ON `Event` (`nodeId`,`time`,`eventId`)');
        $before = scopedTimelineIndexes($database);
        $runner->migrateTo();
        checkScopedTimeline(scopedTimelineIndexes($database) === $before, 'Case-insensitive adoption preserves the original spelling of a usable reserved index.');
        $runner->migrateTo(EventTimelineIndexMigration::VERSION);
        checkScopedTimeline(scopedTimelineIndexes($database) === $before, 'Rollback preserves an uppercase pre-existing reserved index, with or without another usable index.');

        $runner = resetScopedTimeline($database);
        $database->exec('CREATE INDEX `CHRONOS_EVENT_NODE_TIMELINE` ON `Event` (`eventId`,`time`)');
        if ($customIndex) $database->exec('CREATE INDEX `custom_usable_timeline` ON `Event` (`nodeId`,`time`,`eventId`)');
        $before = scopedTimelineIndexes($database);
        refuseScopedTimeline(fn() => $runner->migrateTo(), 'A conflicting uppercase reserved name is rejected before journal insertion.');
        checkScopedTimeline(scopedTimelineIndexes($database) === $before && $runner->status()['version'] === EventTimelineIndexMigration::VERSION
            && (int) $database->query('SELECT COUNT(*) FROM `ChronosMigration`')->fetchColumn() === 3, 'An uppercase name collision preserves every index and the previous clean journal boundary.');
    }

    foreach (['(`eventId`,`time`)', '(`nodeId`,`time`,`eventId`) INVISIBLE', '(`nodeId`,`time` ASC,`eventId` DESC)', '(`nodeId`(8),`time`,`eventId`)'] as $definition) {
        $runner = resetScopedTimeline($database);
        $database->exec('CREATE INDEX `chronos_event_node_timeline` ON `Event` ' . $definition);
        $database->exec('CREATE INDEX `custom_usable_timeline` ON `Event` (`nodeId`,`time`,`eventId`)');
        $before = scopedTimelineIndexes($database);
        refuseScopedTimeline(fn() => $runner->migrateTo(), 'A conflicting reserved name cannot be overwritten, even when another usable index exists.');
        checkScopedTimeline(scopedTimelineIndexes($database) === $before && $runner->status()['version'] === EventTimelineIndexMigration::VERSION, 'A name collision preserves the original schema and previous journal boundary.');
    }

    foreach (['new', 'existing'] as $origin) {
        foreach (['before-up', 'after-up', 'before-down', 'after-down'] as $fault) {
            $runner = resetScopedTimeline($database);
            if ($origin === 'existing') $database->exec('CREATE INDEX `custom_timeline_index` ON `Event` (`nodeId`,`time`,`eventId`)');
            $originalIndexes = scopedTimelineIndexes($database);
            $records = scopedTimelineRecords($database);
            $target = str_ends_with($fault, '-up') ? ScopedEventTimelineIndexesMigration::VERSION : EventTimelineIndexMigration::VERSION;
            if ($target === EventTimelineIndexMigration::VERSION) $runner->migrateTo();
            $checksums = [];
            foreach (scopedTimelineCatalog() as $migration) {
                $source = new ReflectionClass($migration);
                $name = $migration instanceof ScopedEventTimelineIndexesMigration ? 'InterruptedScopedTimelineMigration' : $source->getShortName();
                $checksums[$name] = hash_file('sha256', $source->getFileName());
            }
            $interrupted = new MigrationRunner($database, [new UserEnabledMigration(), new LiveUpdatesMigration(), new EventTimelineIndexMigration(), new InterruptedScopedTimelineMigration($fault)], 1, $checksums);
            try {
                $interrupted->migrateTo($target);
                throw new RuntimeException('Expected timeline interruption.');
            } catch (RuntimeException $error) {
                checkScopedTimeline($error->getMessage() === 'Injected timeline DDL interruption', 'The injected crash reaches the correct DDL boundary.');
            }
            $physical = scopedTimelineIndexes($database);
            checkScopedTimeline(!$runner->status()['clean'] && scopedTimelineIndexes($database) === $physical, 'Inspecting an interrupted index migration performs no DDL.');
            checkScopedTimeline($runner->migrateTo($target)['version'] === $target && scopedTimelineRecords($database) === $records, 'A crash before or after atomic index DDL can resume safely: ' . $origin . ' ' . $fault);
            $runner->migrateTo(EventTimelineIndexMigration::VERSION);
            checkScopedTimeline(scopedTimelineIndexes($database) === $originalIndexes, 'Recovery never removes an adopted index or changes unrelated original indexes.');
        }
    }

    $runner = resetScopedTimeline($database);
    $runner->migrateTo();
    $database->exec('ALTER TABLE `Event` ALTER INDEX `chronos_event_node_timeline` INVISIBLE');
    refuseScopedTimeline(fn() => $runner->status(), 'A changed applied index cannot satisfy the runtime schema gate.');
    refuseScopedTimeline(fn() => $runner->migrateTo(EventTimelineIndexMigration::VERSION), 'Rollback refuses to delete an index whose definition has drifted.');
    $database->exec('ALTER TABLE `Event` ALTER INDEX `chronos_event_node_timeline` VISIBLE');
    $database->exec('ALTER TABLE `User` ALTER COLUMN `enabled` SET DEFAULT 1');
    refuseScopedTimeline(fn() => $runner->assertCurrent(), 'The additive index migration retains the previous account-schema drift checks.');
    $database->exec('ALTER TABLE `User` ALTER COLUMN `enabled` DROP DEFAULT');
    checkScopedTimeline($runner->migrateTo('0')['version'] === '0', 'The complete current catalog can still restore the original baseline.');
    checkScopedTimeline($database->query('SELECT alive FROM `User`')->fetchColumn() === 1, 'Complete rollback preserves account data.');
    echo $checks . ' scoped node timeline index migration assertions passed on PHP ' . PHP_VERSION . ".\n";
} finally {
    clearScopedTimeline($database);
}
