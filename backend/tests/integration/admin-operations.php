<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/application-autoload.php';

// Run only inside the disposable fixture container, never with application config.
if (PHP_SAPI !== 'cli' || getenv('CHRONOS_TEST_STACK') !== '1' || !is_file('/.dockerenv') || __FILE__ !== '/opt/chronos/backend/tests/integration/admin-operations.php') {
    fwrite(STDERR, "Refusing administrative integration tests outside the isolated fixture container.\n");
    exit(1);
}
date_default_timezone_set('Europe/Zurich');

use Chronos\Application\Maintenance\AdminOperationExecutor;
use Chronos\Http\Api\V3\AdminRoutes;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Administration\NativeAdminOperations;
use Chronos\Http\Request;
use Chronos\Infrastructure\Security\Sessions;
use Chronos\Infrastructure\Persistence\Store;

$pdo = new PDO('mysql:host=db;dbname=chronos_test;charset=utf8mb4', 'chronos_test', 'chronos_test_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'chronos_test') throw new RuntimeException('Unexpected database.');
$db = new Store($pdo);
$tables = ['Evaluation', 'Event', 'Experiment', 'Job', 'Project', 'ProjectUser', 'Session', 'Setting', 'System', 'User', 'Node'];
$original = [];
foreach ($tables as $table) $original[$table] = $db->rows('SELECT * FROM `' . $table . '`');
$fixture = '/tmp/chronos-admin-operation-test-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700); mkdir($fixture . '/root/webroot/systems/1', 0700, true); mkdir($fixture . '/root/webroot/systems/2', 0700, true); mkdir($fixture . '/root/webroot/data', 0700, true);
mkdir($fixture . '/initial/systems/1', 0700, true); mkdir($fixture . '/initial/systems/2', 0700, true);
define('CHRONOS_ENABLE_STORAGE_MOUNT', true);
define('CHRONOS_ENABLE_DEMO_RESET', true);
define('CHRONOS_ENABLE_LOCAL_SYSTEM_RESET', true);
define('CHRONOS_MAINTENANCE_BACKUP_PATH', $fixture . '/backups');
define('CHRONOS_DEMO_SNAPSHOT', $fixture . '/initial');
define('UPLOADED_DATA_PATH', $fixture . '/root/webroot/data/');

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function fails(callable $action, string $code): void {
    try { $action(); } catch (HttpError $error) { check($error->errorCode === $code, 'Unexpected API error: ' . $error->errorCode); return; }
    throw new RuntimeException('Expected failure: ' . $code);
}
function clean(string $directory, string $boundary): void {
    if (!str_starts_with($directory . '/', $boundary . '/') || is_link($directory)) throw new RuntimeException('Unsafe test cleanup.');
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($directory);
}
function writeDataset(string $directory, array $data): void { file_put_contents($directory . '/dataset.json', json_encode(['format' => 1, 'tables' => $data], JSON_THROW_ON_ERROR)); }
$passed = [];
try {
    foreach ([1, 2] as $id) {
        file_put_contents($fixture . '/root/webroot/systems/' . $id . '/config.json', '{"identifier":"before-' . $id . '"}');
        file_put_contents($fixture . '/initial/systems/' . $id . '/config.json', '{"identifier":"initial-' . $id . '"}');
    }
    $snapshot = $original; $snapshot['Session'] = [];
    writeDataset($fixture . '/initial', $snapshot);
    $commands = [];
    $repositories = [];
    $repository = static function (string $path, array $argv) use ($fixture, &$repositories): array {
        check(str_starts_with($path, $fixture . '/backups/') && preg_match('#/new-[1-9][0-9]*$#D', $path) === 1 && $argv === ['init'], 'Repository command escaped a fresh staging directory.');
        $repositories[] = [$path, $argv]; mkdir($path . '/.git', 0700); file_put_contents($path . '/.git/HEAD', "ref: refs/heads/master\n");
        return [0, 'Synthetic repository initialized.'];
    };
    $executor = new NativeAdminOperations($db, $fixture . '/root', static function (array $argv) use (&$commands): array { $commands[] = $argv; return [0, 'Fixture mount command completed.']; }, $repository);
    $mount = $executor->plan('mount', []);
    $result = $executor->execute('mount', $mount);
    check($commands === [['/usr/bin/sudo', '-n', '/bin/mount', '--', $fixture . '/root/webroot/data']], 'Mount did not use the pinned configured directory and argv boundary.');
    check(str_contains($result['output'], 'Fixture'), 'Mount result missing.');
    $failedMount = new NativeAdminOperations($db, $fixture . '/root', static fn(array $argv): array => [9, 'fixture failure']);
    fails(static fn() => $failedMount->execute('mount', $mount), 'mount_failed');
    $tampered = $mount; $tampered['path'] .= '/unexpected';
    fails(static fn() => $executor->execute('mount', $tampered), 'resource_changed');
    $passed[] = 'ADMIN-07/09: configured mount argv, useful failure and changed-resource rejection';

    $db->execute('UPDATE `System` SET vcsUrl=\'\' WHERE systemId=1');
    $db->execute('UPDATE `System` SET vcsUrl=\'https://example.invalid/fixture.git\' WHERE systemId=2');
    $local = $executor->plan('reset-local-systems', []);
    check(count($local['resources']) === 1, 'Remote-backed systems were included.');
    $executor->execute('reset-local-systems', $local);
    check(is_dir($fixture . '/root/webroot/systems/1/.git') && count($repositories) === 1, 'Local repository initialization boundary was not called.');
    check(str_contains(file_get_contents($fixture . '/root/webroot/systems/1/config.json'), 'system-1-'), 'Local initial definition was not written.');
    check(file_get_contents($fixture . '/root/webroot/systems/2/config.json') === '{"identifier":"before-2"}', 'Remote system definitions changed.');
    check(count(glob($fixture . '/backups/*/previous-1/config.json')) === 1, 'Previous local definitions were not retained.');
    $passed[] = 'ADMIN-11: real local definition reset, private backup and remote-system exclusion';

    $changed = $executor->plan('reset-local-systems', []);
    $db->execute('UPDATE `System` SET name=\'Changed fixture\' WHERE systemId=1');
    fails(static fn() => $executor->execute('reset-local-systems', $changed), 'resource_changed');
    $db->execute('UPDATE `System` SET name=? WHERE systemId=1', [$original['System'][0]['name']]);
    file_put_contents($fixture . '/outside-marker', 'untouched');
    symlink($fixture . '/outside-marker', $fixture . '/root/webroot/systems/1/unsafe-link');
    fails(static fn() => $executor->execute('reset-local-systems', $executor->plan('reset-local-systems', [])), 'unsafe_files');
    check(file_get_contents($fixture . '/outside-marker') === 'untouched', 'A linked file was modified.');
    unlink($fixture . '/root/webroot/systems/1/unsafe-link');
    $passed[] = 'ADMIN-11: stale-plan and filesystem-link rejection without outside writes';

    $demo = $executor->plan('reset-demo', []);
    file_put_contents($fixture . '/initial/systems/1/config.json', '{"identifier":"changed-after-review"}');
    fails(static fn() => $executor->execute('reset-demo', $demo), 'resource_changed');
    file_put_contents($fixture . '/initial/systems/1/config.json', '{"identifier":"initial-1"}');
    $beforeDefinition = file_get_contents($fixture . '/root/webroot/systems/1/config.json');
    $beforeUsers = $db->rows('SELECT * FROM `User` ORDER BY userId');
    $invalid = $snapshot; $invalid['User'][] = $invalid['User'][0];
    writeDataset($fixture . '/initial', $invalid);
    try { $executor->execute('reset-demo', $executor->plan('reset-demo', [])); throw new RuntimeException('Duplicate primary key was unexpectedly accepted.'); }
    catch (PDOException) { /* The real database rejects the snapshot after the filesystem swap. */ }
    check($db->rows('SELECT * FROM `User` ORDER BY userId') === $beforeUsers, 'Database transaction did not roll back.');
    check(file_get_contents($fixture . '/root/webroot/systems/1/config.json') === $beforeDefinition, 'Filesystem recovery did not restore previous definitions.');
    $passed[] = 'ADMIN-10: pinned demo snapshot and actual database-failure filesystem recovery';

    writeDataset($fixture . '/initial', $snapshot);
    $db->execute('UPDATE `Project` SET name=\'Temporary changed dataset\' WHERE projectId=1');
    $presets = new \Chronos\Application\Projects\ParameterPresets($fixture . '/root', 1);
    $presetHash = str_repeat('a', 64);
    $preset = $presets->save(null, ['name' => 'Private old dataset', 'values' => ['label' => 'Must not leak to recycled IDs'], 'schemaHash' => $presetHash], $presetHash, 3);
    $beforeEpoch = \Chronos\Infrastructure\Security\SessionEpoch::current($fixture . '/root');
    $epochPath = $fixture . '/root/locks/v3-session-epoch';
    file_put_contents($epochPath, 'invalid');
    fails(static fn() => $executor->execute('reset-demo', $executor->plan('reset-demo', [])), 'session_validation_unavailable');
    check($db->row('SELECT name FROM `Project` WHERE projectId=1')['name'] === 'Temporary changed dataset', 'Epoch failure did not roll back the database reset.');
    check(file_get_contents($fixture . '/root/webroot/systems/1/config.json') === $beforeDefinition, 'Epoch failure did not restore the previous definitions.');
    check(file_get_contents($epochPath) === 'invalid', 'Epoch failure replaced the prior validation state.');
    check($presets->listing($presetHash)['items'][0]['id'] === $preset['id'], 'Epoch failure did not restore the old project presets.');
    file_put_contents($epochPath, $beforeEpoch . "\n");
    $done = $executor->execute('reset-demo', $executor->plan('reset-demo', []));
    check($beforeEpoch !== \Chronos\Infrastructure\Security\SessionEpoch::current($fixture . '/root'), 'Demo reset did not invalidate active PHP sessions outside the restored database.');
    check($done['signedOut'] === true, 'Demo reset did not require session termination.');
    check($presets->listing($presetHash)['items'] === [], 'Demo reset retained private presets under recycled project IDs.');
    check(count(glob($fixture . '/backups/*/previous-presets/1.json')) === 1, 'The successful demo reset did not retain a private preset backup.');
    check($db->row('SELECT name FROM `Project` WHERE projectId=1')['name'] === $snapshot['Project'][0]['name'], 'Initial demo dataset was not restored.');
    check(file_get_contents($fixture . '/root/webroot/systems/1/config.json') === '{"identifier":"initial-1"}', 'Initial demo definitions were not installed.');
    check(count(glob($fixture . '/backups/*/database.json')) >= 2, 'Recoverable database snapshots are missing.');
    $initialPreset = $presets->save(null, ['name' => 'Initial demonstration preset', 'values' => ['quantity' => 0], 'schemaHash' => $presetHash], $presetHash, 3);
    mkdir($fixture . '/initial/presets', 0700);
    copy($fixture . '/root/data/presets/1.json', $fixture . '/initial/presets/1.json');
    $presets->delete($initialPreset['id'], $initialPreset['revision']);
    file_put_contents($fixture . '/initial/presets/999.json', '{"format":1,"items":[]}');
    fails(static fn() => $executor->plan('reset-demo', []), 'invalid_snapshot');
    unlink($fixture . '/initial/presets/999.json');
    $executor->execute('reset-demo', $executor->plan('reset-demo', []));
    check($presets->listing($presetHash)['items'][0]['id'] === $initialPreset['id'], 'The initial demo preset snapshot was not restored.');
    $passed[] = 'ADMIN-10: real current-schema demo dataset/definition restore and sign-out requirement';

    $fake = new class implements AdminOperationExecutor {
        public int $calls = 0;
        public function capabilities(): array { return ['reset-local-systems' => true]; }
        public function plan(string $action, array $parameters): array { return ['canExecute' => true, 'impact' => 'Isolated lifecycle assertion.']; }
        public function execute(string $action, array $plan): array { ++$this->calls; return ['output' => 'Adapter invoked.']; }
    };
    $sessions = (new ReflectionClass(Sessions::class))->newInstanceWithoutConstructor();
    $actor = ['id' => 1, 'role' => 2]; $_SESSION = [];
    $request = static fn(array $body): Request => new Request('POST', '/', ['content-type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    $create = new AdminRoutes($request(['action' => 'reset-local-systems']), $db, $actor, $fixture . '/root', $sessions, $fake);
    $review = $create->handle('/admin/operations')['body']['data'];
    $path = '/admin/operations/' . $review['id'] . '/execute';
    $wrong = new AdminRoutes($request(['confirmation' => 'wrong']), $db, $actor, $fixture . '/root', $sessions, $fake);
    fails(static fn() => $wrong->handle($path), 'validation_failed');
    check($fake->calls === 0, 'Incorrect confirmation invoked the executor.');
    $other = new AdminRoutes($request(['confirmation' => $review['confirmation']]), $db, ['id' => 2, 'role' => 2], $fixture . '/root', $sessions, $fake);
    fails(static fn() => $other->handle($path), 'plan_expired');
    $execute = new AdminRoutes($request(['confirmation' => $review['confirmation']]), $db, $actor, $fixture . '/root', $sessions, $fake);
    check($execute->handle($path)['body']['data']['state'] === 'completed', 'Reviewed operation did not complete.');
    fails(static fn() => $execute->handle($path), 'plan_finished');
    check($fake->calls === 1, 'Operation replay invoked the executor twice.');
    $ordinary = new AdminRoutes($request(['action' => 'reset-local-systems']), $db, ['id' => 2, 'role' => 1], $fixture . '/root', $sessions, $fake);
    fails(static fn() => $ordinary->handle('/admin/operations'), 'forbidden');
    $passed[] = 'ADMIN-08/09/10/11: actor-bound confirmation, superadmin enforcement and replay prevention';
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $db->transaction(function () use ($db, $tables, $original): void {
        foreach ($tables as $table) $db->execute('DELETE FROM `' . $table . '`');
        foreach ($original as $table => $rows) foreach ($rows as $row) $db->insert($table, $row);
    });
    clean($fixture, $fixture);
}
foreach ($passed as $message) echo "PASS " . $message . "\n";
echo count($passed) . " administrative integration scenarios passed.\n";
