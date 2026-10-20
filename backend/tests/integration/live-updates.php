<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/application-autoload.php';
use Chronos\Infrastructure\Persistence\Migrations\LiveUpdatesMigration;
use Chronos\Infrastructure\Persistence\Migrations\MigrationCatalog;
use Chronos\Infrastructure\Persistence\Migrations\MigrationRunner;
use Chronos\Infrastructure\Persistence\Migrations\UserEnabledMigration;
use Chronos\Infrastructure\Realtime\ChangeJournal;
use Chronos\Infrastructure\Realtime\NotifyingPDO;
// The destructive target is fixed, private and independent of application config.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run' || getenv('CHRONOS_TEST_MIGRATIONS') !== '1' || !is_file('/.dockerenv')) {
    throw new RuntimeException('Run only in the explicitly enabled isolated migration fixture.');
}
const DSN = 'mysql:host=chronos-migration-db;dbname=chronos_migration_test;charset=utf8mb4';
$database = new PDO(DSN, 'chronos_migration', 'chronos_migration_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($database->query('SELECT DATABASE()')->fetchColumn() !== 'chronos_migration_test' || !str_starts_with((string) $database->query('SELECT CURRENT_USER()')->fetchColumn(), 'chronos_migration@')) {
    throw new RuntimeException('Refusing to change an unexpected database or account.');
}
$checks = 0;
function checkLive(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function cleanLive(PDO $database): void
{
    foreach ($database->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name, $kind]) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) {
            throw new RuntimeException('Unexpected fixture object.');
        }
        $database->exec(($kind === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . '`' . $name . '`');
    }
}
try {
    cleanLive($database);
    $database->exec('CREATE TABLE User(userId INT PRIMARY KEY,username VARCHAR(40) NOT NULL,alive TINYINT NOT NULL) ENGINE=InnoDB');
    $database->exec("INSERT INTO User VALUES(1,'synthetic owner',1),(2,'synthetic disabled',0)");
    $database->exec('CREATE TABLE Event(eventId INT PRIMARY KEY,time DATETIME NOT NULL,nodeId VARCHAR(64) DEFAULT NULL) ENGINE=InnoDB');
    $database->exec('CREATE TABLE Job(jobId INT PRIMARY KEY AUTO_INCREMENT,progress INT NOT NULL) ENGINE=InnoDB');
    $database->exec('INSERT INTO Job(progress) VALUES(0),(0)');
    $runner = new MigrationRunner($database);
    checkLive($runner->migrateTo()['version'] === MigrationCatalog::latestVersion(), 'Real migration applies the account rename and transactional outbox.');
    $first = new NotifyingPDO(DSN, 'chronos_migration', 'chronos_migration_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $second = new NotifyingPDO(DSN, 'chronos_migration', 'chronos_migration_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $journal = new ChangeJournal($database);
    $first->beginTransaction();
    $first->exec('UPDATE Job SET progress=10 WHERE jobId=1');
    $second->beginTransaction();
    $second->exec('UPDATE Job SET progress=20 WHERE jobId=2');
    $second->commit();
    $later = $journal->drain();
    checkLive(count($later) === 1, 'An uncommitted earlier outbox row stays invisible.');
    $journal->acknowledge(array_column($later, 'id'));
    $first->commit();
    $earlier = $journal->drain();
    checkLive(count($earlier) === 1 && $earlier[0]['id'] < $later[0]['id'], 'A lower ID committed late remains deliverable after acknowledgement of a later ID.');
    $journal->acknowledge(array_column($earlier, 'id'));
    $first->beginTransaction();
    $first->exec('UPDATE Job SET progress=99 WHERE jobId=1');
    $first->rollBack();
    checkLive($journal->drain() === [] && (int) $database->query('SELECT progress FROM Job WHERE jobId=1')->fetchColumn() === 10, 'Rollback preserves both the business row and absence of false notifications.');
    (new ChangeJournal($database))->record('Fixture');
    // Deliberately offset the outbox auto-increment from business IDs.
    $statement = $first->prepare('INSERT INTO Job(progress) VALUES(?)');
    $statement->execute([30]);
    checkLive($first->lastInsertId() === '3' && (int) $database->query('SELECT progress FROM Job WHERE jobId=3')->fetchColumn() === 30, 'Autocommit prepared inserts retain their business lastInsertId after journaling and COMMIT.');
    $first->exec('DELETE FROM Job WHERE jobId=3');
    $pending = $journal->drain();
    checkLive(count($pending) === 3, 'Prepared inserts and exec deletes both journal once without recursion.');
    $journal->acknowledge(array_column($pending, 'id'));
    $runner->migrateTo(UserEnabledMigration::VERSION);
    checkLive($database->query("SHOW TABLES LIKE 'LiveChange'")->fetchColumn() === false, 'Intermediate rollback removes only the new outbox schema.');
    try {
        $first->exec('UPDATE Job SET progress=88 WHERE jobId=1');
        throw new RuntimeException('Expected missing-journal rejection.');
    } catch (PDOException) {
        checkLive((int) $database->query('SELECT progress FROM Job WHERE jobId=1')->fetchColumn() === 10, 'A failed journal INSERT cannot commit an otherwise successful business update.');
    }
    checkLive($runner->migrateTo()['version'] === MigrationCatalog::latestVersion(), 'A rolled-back live-update schema can be applied again with the complete current catalog.');
    $database->exec('ALTER TABLE User ALTER COLUMN enabled SET DEFAULT 1');
    try {
        $runner->assertCurrent();
        throw new RuntimeException('Expected preceding schema drift rejection.');
    } catch (\Chronos\Infrastructure\Persistence\Migrations\MigrationException) {
        checkLive(true, 'The additive live schema retains account schema drift checks.');
    }
    $database->exec('ALTER TABLE User ALTER COLUMN enabled DROP DEFAULT');
    checkLive($runner->migrateTo('0')['version'] === '0', 'Complete rollback restores the original account column.');
    checkLive($database->query('SELECT alive FROM User ORDER BY userId')->fetchAll(PDO::FETCH_COLUMN) === [1, 0], 'Account values survive the complete forward and inverse chain.');
    echo $checks . ' live-update integration assertions passed on PHP ' . PHP_VERSION . ".\n";
} finally {
    if (isset($first) && $first->inTransaction()) {
        $first->rollBack();
    }
    if (isset($second) && $second->inTransaction()) {
        $second->rollBack();
    }
    cleanLive($database);
}
