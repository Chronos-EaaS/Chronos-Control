<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/application-autoload.php';

use Chronos\Infrastructure\Persistence\Migrations\Migration;
use Chronos\Infrastructure\Persistence\Migrations\MigrationCatalog;
use Chronos\Infrastructure\Persistence\Migrations\MigrationException;
use Chronos\Infrastructure\Persistence\Migrations\MigrationRunner;
use Chronos\Infrastructure\Persistence\Migrations\ResourceEventTimelineIndexMigration;
use Chronos\Infrastructure\Persistence\Migrations\SystemMaintainersMigration;

// One fixed disposable MySQL database; never load application configuration.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run' || getenv('CHRONOS_TEST_MIGRATIONS') !== '1' || !is_file('/.dockerenv')) {
    throw new RuntimeException('Run only in the explicitly enabled isolated migration fixture.');
}
const MAINTAINER_DSN = 'mysql:host=chronos-migration-db;dbname=chronos_migration_test;charset=utf8mb4';
$database = new PDO(MAINTAINER_DSN, 'chronos_migration', 'chronos_migration_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($database->query('SELECT DATABASE()')->fetchColumn() !== 'chronos_migration_test'
    || !str_starts_with((string) $database->query('SELECT CURRENT_USER()')->fetchColumn(), 'chronos_migration@')) {
    throw new RuntimeException('Refusing to change an unexpected database or account.');
}
$checks = 0;
function checkMaintainer(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
function refuseMaintainer(callable $operation, string $message): void
{
    try { $operation(); }
    catch (MigrationException) { checkMaintainer(true, $message); return; }
    throw new RuntimeException('Expected maintainer migration refusal: ' . $message);
}
function clearMaintainers(PDO $database): void
{
    $objects = $database->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach (['VIEW', 'BASE TABLE'] as $kind) foreach ($objects as [$name, $type]) {
        if ($type !== $kind) continue;
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) throw new RuntimeException('Unexpected fixture object.');
        $database->exec(($kind === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . '`' . $name . '`');
    }
}
function resetMaintainers(PDO $database, bool $seed = true): MigrationRunner
{
    clearMaintainers($database);
    $database->exec(file_get_contents(dirname(__DIR__, 2) . '/resources/chronos.sql'));
    $database->exec('SET SESSION autocommit=1');
    if ($seed) {
        $database->exec("INSERT INTO `User` (userId,username,password,email,lastname,firstname,gender,role,alive,activated,created) VALUES (1,'owner','synthetic','owner@example.test','Fixture','Owner',0,0,1,1,'2026-10-01 00:00:00'),(2,'disabled','synthetic','disabled@example.test','Fixture','Disabled',0,0,0,1,'2026-10-01 00:00:00'),(3,'additional','synthetic','additional@example.test','Fixture','Additional',0,0,1,1,'2026-10-01 00:00:00')");
        $statement = $database->prepare("INSERT INTO `System` (systemId,name,description,userId,vcsUrl,vcsBranch,vcsType,vcsUser,vcsPassword,created,lastEdit,isArchived,cem) VALUES (?,'Synthetic system','Unchanged',?,'','master','git','','','2026-10-01 00:00:00','2026-10-01 00:00:00',0,0)");
        foreach ([1 => 1, 2 => 2, 3 => 99] as $systemId => $userId) $statement->execute([$systemId, $userId]);
    }
    $runner = new MigrationRunner($database);
    $runner->migrateTo(ResourceEventTimelineIndexMigration::VERSION);
    return $runner;
}
function maintainerRows(PDO $database): array
{
    return $database->query('SELECT systemId,userId FROM `SystemMaintainer` ORDER BY systemId,userId')->fetchAll(PDO::FETCH_NUM);
}

final class InterruptedMaintainerPdo extends PDO
{
    public function __construct(public readonly string $fault)
    {
        parent::__construct(MAINTAINER_DSN, 'chronos_migration', 'chronos_migration_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    public function exec(string $statement): int|false
    {
        if ($this->fault === 'after-create' && str_starts_with($statement, 'CREATE TABLE `SystemMaintainer`')) {
            parent::exec($statement);
            throw new RuntimeException('Injected maintainer interruption');
        }
        return parent::exec($statement);
    }
}
final class InterruptedMaintainerMigration implements Migration
{
    public function __construct(private readonly string $fault) {}
    public function version(): string { return SystemMaintainersMigration::VERSION; }
    public function capture(PDO $database): array { return (new SystemMaintainersMigration())->capture($database); }
    public function validateContext(array $context): void { (new SystemMaintainersMigration())->validateContext($context); }
    public function assertState(PDO $database, array $context, bool $applied): void { (new SystemMaintainersMigration())->assertState($database, $context, $applied); }
    public function apply(PDO $database, array $context): void
    {
        if ($this->fault === 'before-up') throw new RuntimeException('Injected maintainer interruption');
        (new SystemMaintainersMigration())->apply($database, $context);
        if ($this->fault === 'after-up') throw new RuntimeException('Injected maintainer interruption');
    }
    public function revert(PDO $database, array $context): void
    {
        if ($this->fault === 'before-down') throw new RuntimeException('Injected maintainer interruption');
        (new SystemMaintainersMigration())->revert($database, $context);
        if ($this->fault === 'after-down') throw new RuntimeException('Injected maintainer interruption');
    }
}

try {
    $runner = resetMaintainers($database);
    $systems = $database->query('SELECT * FROM `System` ORDER BY systemId')->fetchAll(PDO::FETCH_ASSOC);
    $runner->migrateTo();
    checkMaintainer(maintainerRows($database) === [[1, 1], [2, 2]], 'Every valid owner, including disabled owners, is associated; dangling owners are omitted.');
    checkMaintainer($database->query('SELECT * FROM `System` ORDER BY systemId')->fetchAll(PDO::FETCH_ASSOC) === $systems, 'Migration preserves all system data and designated owners.');
    $context = $database->query("SELECT context FROM `ChronosMigration` WHERE version='" . SystemMaintainersMigration::VERSION . "'")->fetchColumn();
    $migration = new SystemMaintainersMigration();
    $captured = $migration->capture($database);
    $database->exec('INSERT INTO `SystemMaintainer` VALUES (1,3)');
    checkMaintainer($migration->capture($database) === $captured, 'Recovery capture is independent of mutable membership contents.');
    $runner->assertCurrent();
    $runner->migrateTo();
    checkMaintainer(maintainerRows($database) === [[1, 1], [1, 3], [2, 2]], 'Repeated migration leaves additional memberships intact.');
    checkMaintainer($database->query("SELECT context FROM `ChronosMigration` WHERE version='" . SystemMaintainersMigration::VERSION . "'")->fetchColumn() === $context, 'Status and membership changes never rewrite the immutable schema recovery context.');
    refuseMaintainer(fn() => $runner->migrateTo(ResourceEventTimelineIndexMigration::VERSION), 'Rollback refuses to discard additional maintainers.');
    checkMaintainer(maintainerRows($database) === [[1, 1], [1, 3], [2, 2]], 'A refused downgrade retains every association.');
    checkMaintainer((int) $database->query('SELECT @@SESSION.autocommit')->fetchColumn() === 1, 'Refused table-lock downgrade restores the session boundary.');
    $database->exec('DELETE FROM `SystemMaintainer` WHERE systemId=1 AND userId=3');
    $runner->migrateTo(ResourceEventTimelineIndexMigration::VERSION);
    checkMaintainer($database->query("SHOW TABLES LIKE 'SystemMaintainer'")->fetchColumn() === false, 'Owner-only memberships safely revert to the prior schema.');
    $runner->migrateTo();
    $database->exec('UPDATE `System` SET userId=3 WHERE systemId=1');
    $database->exec('DELETE FROM `SystemMaintainer` WHERE systemId=1');
    $database->exec('INSERT INTO `SystemMaintainer` VALUES (1,3)');
    $runner->migrateTo(ResourceEventTimelineIndexMigration::VERSION);
    checkMaintainer((int) $database->query('SELECT userId FROM `System` WHERE systemId=1')->fetchColumn() === 3, 'Rollback preserves a designated owner transferred after migration.');

    $runner = resetMaintainers($database);
    $migration->apply($database, $migration->capture($database));
    $database->exec('INSERT INTO `SystemMaintainer` VALUES (1,3)');
    $database->exec('DELETE FROM `SystemMaintainer` WHERE systemId=2');
    $runner->migrateTo();
    checkMaintainer(maintainerRows($database) === [[1, 1], [1, 3], [2, 2]], 'Adopting a compatible preexisting table backfills missing owners without losing members.');
    $runner->migrateTo(ResourceEventTimelineIndexMigration::VERSION);
    checkMaintainer(maintainerRows($database) === [[1, 1], [1, 3], [2, 2]], 'Downgrade preserves a compatible table that existed before this migration.');

    foreach (['systemId INT NOT NULL,userId INT NOT NULL', 'systemId INT UNSIGNED NOT NULL,userId INT NOT NULL,PRIMARY KEY(systemId,userId)', 'systemId INT NOT NULL,userId INT NOT NULL,PRIMARY KEY(userId,systemId)'] as $definition) {
        $runner = resetMaintainers($database);
        $database->exec('CREATE TABLE `SystemMaintainer` (' . $definition . ') ENGINE=InnoDB');
        refuseMaintainer(fn() => $runner->migrateTo(), 'A conflicting table definition cannot be adopted or overwritten.');
        checkMaintainer($runner->status()['version'] === ResourceEventTimelineIndexMigration::VERSION, 'A preexisting schema collision records no new migration.');
    }
    $runner = resetMaintainers($database, false);
    $runner->migrateTo();
    checkMaintainer(maintainerRows($database) === [], 'A genuine empty installer baseline reaches the latest schema.');
    $runner->migrateTo('0');

    foreach (['before-up', 'after-create', 'after-up', 'before-down', 'after-down'] as $fault) {
        $runner = resetMaintainers($database);
        $target = str_ends_with($fault, '-down') ? ResourceEventTimelineIndexMigration::VERSION : SystemMaintainersMigration::VERSION;
        if (str_ends_with($fault, '-down')) $runner->migrateTo();
        $catalog = MigrationCatalog::migrations(); $checksums = [];
        foreach ($catalog as $position => $item) {
            $source = new ReflectionClass($item);
            $name = $item instanceof SystemMaintainersMigration ? 'InterruptedMaintainerMigration' : $source->getShortName();
            $checksums[$name] = hash_file('sha256', $source->getFileName());
            if ($item instanceof SystemMaintainersMigration) $catalog[$position] = new InterruptedMaintainerMigration($fault);
        }
        $faultDatabase = new InterruptedMaintainerPdo($fault);
        $interrupted = new MigrationRunner($faultDatabase, $catalog, 1, $checksums);
        try { $interrupted->migrateTo($target); throw new RuntimeException('The intended interruption did not happen.'); }
        catch (RuntimeException $error) { checkMaintainer($error->getMessage() === 'Injected maintainer interruption', 'The intended interruption reaches its exact boundary.'); }
        checkMaintainer(!$runner->status()['clean'], 'Read-only status exposes incomplete work without mutating membership data.');
        checkMaintainer($runner->migrateTo($target)['version'] === $target, 'Original migration source recovers a checksum-matched interruption: ' . $fault);
        if ($target === SystemMaintainersMigration::VERSION) checkMaintainer(maintainerRows($database) === [[1, 1], [2, 2]], 'Recovery completes owner backfill exactly once: ' . $fault);
        unset($interrupted, $faultDatabase);
    }
    $runner = resetMaintainers($database);
    $runner->migrateTo();
    $database->exec('ALTER TABLE `SystemMaintainer` ALTER INDEX `chronos_system_maintainer_user` INVISIBLE');
    refuseMaintainer(fn() => $runner->assertCurrent(), 'Applied membership-key drift fails closed.');
    refuseMaintainer(fn() => $runner->migrateTo(ResourceEventTimelineIndexMigration::VERSION), 'Rollback cannot erase a table whose schema has drifted.');
    echo $checks . ' system maintainer migration assertions passed on PHP ' . PHP_VERSION . ".\n";
} finally {
    $database->exec('UNLOCK TABLES');
    clearMaintainers($database);
}
