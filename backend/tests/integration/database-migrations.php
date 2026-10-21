<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/application-autoload.php';

use Chronos\Infrastructure\Persistence\Migrations\MigrationCatalog;
use Chronos\Infrastructure\Persistence\Migrations\Migration;
use Chronos\Infrastructure\Persistence\Migrations\MigrationException;
use Chronos\Infrastructure\Persistence\Migrations\MigrationRunner;
use Chronos\Infrastructure\Persistence\Migrations\UserEnabledMigration;
use Chronos\Infrastructure\Persistence\Migrations\LiveUpdatesMigration;

// This destructive harness has one fixed, isolated target and never reads app config.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run' || getenv('CHRONOS_TEST_MIGRATIONS') !== '1' || !is_file('/.dockerenv')) {
    throw new RuntimeException('Run only in the explicitly enabled isolated migration fixture.');
}
function database(): PDO
{
    return new PDO('mysql:host=chronos-migration-db;dbname=chronos_migration_test;charset=utf8mb4', 'chronos_migration', 'chronos_migration_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
$database = database();
if ($database->query('SELECT DATABASE()')->fetchColumn() !== 'chronos_migration_test'
    || !str_starts_with((string) $database->query('SELECT CURRENT_USER()')->fetchColumn(), 'chronos_migration@')) {
    throw new RuntimeException('Refusing to change an unexpected database or account.');
}
$assertions = 0;
function ensure(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) throw new RuntimeException($message);
}
function rejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (MigrationException) {
        ensure(true, $message);
        return;
    }
    throw new RuntimeException('Expected migration rejection: ' . $message);
}
function resetSchema(PDO $database, string $column = 'alive', string $definition = "tinyint unsigned DEFAULT '1' COMMENT 'Synthetic account flag'"): void
{
    $database->exec('DROP TABLE IF EXISTS `Event`');
    $database->exec('DROP TABLE IF EXISTS `LiveChange`');
    $database->exec('DROP TABLE IF EXISTS `SystemMaintainer`');
    $database->exec('DROP TABLE IF EXISTS `System`');
    $database->exec('DROP VIEW IF EXISTS `ChronosMigrationView`');
    $database->exec('DROP TABLE IF EXISTS `ChronosMigration`');
    $database->exec('DROP TABLE IF EXISTS `User`');
    $database->exec('CREATE TABLE `User` (`userId` int NOT NULL PRIMARY KEY,`username` varchar(40) NOT NULL,`' . $column . '` ' . $definition . ',KEY `account_state` (`' . $column . '` DESC,`userId`)) ENGINE=InnoDB');
    $database->exec("INSERT INTO `User` VALUES (1,'enabled user',1),(2,'disabled user',0),(3,'nullable user',NULL)");
    $database->exec('CREATE TABLE `Event` (`eventId` int NOT NULL PRIMARY KEY,`time` datetime NOT NULL,`nodeId` varchar(64) DEFAULT NULL,`eventType` varchar(50) NOT NULL,`relatedId` int DEFAULT NULL) ENGINE=InnoDB');
    $database->exec('CREATE TABLE `System` (`systemId` int NOT NULL PRIMARY KEY,`userId` int NOT NULL) ENGINE=InnoDB');
}
function users(PDO $database, string $column): array
{
    return $database->query('SELECT userId,username,`' . $column . '` AS enabled FROM `User` ORDER BY userId')->fetchAll(PDO::FETCH_ASSOC);
}
function column(PDO $database, string $name): bool
{
    $statement = $database->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='User' AND COLUMN_NAME=?");
    $statement->execute([$name]);
    return (int) $statement->fetchColumn() === 1;
}
function clearSchema(PDO $database): void
{
    $tables = $database->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach (['VIEW', 'BASE TABLE'] as $kind) {
        foreach ($tables as [$name, $type]) {
            if ($type !== $kind) continue;
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) throw new RuntimeException('Unexpected fixture object name.');
            $database->exec(($kind === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . '`' . $name . '`');
        }
    }
}
function baselineUser(PDO $database, int $id, string $stateColumn, int $enabled): void
{
    if (!in_array($stateColumn, ['alive', 'enabled'], true)) throw new RuntimeException('Invalid fixture state column.');
    $statement = $database->prepare('INSERT INTO `User` (userId,username,password,email,lastname,firstname,gender,role,`' . $stateColumn . '`,activated,created) VALUES (?,?,?,?,?,?,0,0,?,1,\'2026-01-15 12:00:00\')');
    $statement->execute([$id, 'fixture-' . $id, 'synthetic-hash-' . $id, 'fixture-' . $id . '@example.test', 'Synthetic', 'Fixture', $enabled]);
}
function accountRecords(PDO $database, string $stateColumn): array
{
    $rows = $database->query('SELECT * FROM `User` ORDER BY userId')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        if ($stateColumn === 'alive') { $row['enabled'] = $row['alive']; unset($row['alive']); }
        unset($row['fixture_before_enabled']);
        ksort($row);
    }
    unset($row);
    return $rows;
}
function journal(PDO $database, string $state, array $context, ?string $checksum = null): void
{
    $checksum ??= hash_file('sha256', (new ReflectionClass(UserEnabledMigration::class))->getFileName());
    $statement = $database->prepare('INSERT INTO `ChronosMigration` (`version`,`checksum`,`state`,`context`,`startedAt`,`updatedAt`) VALUES (?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))');
    $statement->execute([UserEnabledMigration::VERSION, $checksum, $state, json_encode($context, JSON_THROW_ON_ERROR)]);
}

final class InterruptedAccountMigration implements Migration
{
    public function __construct(private readonly string $fault) {}
    public function version(): string { return UserEnabledMigration::VERSION; }
    public function capture(PDO $database): array { return (new UserEnabledMigration())->capture($database); }
    public function validateContext(array $context): void { (new UserEnabledMigration())->validateContext($context); }
    public function assertState(PDO $database, array $context, bool $applied): void { (new UserEnabledMigration())->assertState($database, $context, $applied); }
    public function apply(PDO $database, array $context): void
    {
        if ($this->fault === 'before-up') throw new RuntimeException('Injected DDL interruption');
        (new UserEnabledMigration())->apply($database, $context);
        if ($this->fault === 'after-up') throw new RuntimeException('Injected DDL interruption');
    }
    public function revert(PDO $database, array $context): void
    {
        if ($this->fault === 'before-down') throw new RuntimeException('Injected DDL interruption');
        (new UserEnabledMigration())->revert($database, $context);
        if ($this->fault === 'after-down') throw new RuntimeException('Injected DDL interruption');
    }
}

final class SecondFixtureMigration implements Migration
{
    public function version(): string { return '202610180002_fixture_marker'; }
    public function capture(PDO $database): array { return ['fixture' => true]; }
    public function validateContext(array $context): void { if ($context !== ['fixture' => true]) throw new MigrationException('Invalid fixture recovery context.'); }
    public function apply(PDO $database, array $context): void { $database->exec('CREATE TABLE IF NOT EXISTS `ChronosMigrationProbe` (`id` int PRIMARY KEY) ENGINE=InnoDB'); }
    public function revert(PDO $database, array $context): void { $database->exec('DROP TABLE IF EXISTS `ChronosMigrationProbe`'); }
    public function assertState(PDO $database, array $context, bool $applied): void
    {
        $exists = (bool) $database->query("SHOW TABLES LIKE 'ChronosMigrationProbe'")->fetchColumn();
        if ($context !== ['fixture' => true] || $exists !== $applied) throw new MigrationException('Invalid fixture schema boundary.');
    }
}

/** A later schema change intentionally invalidates the previous column position and index set. */
final class UserShapeFixtureMigration implements Migration
{
    public function version(): string { return '202610180002_fixture_user_shape'; }
    public function capture(PDO $database): array
    {
        $context = (new UserEnabledMigration())->capture($database);
        (new UserEnabledMigration())->assertState($database, $context, true);
        if (column($database, 'fixture_before_enabled') || $this->indexExists($database)) throw new MigrationException('The fixture migration requires its genuine previous schema.');
        return ['account' => $context];
    }
    public function validateContext(array $context): void
    {
        if (array_keys($context) !== ['account'] || !is_array($context['account'])) throw new MigrationException('Invalid user-shape fixture context.');
        (new UserEnabledMigration())->validateContext($context['account']);
    }
    public function apply(PDO $database, array $context): void
    {
        if (!column($database, 'fixture_before_enabled') && !$this->indexExists($database)) {
            $database->exec('ALTER TABLE `User` ADD COLUMN `fixture_before_enabled` int NOT NULL DEFAULT 0 AFTER `role`, ADD INDEX `fixture_enabled_index` (`enabled`,`userId`)');
        }
    }
    public function revert(PDO $database, array $context): void
    {
        if (column($database, 'fixture_before_enabled') && $this->indexExists($database)) {
            $database->exec('ALTER TABLE `User` DROP INDEX `fixture_enabled_index`, DROP COLUMN `fixture_before_enabled`');
        }
    }
    public function assertState(PDO $database, array $context, bool $applied): void
    {
        $this->validateContext($context);
        $current = (new UserEnabledMigration())->capture($database);
        $expected = $context['account'];
        if ($applied) {
            $expected['column']['attributes']['ORDINAL_POSITION'] = (string) ((int) $expected['column']['attributes']['ORDINAL_POSITION'] + 1);
            $added = array_values(array_filter($current['column']['indexes'], static fn(array $index): bool => $index['Key_name'] === 'fixture_enabled_index'));
            if (count($added) !== 2 || $added[0]['Column_name'] !== '<account-state>' || $added[1]['Column_name'] !== 'userId') throw new MigrationException('The fixture status index is incomplete.');
            $current['column']['indexes'] = array_values(array_filter($current['column']['indexes'], static fn(array $index): bool => $index['Key_name'] !== 'fixture_enabled_index'));
        }
        if ($current !== $expected || column($database, 'fixture_before_enabled') !== $applied || $this->indexExists($database) !== $applied) {
            throw new MigrationException('The user-shape fixture schema does not match its recorded boundary.');
        }
    }
    private function indexExists(PDO $database): bool
    {
        return $database->query("SHOW INDEX FROM `User` WHERE Key_name='fixture_enabled_index'")->fetch(PDO::FETCH_ASSOC) !== false;
    }
}

final class NoCreateMigrationPdo extends PDO
{
    public int $createAttempts = 0;
    public function __construct()
    {
        parent::__construct('mysql:host=chronos-migration-db;dbname=chronos_migration_test;charset=utf8mb4', 'chronos_migration', 'chronos_migration_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    public function exec(string $statement): int|false
    {
        if (preg_match('/^CREATE TABLE/i', $statement)) {
            ++$this->createAttempts;
            throw new PDOException('Injected CREATE privilege denial');
        }
        return parent::exec($statement);
    }
}

try {
    resetSchema($database);
    $runner = new MigrationRunner($database, lockTimeout: 1);
    $before = users($database, 'alive');
    $tables = $database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $status = $runner->status();
    ensure($status['version'] === '0' && !$status['initialized'] && $status['clean'], 'A v2 schema has an explicit untracked baseline.');
    ensure($database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) === $tables, 'Read-only status creates no metadata.');
    rejected(fn() => $runner->assertCurrent(), 'Runtime compatibility cannot silently migrate.');
    rejected(fn() => $runner->migrateTo('209901010001_future'), 'Unpackaged target versions are rejected.');
    ensure($database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) === $tables, 'An unknown target cannot even create metadata.');
    $deniedCreate = new NoCreateMigrationPdo();
    $deniedRunner = new MigrationRunner($deniedCreate, lockTimeout: 1);
    try {
        $deniedRunner->migrateTo();
        throw new RuntimeException('Expected the injected CREATE denial.');
    } catch (PDOException $error) {
        ensure($error->getMessage() === 'Injected CREATE privilege denial', 'The intended initial journal CREATE failure reaches the caller.');
    }
    ensure($deniedCreate->createAttempts === 1, 'The upgrade attempted to create its durable journal.');
    $baseline = $deniedRunner->migrateTo('0');
    ensure($baseline['version'] === '0' && !$baseline['initialized'] && $baseline['clean'], 'Recovery verifies the intact untracked v2 baseline without CREATE privileges.');
    ensure($deniedCreate->createAttempts === 1 && users($database, 'alive') === $before, 'Recovery does not retry forbidden metadata creation or touch account rows.');
    $originalShape = (new UserEnabledMigration())->capture($database);
    $oldTimeout = $database->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
    $status = $runner->migrateTo();
    ensure($status['version'] === MigrationCatalog::latestVersion() && $status['clean'], 'The verified upgrade records its target.');
    ensure(column($database, 'enabled') && !column($database, 'alive'), 'The physical account column is renamed.');
    ensure(users($database, 'enabled') === $before, 'All account rows retain their original 0/1/null values.');
    ensure((new UserEnabledMigration())->capture($database) === $originalShape, 'Type/default/nullability/comment/index order and attributes are preserved.');
    ensure($database->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn() === $oldTimeout, 'The metadata lock timeout is restored.');
    $runner->assertCurrent();
    ensure($runner->migrateTo() === $status, 'Repeating an already completed upgrade is idempotent.');
    $database->exec("UPDATE `User` SET enabled=0 WHERE userId=1");
    $database->exec("INSERT INTO `User` VALUES(4,'post-update user',1)");
    $updated = users($database, 'enabled');
    ensure($runner->migrateTo('0')['version'] === '0', 'Rollback returns to the original schema version.');
    ensure(users($database, 'alive') === $updated, 'Rollback preserves accounts created or changed after the update.');
    ensure((int) $database->query('SELECT COUNT(*) FROM `ChronosMigration`')->fetchColumn() === 0, 'A successful down clears active migration history for older releases.');

    resetSchema($database);
    $database->exec('DELETE FROM `User` WHERE userId=3');
    $database->exec('ALTER TABLE `User` MODIFY `alive` tinyint NOT NULL');
    $legacyRequired = (new UserEnabledMigration())->capture($database);
    $legacyRows = users($database, 'alive');
    $runner->migrateTo();
    ensure((new UserEnabledMigration())->capture($database) === $legacyRequired, 'The original v2 NOT NULL column without a default keeps its exact definition.');
    $runner->migrateTo('0');
    ensure((new UserEnabledMigration())->capture($database) === $legacyRequired && users($database, 'alive') === $legacyRows, 'The original v2 required boolean also reverses without data or attribute changes.');

    // An advanced snapshot without history cannot reconstruct previous-version contexts.
    resetSchema($database, 'enabled');
    $fresh = users($database, 'enabled');
    $freshTables = $database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    rejected(fn() => $runner->status(), 'Read-only status rejects an untracked advanced schema.');
    rejected(fn() => $runner->assertCurrent(), 'Requests cannot adopt an untracked advanced schema.');
    rejected(fn() => $runner->migrateTo(), 'An explicit upgrade cannot invent missing migration history.');
    rejected(fn() => $runner->migrateTo('0'), 'An explicit rollback cannot guess an untracked advanced schema.');
    ensure(users($database, 'enabled') === $fresh && !column($database, 'alive')
        && $database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) === $freshTables, 'Refusing an untracked advanced snapshot preserves all data and creates no history or DDL.');

    resetSchema($database);
    $runner->migrateTo();
    $runner->migrateTo('0');
    $database->exec('ALTER TABLE `User` RENAME COLUMN `alive` TO `enabled`');
    rejected(fn() => $runner->status(), 'An empty ledger also cannot explain an advanced physical schema.');
    rejected(fn() => $runner->migrateTo('0'), 'An empty ledger cannot authorize an inverse migration.');
    ensure((int) $database->query('SELECT COUNT(*) FROM `ChronosMigration`')->fetchColumn() === 0 && column($database, 'enabled'), 'Refusing missing active history leaves an empty ledger and the physical schema unchanged.');

    // Durable crash points before and after either implicit-commit DDL statement.
    foreach (['applying', 'reverting'] as $state) {
        foreach (['alive', 'enabled'] as $physical) {
            foreach (['0', MigrationCatalog::latestVersion()] as $target) {
                resetSchema($database);
                $context = (new UserEnabledMigration())->capture($database);
                $runner->migrateTo();
                $runner->migrateTo('0'); // Produce a legitimate empty ledger through real up/down.
                if ($physical === 'enabled') $database->exec('ALTER TABLE `User` RENAME COLUMN `alive` TO `enabled`');
                journal($database, $state, $context);
                $before = users($database, $physical);
                $status = $runner->status();
                ensure(!$status['clean'] && $status['inProgress']['state'] === $state, 'Read-only status exposes an interrupted transition.');
                ensure(column($database, $physical), 'Read-only status never completes interrupted DDL.');
                $status = $runner->migrateTo($target);
                $expectedColumn = $target === '0' ? 'alive' : 'enabled';
                ensure($status['clean'] && $status['version'] === $target, 'Crash recovery reaches the explicitly requested side.');
                ensure(users($database, $expectedColumn) === $before, 'Crash recovery preserves the complete account rows.');
            }
        }
    }

    $sourceChecksum = hash_file('sha256', (new ReflectionClass(UserEnabledMigration::class))->getFileName());
    foreach (['before-up', 'after-up', 'before-down', 'after-down'] as $fault) {
        resetSchema($database);
        $target = str_ends_with($fault, '-up') ? UserEnabledMigration::VERSION : '0';
        if ($target === '0') $runner->migrateTo(UserEnabledMigration::VERSION);
        $physicalBefore = $target === '0' ? 'enabled' : 'alive';
        $before = users($database, $physicalBefore);
        $interrupted = new MigrationRunner($database, [new InterruptedAccountMigration($fault)], 1, ['InterruptedAccountMigration' => $sourceChecksum]);
        try {
            $interrupted->migrateTo($target);
            throw new RuntimeException('The injected interruption did not happen.');
        } catch (RuntimeException $error) {
            ensure($error->getMessage() === 'Injected DDL interruption', 'The intended DDL interruption reaches the caller.');
        }
        ensure(!$runner->status()['clean'], 'Real failed DDL work is never recorded as successfully applied.');
        $status = $runner->migrateTo($target);
        ensure($status['clean'] && $status['version'] === $target, 'Original-source code resumes a checksum-matched isolated bundle interruption.');
        ensure(users($database, $target === '0' ? 'alive' : 'enabled') === $before, 'Retry after an injected interruption preserves every account.');
    }

    // A later migration can be rolled back independently, and older code rejects it.
    resetSchema($database);
    $twoMigrations = new MigrationRunner($database, [new UserEnabledMigration(), new SecondFixtureMigration()], 1);
    ensure($twoMigrations->migrateTo()['version'] === '202610180002_fixture_marker', 'An ordered multi-migration catalog applies sequentially.');
    rejected(fn() => $runner->status(), 'An older release rejects a valid but unknown future migration.');
    ensure($twoMigrations->migrateTo(UserEnabledMigration::VERSION)['version'] === UserEnabledMigration::VERSION, 'Only migrations newer than the requested target are reversed.');
    (new MigrationRunner($database, [new UserEnabledMigration()]))->assertCurrent();
    ensure($twoMigrations->migrateTo()['version'] === '202610180002_fixture_marker', 'A reversed later migration can be reapplied.');
    $database->exec("UPDATE `ChronosMigration` SET context='[]' WHERE version='" . UserEnabledMigration::VERSION . "'");
    rejected(fn() => $twoMigrations->status(), 'Malformed earlier recovery context is rejected even under a later schema boundary.');
    $database->exec("DELETE FROM `ChronosMigration` WHERE version='" . UserEnabledMigration::VERSION . "'");
    rejected(fn() => $twoMigrations->status(), 'A journal containing a later known migration without its prefix is rejected.');
    $database->exec('DROP TABLE `ChronosMigrationProbe`');

    resetSchema($database);
    $runner->migrateTo();
    $sourceChecksums = [];
    foreach (MigrationCatalog::migrations() as $migration) {
        $source = new ReflectionClass($migration);
        $sourceChecksums[$source->getShortName()] = hash_file('sha256', $source->getFileName());
    }
    (new MigrationRunner($database, sourceChecksums: $sourceChecksums))->assertCurrent();
    rejected(fn() => new MigrationRunner($database, sourceChecksums: []), 'A compiled bundle cannot omit an original source checksum.');
    rejected(fn() => new MigrationRunner($database, sourceChecksums: ['UserEnabledMigration' => 'not-sha256']), 'A compiled bundle checksum must be SHA256.');
    rejected(fn() => new MigrationRunner($database, sourceChecksums: ['UserEnabledMigration' => $sourceChecksum, 'UnknownMigration' => $sourceChecksum]), 'A compiled bundle cannot include an unknown checksum key.');
    rejected(fn() => (new MigrationRunner($database, sourceChecksums: ['UserEnabledMigration' => str_repeat('0', 64)]))->status(), 'Wrong original source checksums reject existing history.');

    // Fail closed for drift and metadata tampering, before schema work can begin.
    resetSchema($database);
    $runner->migrateTo();
    $database->exec('ALTER TABLE `User` ADD COLUMN `alive` tinyint DEFAULT 1');
    rejected(fn() => $runner->status(), 'Both status columns are rejected.');
    rejected(fn() => $runner->migrateTo('0'), 'Rollback cannot guess when both columns exist.');
    resetSchema($database);
    $runner->migrateTo();
    $database->exec('ALTER TABLE `User` DROP COLUMN `enabled`');
    rejected(fn() => $runner->assertCurrent(), 'A missing status column fails the runtime gate.');
    resetSchema($database);
    $runner->migrateTo();
    $database->exec('ALTER TABLE `User` ALTER COLUMN `enabled` SET DEFAULT 0');
    rejected(fn() => $runner->status(), 'A changed status default is schema drift.');
    resetSchema($database);
    $runner->migrateTo();
    $database->exec('DROP INDEX `account_state` ON `User`');
    rejected(fn() => $runner->status(), 'A lost status index is schema drift.');
    resetSchema($database);
    $runner->migrateTo();
    $database->exec('UPDATE `User` SET enabled=2 WHERE userId=1');
    rejected(fn() => $runner->status(), 'Non-boolean stored data is rejected.');
    resetSchema($database);
    $runner->migrateTo();
    $database->exec("UPDATE `ChronosMigration` SET checksum=REPEAT('0',64)");
    rejected(fn() => $runner->status(), 'Changed applied migration checksums are rejected.');
    resetSchema($database);
    $runner->migrateTo();
    $database->exec("UPDATE `ChronosMigration` SET version='209901010001_future' WHERE version='" . MigrationCatalog::latestVersion() . "'");
    rejected(fn() => $runner->status(), 'Future database history cannot run under an older catalog.');
    rejected(fn() => $runner->migrateTo('0'), 'An older catalog cannot reverse unknown future migrations.');
    resetSchema($database);
    $runner->migrateTo();
    rejected(fn() => (new MigrationRunner($database, []))->status(), 'A catalog without the applied migration fails closed.');
    foreach (['not-json', 'null', '[]'] as $invalidContext) {
        resetSchema($database);
        $runner->migrateTo();
        $statement = $database->prepare('UPDATE `ChronosMigration` SET context=?');
        $statement->execute([$invalidContext]);
        rejected(fn() => $runner->status(), 'Malformed or missing recovery context is rejected.');
    }
    resetSchema($database);
    $runner->migrateTo();
    $database->exec("UPDATE `ChronosMigration` SET state='unknown'");
    rejected(fn() => $runner->status(), 'Unknown journal states are rejected.');
    resetSchema($database);
    $database->exec('CREATE TABLE `ChronosMigration` (version varchar(128) PRIMARY KEY) ENGINE=InnoDB');
    rejected(fn() => $runner->status(), 'An unrelated or malformed ledger table is rejected.');
    resetSchema($database);
    $database->exec('ALTER TABLE `User` MODIFY `alive` int DEFAULT 1');
    rejected(fn() => $runner->migrateTo(), 'A non-tinyint status column is rejected before journal creation.');
    ensure($database->query("SHOW TABLES LIKE 'ChronosMigration'")->fetchColumn() === false, 'Invalid source schema creates no journal.');
    resetSchema($database);
    $database->exec('ALTER TABLE `User` ALTER COLUMN `alive` SET DEFAULT 2');
    rejected(fn() => $runner->migrateTo(), 'A non-boolean default is rejected even when all current rows contain booleans.');
    resetSchema($database);
    $database->exec('DROP INDEX `account_state` ON `User`');
    $database->exec('ALTER TABLE `User` ENGINE=MyISAM');
    rejected(fn() => $runner->migrateTo(), 'A non-atomic table engine is rejected.');
    resetSchema($database);
    $database->exec('CREATE VIEW `ChronosMigrationView` AS SELECT userId,alive FROM `User`');
    rejected(fn() => $runner->migrateTo(), 'A custom view using the old column is not silently broken by a rename.');
    ensure(column($database, 'alive'), 'A dependent view refusal leaves the original column intact.');
    ensure($runner->migrateTo('0')['clean'], 'A dependency failure can be safely unwound to the untouched baseline.');
    $database->exec('DROP VIEW `ChronosMigrationView`');
    ensure($runner->migrateTo()['clean'], 'Migration succeeds after unsupported custom dependencies are removed.');
    resetSchema($database);
    $database->exec('ALTER TABLE `User` ADD COLUMN `derived_status` tinyint GENERATED ALWAYS AS (`alive` + 0)');
    rejected(fn() => $runner->migrateTo(), 'A generated expression using the old column requires explicit adaptation.');
    ensure(column($database, 'alive'), 'A generated dependency refusal preserves the old schema.');

    // Named locks serialize independent connections and survive implicit DDL commits.
    resetSchema($database);
    $other = database();
    $lockName = 'chronos:migrations:' . substr(hash('sha256', 'chronos_migration_test'), 0, 40);
    $statement = $other->prepare('SELECT GET_LOCK(?,0)');
    $statement->execute([$lockName]);
    ensure((int) $statement->fetchColumn() === 1, 'A separate connection can hold the fixture migration lock.');
    try {
        rejected(fn() => (new MigrationRunner($database, lockTimeout: 0))->migrateTo(), 'Competing migrations cannot acquire the same schema lock.');
        ensure(!column($database, 'enabled'), 'Lock contention cannot alter the account schema.');
    } finally {
        $statement = $other->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$lockName]);
    }
    $runner->migrateTo();
    $runner->migrateTo('0');
    $other->exec('LOCK TABLES `ChronosMigration` WRITE');
    $ledgerTimeout = $database->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
    try {
        foreach (['status', 'migrateTo'] as $operation) {
            try {
                $runner->$operation();
                throw new RuntimeException('Expected the held ledger metadata lock to time out.');
            } catch (PDOException $error) {
                ensure((int) ($error->errorInfo[1] ?? 0) === 1205, 'The initial ledger inspection is bounded even before migration DDL.');
            }
        }
        ensure($database->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn() === $ledgerTimeout, 'An initial ledger timeout restores the original session setting.');
    } finally {
        $other->exec('UNLOCK TABLES');
    }
    $other->beginTransaction();
    $other->query('SELECT * FROM `User`')->fetchAll();
    $metadataTimeout = $database->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
    try {
        try {
            $runner->migrateTo();
            throw new RuntimeException('Expected the held metadata lock to time out.');
        } catch (PDOException $error) {
            ensure((int) ($error->errorInfo[1] ?? 0) === 1205, 'A held application metadata lock causes a bounded MySQL DDL timeout.');
        }
        ensure(column($database, 'alive'), 'Metadata contention leaves the source column intact.');
        ensure($database->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn() === $metadataTimeout, 'A DDL failure restores the original metadata timeout.');
    } finally {
        $other->rollBack();
    }
    ensure(!$runner->status()['clean'], 'A timed-out DDL statement remains durably resumable.');
    ensure($runner->migrateTo()['clean'], 'The migration resumes after the blocking application transaction finishes.');
    $runner->migrateTo('0');
    $database->beginTransaction();
    try {
        rejected(fn() => $runner->migrateTo(), 'An open application transaction cannot be implicitly committed.');
        ensure($database->inTransaction(), 'Rejecting a migration leaves the application transaction open.');
    } finally {
        $database->rollBack();
    }
    $runner->migrateTo();
    ensure((int) $other->query("SELECT IS_FREE_LOCK('" . $lockName . "')")->fetchColumn() === 1, 'The migration lock is released after a verified upgrade.');

    $outerLease = $runner->lease();
    $nestedLease = $runner->lease();
    $runner->status();
    $runner->migrateTo('0');
    $runner->migrateTo();
    rejected(fn() => (new MigrationRunner($other, lockTimeout: 0))->lease(), 'An outer deployment lease survives nested status and DDL calls.');
    $outerLease();
    $outerLease();
    rejected(fn() => (new MigrationRunner($other, lockTimeout: 0))->status(), 'Releasing one nested lease twice cannot release another lease.');
    $nestedLease();
    ensure((int) $other->query("SELECT IS_FREE_LOCK('" . $lockName . "')")->fetchColumn() === 1, 'Balanced idempotent lease releases make the schema available to another connection.');

    resetSchema($database);
    $database->exec('SET SESSION autocommit = 0');
    try {
        ensure(!$database->inTransaction(), 'Disabling autocommit alone has not opened a PDO transaction.');
        rejected(fn() => $runner->migrateTo(), 'Autocommit zero is rejected before starting implicit-commit DDL.');
        rejected(fn() => $runner->lease(), 'A deployment lease cannot hide a non-autocommit session.');
        ensure(!column($database, 'enabled') && $database->query("SHOW TABLES LIKE 'ChronosMigration'")->fetchColumn() === false, 'The autocommit refusal leaves both application and journal schemas untouched.');
    } finally {
        $database->exec('SET SESSION autocommit = 1');
    }
    $runner->migrateTo();
    $database->exec('ALTER TABLE `User` ALTER COLUMN `enabled` SET DEFAULT 0');
    rejected(fn() => $runner->status(), 'An exception still releases the migration lock.');
    ensure((int) $other->query("SELECT IS_FREE_LOCK('" . $lockName . "')")->fetchColumn() === 1, 'The migration lock is released after errors.');

    // Fresh and existing databases must traverse the same real version boundaries.
    // Importing an arbitrary latest snapshot would poison migration 1's historical
    // ordinal/index context and make a later migration's reversal fail.
    $baselineSql = file_get_contents(dirname(__DIR__, 2) . '/resources/chronos.sql');
    ensure(is_string($baselineSql) && str_contains($baselineSql, '-- Immutable schema baseline 0.')
        && str_contains($baselineSql, '`alive` tinyint') && !str_contains($baselineSql, '`enabled` tinyint'), 'The packaged SQL is the immutable baseline, rather than a current-schema snapshot.');
    foreach (['fresh-install', 'existing-install'] as $origin) {
        clearSchema($database);
        $database->exec($baselineSql);
        $database->exec('SET SESSION autocommit=1');
        $originalContext = (new UserEnabledMigration())->capture($database);
        if ($origin === 'existing-install') {
            baselineUser($database, 1, 'alive', 1);
            baselineUser($database, 2, 'alive', 0);
        }
        $originalRecords = accountRecords($database, 'alive');
        $shaped = new MigrationRunner($database, [new UserEnabledMigration(), new UserShapeFixtureMigration()], 1);
        ensure($shaped->migrateTo()['version'] === '202610180002_fixture_user_shape', 'The full catalog applies from the genuine baseline: ' . $origin);
        $recorded = $database->query("SELECT context FROM `ChronosMigration` WHERE version='" . UserEnabledMigration::VERSION . "'")->fetchColumn();
        ensure(json_decode($recorded, true, 64, JSON_THROW_ON_ERROR) === $originalContext, 'Migration 1 records its original column position and index set: ' . $origin);
        $changedContext = (new UserEnabledMigration())->capture($database);
        ensure((int) $changedContext['column']['attributes']['ORDINAL_POSITION'] === (int) $originalContext['column']['attributes']['ORDINAL_POSITION'] + 1
            && count($changedContext['column']['indexes']) === count($originalContext['column']['indexes']) + 2, 'Migration 2 really changes the earlier column ordinal and affected index definitions: ' . $origin);
        ensure(accountRecords($database, 'enabled') === $originalRecords, 'Applying both migrations preserves all original account columns and values: ' . $origin);
        if ($origin === 'fresh-install') {
            baselineUser($database, 1, 'enabled', 1);
            baselineUser($database, 2, 'enabled', 0);
        }
        $database->exec("UPDATE `User` SET enabled=0,firstname='Edited after update' WHERE userId=1");
        baselineUser($database, 3, 'enabled', 1);
        $editedRecords = accountRecords($database, 'enabled');
        ensure($shaped->migrateTo(UserEnabledMigration::VERSION)['version'] === UserEnabledMigration::VERSION
            && (new UserEnabledMigration())->capture($database) === $originalContext, 'Reversing migration 2 restores the exact historical migration 1 boundary: ' . $origin);
        (new MigrationRunner($database, [new UserEnabledMigration()]))->assertCurrent();
        ensure(accountRecords($database, 'enabled') === $editedRecords, 'Intermediate rollback preserves accounts created or edited after both migrations: ' . $origin);
        ensure($shaped->migrateTo('0')['version'] === '0' && column($database, 'alive'), 'The original baseline can still be reached after an intermediate rollback: ' . $origin);
        ensure(accountRecords($database, 'alive') === $editedRecords, 'The complete inverse chain preserves every retained account value: ' . $origin);
        $shaped->migrateTo();
        ensure($shaped->migrateTo('0')['version'] === '0' && accountRecords($database, 'alive') === $editedRecords, 'Reapplication and direct two-step rollback also preserve all account data: ' . $origin);

        $shaped->migrateTo();
        $database->exec('DROP TABLE `ChronosMigration`');
        $untrackedRecords = accountRecords($database, 'enabled');
        rejected(fn() => $shaped->status(), 'An advanced snapshot cannot manufacture earlier history even when the later schema is valid: ' . $origin);
        rejected(fn() => $shaped->migrateTo(), 'The full catalog refuses adoption of an arbitrary latest snapshot: ' . $origin);
        rejected(fn() => $shaped->migrateTo('0'), 'The full catalog cannot guess missing historical inverse contexts: ' . $origin);
        ensure(accountRecords($database, 'enabled') === $untrackedRecords && column($database, 'fixture_before_enabled')
            && $database->query("SHOW TABLES LIKE 'ChronosMigration'")->fetchColumn() === false, 'Refusing an untracked latest snapshot changes neither its advanced schema nor its account data: ' . $origin);
    }

    echo $assertions . ' database migration assertions passed on PHP ' . PHP_VERSION . ".\n";
} finally {
    clearSchema($database);
}
