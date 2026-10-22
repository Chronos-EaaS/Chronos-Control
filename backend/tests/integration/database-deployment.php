<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/application-autoload.php';

use Chronos\Infrastructure\Deployment\ApplicationLock;
use Chronos\Infrastructure\Deployment\DatabaseDeployment;
use Chronos\Infrastructure\Deployment\ReleaseManager;
use Chronos\Infrastructure\Persistence\Migrations\MigrationCatalog;
use Chronos\Infrastructure\Persistence\Migrations\MigrationRunner;
use Chronos\Infrastructure\Persistence\Migrations\UserEnabledMigration;

// Destructive by design: one container-only, explicitly enabled, fixed synthetic database.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run'
    || getenv('CHRONOS_TEST_MIGRATIONS') !== '1' || !is_file('/.dockerenv')) {
    throw new RuntimeException('Run only in the explicitly enabled isolated migration container.');
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
function rejected(callable $operation, string $fragment): Throwable
{
    try { $operation(); }
    catch (Throwable $error) {
        ensure(str_contains($error->getMessage(), $fragment), 'The intended operation must fail: ' . $error->getMessage());
        return $error;
    }
    throw new RuntimeException('Expected deployment refusal containing: ' . $fragment);
}
function resetSchema(PDO $database): void
{
    $database->exec('DROP TABLE IF EXISTS `Event`');
    $database->exec('DROP TABLE IF EXISTS `LiveChange`');
    $database->exec('DROP TABLE IF EXISTS `SystemMaintainer`');
    $database->exec('DROP TABLE IF EXISTS `System`');
    $database->exec('DROP VIEW IF EXISTS `ChronosMigrationView`');
    $database->exec('DROP TABLE IF EXISTS `ChronosMigration`');
    $database->exec('DROP TABLE IF EXISTS `User`');
    $database->exec("CREATE TABLE `User` (`userId` int NOT NULL PRIMARY KEY,`username` varchar(40) NOT NULL,`alive` tinyint unsigned DEFAULT '1' COMMENT 'Synthetic account flag',KEY `account_state` (`alive` DESC,`userId`)) ENGINE=InnoDB");
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
function application(string $base, string $name): string
{
    $root = $base . '/' . $name;
    ensure(mkdir($root, 0700) && mkdir($root . '/webroot', 0700), 'Cannot create the private synthetic installation.');
    file_put_contents($root . '/webroot/index.php', '<?php echo "old installation";');
    file_put_contents($root . '/config.php', '<?php /* Synthetic configuration: trusted PDO callback only. */');
    mkdir($root . '/webroot/data', 0700);
    file_put_contents($root . '/webroot/data/result.bin', 'retained evaluation result');
    return $root;
}
function package(string $base, string $bundle): array
{
    $commit = str_repeat('a', 40);
    $files = [
        'webroot/index.php' => '<?php echo "new installation";',
        'webroot/ui/index.html' => '<app-root></app-root>',
        'webroot/ui/build.json' => json_encode(['id' => $commit], JSON_THROW_ON_ERROR),
        'webroot/ui/main-A_aAaAaA.js' => 'console.log("synthetic application");',
        'database.php' => $bundle,
    ];
    foreach (['constants.php', 'chronos.sql', 'config.template.php', 'vendor/autoload.php', 'vendor/composer/autoload_real.php',
        'api/ui/bootstrap.php', 'app/Http/Api/Ui/Kernel.php', 'app/Application/Shared/Context.php',
        'app/Infrastructure/Persistence/Store.php', 'app/Infrastructure/Deployment/ApplicationLock.php',
        'app/Infrastructure/Deployment/DatabaseDeployment.php'] as $required) $files[$required] = '<?php /* Synthetic package file. */';
    $manifest = ['format' => 1, 'runtimeLayout' => 'application-v1', 'uiApiPath' => '/api/ui',
        'databaseMigrationFormat' => 1, 'databaseVersion' => MigrationCatalog::latestVersion(),
        'commit' => $commit, 'uiBuildId' => $commit, 'files' => [], 'removedFiles' => []];
    $archive = $base . '/synthetic-release.zip';
    $zip = new ZipArchive();
    ensure($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Cannot create the synthetic deployment package.');
    foreach ($files as $name => $contents) {
        ensure($zip->addFromString($name, $contents), 'Cannot add a synthetic package file.');
        $manifest['files'][$name] = ['bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
    }
    ensure($zip->close(), 'Cannot finish the synthetic package.');
    $manifest['archive'] = ['bytes' => filesize($archive), 'sha256' => hash_file('sha256', $archive)];
    return [$archive, $manifest];
}
function journal(string $root): array
{
    $paths = glob($root . '/.chronos-deployments/*/journal.json');
    ensure(is_array($paths) && count($paths) === 1, 'Exactly one private deployment journal is expected.');
    $journal = json_decode(file_get_contents($paths[0]), true, 64, JSON_THROW_ON_ERROR);
    ensure(is_array($journal) && preg_match('/^[a-f0-9]{32}$/D', $journal['id'] ?? '') === 1, 'The deployment journal has a safe identity.');
    return [$paths[0], $journal];
}
function saveJournal(string $path, array $journal): void
{
    file_put_contents($path, json_encode($journal, JSON_THROW_ON_ERROR));
    chmod($path, 0600);
}
function oldInstallation(string $root): void
{
    ensure(file_get_contents($root . '/webroot/index.php') === '<?php echo "old installation";', 'The previous application entry point is restored.');
    ensure(!is_file($root . '/.chronos-release.json') && !is_file($root . '/database.php'), 'Files introduced by the new release are removed.');
    ensure(!is_file($root . '/.chronos-maintenance.json'), 'Verified recovery clears maintenance.');
    ensure(file_get_contents($root . '/webroot/data/result.bin') === 'retained evaluation result', 'Persistent results survive deployment recovery.');
    ensure(file_get_contents($root . '/config.php') === '<?php /* Synthetic configuration: trusted PDO callback only. */', 'Deployment recovery retains private configuration.');
}
function remove(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path) as $entry) remove($entry->getPathname());
    rmdir($path);
}

$base = sys_get_temp_dir() . '/chronos-database-deployment-' . bin2hex(random_bytes(12));
ensure(mkdir($base, 0700), 'Cannot create the isolated deployment workspace.');
$connect = static fn(): PDO => $database;
$other = database();
$lockName = 'chronos:migrations:' . substr(hash('sha256', 'chronos_migration_test'), 0, 40);
try {
    ensure(is_file('/opt/chronos/database.php') && !is_link('/opt/chronos/database.php'), 'The test image must contain its freshly assembled migration runtime.');
    $bundle = file_get_contents('/opt/chronos/database.php');
    [$archive, $manifest] = package($base, $bundle);

    // The real target bundle runs even though the old process loaded normal migration classes.
    resetSchema($database);
    $root = application($base, 'upgrade-and-rollback');
    $before = users($database, 'alive');
    $shape = (new UserEnabledMigration())->capture($database);
    $leaseObserved = false;
    $result = ReleaseManager::installArchive($root, $archive, $manifest, 'synthetic-branch', null,
        static function () use ($database, $other, &$leaseObserved): void {
            if ($leaseObserved) return;
            rejected(fn() => (new MigrationRunner($other, lockTimeout: 0))->lease(), 'Another database migration');
            ensure(column($database, 'alive'), 'The database lease is already held before application files are written.');
            $leaseObserved = true;
        }, null, $connect);
    ensure($leaseObserved && $result['success'] && $result['databaseVersion'] === MigrationCatalog::latestVersion(), 'Package activation reaches its declared database version.');
    ensure(column($database, 'enabled') && !column($database, 'alive') && users($database, 'enabled') === $before, 'A package update physically renames the account column without changing any values.');
    ensure((new UserEnabledMigration())->capture($database) === $shape, 'Package migration retains column attributes and composite index order.');
    ensure($database->query("SHOW INDEX FROM `Event` WHERE Key_name='chronos_event_timeline'")->rowCount() === 2, 'The packaged target runtime creates the event timeline index during self-update.');
    ensure($database->query("SHOW INDEX FROM `Event` WHERE Key_name='chronos_event_node_timeline'")->rowCount() === 3, 'The packaged target runtime creates the scoped node timeline index during self-update.');
    ensure($database->query("SHOW INDEX FROM `Event` WHERE Key_name='chronos_event_resource_timeline'")->rowCount() === 4, 'The packaged target runtime creates the covering resource timeline index during self-update.');
    ensure($database->query("SHOW TABLES LIKE 'SystemMaintainer'")->fetchColumn() === 'SystemMaintainer', 'The packaged target runtime installs the system-maintainer schema.');
    [$path, $saved] = journal($root);
    ensure($saved['databasePrepared'] === true && $saved['database'] === ['before' => '0', 'after' => MigrationCatalog::latestVersion(), 'bundleSha256' => hash('sha256', $bundle)], 'Private deployment history binds both schema boundaries to the exact retained runtime.');
    ensure(!str_contains(file_get_contents($path), 'chronos_migration_only'), 'Deployment journals never contain database credentials.');
    ensure(!is_file($root . '/.chronos-maintenance.json') && file_get_contents($root . '/webroot/index.php') === '<?php echo "new installation";', 'Successful database activation publishes the new application and clears maintenance.');
    ensure((int) $other->query("SELECT IS_FREE_LOCK('" . $lockName . "')")->fetchColumn() === 1, 'A complete package update releases its database lease.');
    $database->exec("UPDATE `User` SET enabled=0 WHERE userId=1");
    $database->exec("INSERT INTO `User` VALUES (4,'created after upgrade',1)");
    $updated = users($database, 'enabled');
    $rollback = ReleaseManager::rollback($root, $saved['id'], $manifest['commit'], $connect);
    ensure($rollback['success'] && column($database, 'alive') && !column($database, 'enabled'), 'Package rollback reverses the schema before restoring its previous application.');
    ensure(users($database, 'alive') === $updated && (new UserEnabledMigration())->capture($database) === $shape, 'Inverse migration preserves accounts created or edited after the upgrade.');
    ensure($database->query("SHOW INDEX FROM `Event` WHERE Key_name='chronos_event_timeline'")->fetch() === false, 'Package rollback removes only the event timeline index introduced by this update.');
    ensure($database->query("SHOW INDEX FROM `Event` WHERE Key_name='chronos_event_node_timeline'")->fetch() === false, 'Package rollback removes the scoped index introduced by this update.');
    ensure($database->query("SHOW INDEX FROM `Event` WHERE Key_name='chronos_event_resource_timeline'")->fetch() === false, 'Package rollback removes the covering resource index introduced by this update.');
    oldInstallation($root);

    resetSchema($database);
    $root = application($base, 'file-write-failure');
    $before = users($database, 'alive');
    $writes = 0;
    rejected(static function () use ($root, $archive, $manifest, $connect, &$writes): void {
        ReleaseManager::installArchive($root, $archive, $manifest, 'synthetic-branch', null,
            static function () use (&$writes): void { if (++$writes === 2) throw new RuntimeException('Injected file write failure.'); }, null, $connect);
    }, 'Injected file write failure');
    ensure($writes === 2 && column($database, 'alive') && users($database, 'alive') === $before, 'A file activation failure restores the untouched database baseline.');
    oldInstallation($root);

    // A final manifest write fails after actual DDL. Observe that boundary, then verify inverse recovery.
    resetSchema($database);
    $root = application($base, 'failure-after-ddl');
    $sawAppliedSchema = false;
    $postDdlRows = null;
    set_error_handler(static function (int $severity, string $message) use ($root, $database, &$sawAppliedSchema, &$postDdlRows): bool {
        if ($severity === E_WARNING && str_contains($message, 'rename(') && str_contains($message, '/.chronos-release.json')) {
            $sawAppliedSchema = column($database, 'enabled') && !column($database, 'alive');
            $database->exec("INSERT INTO `User` VALUES (4,'retained during recovery',0)");
            $postDdlRows = users($database, 'enabled');
            return true;
        }
        if (!(error_reporting() & $severity)) return false;
        throw new ErrorException($message, 0, $severity);
    });
    try {
        rejected(fn() => ReleaseManager::installArchive($root, $archive, $manifest, 'synthetic-branch', null,
            static function (string $path) use ($root): void { if ($path === 'webroot/index.php') mkdir($root . '/.chronos-release.json', 0700); }, null, $connect), 'Deployment state could not be saved');
    } finally { restore_error_handler(); }
    ensure($sawAppliedSchema && is_array($postDdlRows) && column($database, 'alive') && users($database, 'alive') === $postDdlRows, 'Automatic recovery reverses actual completed DDL while retaining all account data.');
    rmdir($root . '/.chronos-release.json'); // Remove only the explicitly injected filesystem obstruction.
    oldInstallation($root);
    [, $saved] = journal($root);
    ensure($saved['status'] === 'recovered', 'A failed final publication is recorded as recovered, not applied.');

    // Incompatible source databases must fail before any application file is activated.
    foreach (['invalid-column', 'future-history'] as $scenario) {
        resetSchema($database);
        $root = application($base, $scenario);
        if ($scenario === 'invalid-column') {
            $database->exec('ALTER TABLE `User` MODIFY `alive` int DEFAULT 1');
            $physical = 'alive';
            $fragment = 'stored boolean tinyint';
        } else {
            (new MigrationRunner($database))->migrateTo();
            $database->exec("UPDATE `ChronosMigration` SET version='209901010001_future' WHERE version='" . MigrationCatalog::latestVersion() . "'");
            $physical = 'enabled';
            $fragment = 'migration';
        }
        $before = users($database, $physical);
        $writes = 0;
        rejected(static function () use ($root, $archive, $manifest, $connect, &$writes): void {
            ReleaseManager::installArchive($root, $archive, $manifest, 'synthetic-branch', null,
                static function () use (&$writes): void { ++$writes; }, null, $connect);
        }, $fragment);
        ensure($writes === 0 && users($database, $physical) === $before && column($database, $physical), 'Incompatible history or schema cannot activate files or change accounts.');
        oldInstallation($root);
    }

    // A real migration refusal after file activation must unwind to the previous database and files.
    resetSchema($database);
    $root = application($base, 'dependent-view-failure');
    $database->exec('CREATE VIEW `ChronosMigrationView` AS SELECT userId,alive FROM `User`');
    $before = users($database, 'alive');
    rejected(fn() => ReleaseManager::installArchive($root, $archive, $manifest, 'synthetic-branch', null, null, null, $connect), 'custom views or expressions');
    ensure(column($database, 'alive') && users($database, 'alive') === $before && (new MigrationRunner($database))->status()['clean'], 'A failed migration safely returns to its verified previous schema boundary.');
    ensure((int) $database->query('SELECT COUNT(*) FROM `ChronosMigrationView`')->fetchColumn() === 3, 'A rejected dependency remains valid and unchanged.');
    oldInstallation($root);

    // A corrupted retained executable cannot reverse a database or restore incompatible old files.
    resetSchema($database);
    $root = application($base, 'corrupt-retained-bundle');
    ReleaseManager::installArchive($root, $archive, $manifest, 'synthetic-branch', null, null, null, $connect);
    [, $saved] = journal($root);
    $retained = $root . '/.chronos-deployments/' . $saved['id'] . '/stage/database.php';
    file_put_contents($retained, $bundle . "\n// Injected checksum mismatch.\n");
    $before = users($database, 'enabled');
    rejected(fn() => ReleaseManager::rollback($root, $saved['id'], $manifest['commit'], $connect), 'integrity check');
    ensure(is_file($root . '/.chronos-maintenance.json') && column($database, 'enabled') && users($database, 'enabled') === $before, 'Unverified inverse code leaves maintenance active and current data untouched.');
    ensure(file_get_contents($root . '/webroot/index.php') === '<?php echo "new installation";', 'Failed inverse validation never restores the old application.');
    file_put_contents($retained, $bundle);
    ensure(ReleaseManager::recover($root, $saved['id'], $connect)['success'], 'Restoring the exact retained executable permits verified recovery.');
    oldInstallation($root);

    resetSchema($database);
    $root = application($base, 'missing-database-journal-key');
    ReleaseManager::installArchive($root, $archive, $manifest, 'synthetic-branch', null, null, null, $connect);
    [$path, $saved] = journal($root);
    $before = users($database, 'enabled');
    file_put_contents($root . '/.chronos-maintenance.json', json_encode(['deployment' => $saved['id']], JSON_THROW_ON_ERROR));
    $invalid = $saved;
    unset($invalid['database']);
    saveJournal($path, $invalid);
    rejected(fn() => ReleaseManager::recover($root, $saved['id'], $connect), 'journal is unavailable');
    ensure(is_file($root . '/.chronos-maintenance.json') && column($database, 'enabled') && !column($database, 'alive')
        && users($database, 'enabled') === $before, 'A missing database plan cannot skip inverse migration or clear maintenance.');
    ensure(file_get_contents($root . '/webroot/index.php') === '<?php echo "new installation";', 'Incomplete database history cannot restore incompatible previous application files.');
    saveJournal($path, $saved);
    ensure(ReleaseManager::recover($root, $saved['id'], $connect)['success'], 'Restoring the complete original journal permits verified recovery.');
    oldInstallation($root);

    // Persisted journals represent process death on either side of final file publication or inverse DDL.
    foreach (['prepared-before-manifest', 'prepared-after-manifest', 'applied-before-maintenance-clear', 'inverse-already-completed'] as $point) {
        resetSchema($database);
        $root = application($base, $point);
        ReleaseManager::installArchive($root, $archive, $manifest, 'synthetic-branch', null, null, null, $connect);
        [$path, $saved] = journal($root);
        $database->exec("UPDATE `User` SET enabled=0 WHERE userId=1");
        $database->exec("INSERT INTO `User` VALUES (4,'post-update crash data',1)");
        $before = users($database, 'enabled');
        if ($point === 'inverse-already-completed') {
            DatabaseDeployment::migrate($root, dirname($path) . '/stage/database.php', $saved['database'], false, $connect);
        }
        if ($point !== 'applied-before-maintenance-clear') $saved['status'] = 'prepared';
        saveJournal($path, $saved);
        if ($point === 'prepared-before-manifest') unlink($root . '/.chronos-release.json');
        file_put_contents($root . '/.chronos-maintenance.json', json_encode(['deployment' => $saved['id']], JSON_THROW_ON_ERROR));
        ensure(ReleaseManager::recover($root, $saved['id'], $connect)['success'], 'Recovery accepts its exact persisted deployment boundary: ' . $point);
        ensure(column($database, 'alive') && !column($database, 'enabled') && users($database, 'alive') === $before, 'Interrupted deployment recovery preserves all account data: ' . $point);
        oldInstallation($root);
        ensure(ReleaseManager::recover($root, $saved['id'], $connect)['success'], 'Repeating an already completed recovery is harmless: ' . $point);
        [, $finished] = journal($root);
        ensure($finished['status'] === 'recovered', 'Recovery durably records its completed boundary: ' . $point);
    }
    ensure((int) $other->query("SELECT IS_FREE_LOCK('" . $lockName . "')")->fetchColumn() === 1, 'Success, refusal, rollback and recovery release every database lease.');
    echo $assertions . ' database deployment assertions passed on PHP ' . PHP_VERSION . ".\n";
} finally {
    ApplicationLock::leaveRequest();
    $database->exec('DROP TABLE IF EXISTS `LiveChange`');
    $database->exec('DROP VIEW IF EXISTS `ChronosMigrationView`');
    $database->exec('DROP TABLE IF EXISTS `ChronosMigration`');
    $database->exec('DROP TABLE IF EXISTS `User`');
    remove($base);
}
