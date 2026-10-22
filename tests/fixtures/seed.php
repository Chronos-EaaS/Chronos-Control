<?php
declare(strict_types=1);

// This command is intentionally container-only and cannot accept a DSN/path override.
if (PHP_SAPI !== 'cli' || getenv('CHRONOS_TEST_STACK') !== '1' || __DIR__ !== '/opt/chronos/tests/fixtures' || !is_file('/.dockerenv')) {
    fwrite(STDERR, "Refusing to seed outside the isolated Chronos test container.\n");
    exit(1);
}

$root = '/opt/chronos';
if (is_file($root . '/webroot/healthz.txt')) {
    unlink($root . '/webroot/healthz.txt');
}
$definition = require __DIR__ . '/definitions.php';
$db = new PDO('mysql:host=db;dbname=chronos_test;charset=utf8mb4', 'chronos_test', 'chronos_test_only', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'chronos_test') {
    throw new RuntimeException('Refusing to reset an unexpected database.');
}

function clearFixtureDirectory(string $directory): void
{
    $allowed = [
        '/opt/chronos/webroot/systems', '/opt/chronos/webroot/data', '/opt/chronos/webroot/media',
        '/opt/chronos/logs', '/opt/chronos/locks', '/opt/chronos/temp', '/opt/chronos/tests/runtime/sessions', '/opt/chronos/data/presets',
    ];
    if (!in_array($directory, $allowed, true) || is_link($directory)) {
        throw new RuntimeException('Refusing unexpected fixture cleanup path.');
    }
    if (!is_dir($directory)) {
        mkdir($directory, 0770, true);
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        // Keep live daemon coordination on the same inodes across isolated resets.
        // Tickets, leases and PHP sessions are still cleared with the fixture data.
        if ($directory === '/opt/chronos/locks' && in_array($entry->getPathname(), [
            '/opt/chronos/locks/.live-server.lock', '/opt/chronos/locks/live-notify.sock',
            '/opt/chronos/locks/.application-runtime.lock',
        ], true)) continue;
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
}
foreach ([
    '/opt/chronos/webroot/systems', '/opt/chronos/webroot/data', '/opt/chronos/webroot/media',
    '/opt/chronos/logs', '/opt/chronos/locks', '/opt/chronos/temp', '/opt/chronos/tests/runtime/sessions', '/opt/chronos/data/presets',
] as $fixtureDirectory) {
    clearFixtureDirectory($fixtureDirectory);
    if ($fixtureDirectory === '/opt/chronos/webroot/systems') {
        // OverlayFS cannot rename an image-layer directory even on the same
        // device. A fresh runtime directory supports actual atomic demo swaps.
        if (!rmdir($fixtureDirectory) || !mkdir($fixtureDirectory, 0770)) {
            throw new RuntimeException('Unable to recreate synthetic system storage.');
        }
    }
}
mkdir($root . '/webroot/data/evaluation', 0770, true);
mkdir($root . '/webroot/data/log', 0770, true);
if (is_file('/tmp/chronos-test-commands.log')) {
    unlink('/tmp/chronos-test-commands.log');
}

// The dump contains a malformed quoted identifier. Fix exactly that known typo
// in this disposable import, leaving the production chronos.sql unchanged.
$sql = file_get_contents($root . '/chronos.sql');
if (substr_count($sql, "'defaultEnvironment' varchar(100)") + substr_count($sql, '`defaultEnvironment` varchar(100)') !== 1) {
    throw new RuntimeException('The baseline schema changed; review the fixture-only SQL correction.');
}
$sql = str_replace("'defaultEnvironment' varchar(100)", '`defaultEnvironment` varchar(100)', $sql);
$tables = $db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
foreach (['VIEW', 'BASE TABLE'] as $type) {
    foreach ($tables as [$name, $tableType]) {
        if ($tableType === $type && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) {
            $db->exec(($type === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . '`' . $name . '`');
        }
    }
}
$db->exec($sql);
// The legacy dump leaves autocommit disabled even after its final COMMIT.
// Commit all synthetic records explicitly before marking the stack ready.
$db->exec('SET autocommit=1');
if (getenv('CHRONOS_TEST_V3') === '1') {
    // Use the same baseline-to-current path as a fresh installer or an update.
    // DDL runs before synthetic data transactions so recovery contexts are real.
    $migrations = require $root . '/database.php';
    $migrations['run']($db, 'migrate');
}
$db->beginTransaction();

function insertFixture(PDO $db, string $table, array $values): void
{
    $columns = implode(',', array_map(static fn(string $key): string => '`' . $key . '`', array_keys($values)));
    $marks = implode(',', array_fill(0, count($values), '?'));
    $db->prepare('INSERT INTO `' . $table . '` (' . $columns . ') VALUES (' . $marks . ')')->execute(array_values($values));
}

function writeFixtureJson(string $path, mixed $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}

$timestamp = '2026-01-15 12:00:00';
$password = password_hash('Chronos-test-2026!', PASSWORD_BCRYPT);
foreach (['superadmin', 'admin', 'owner', 'member', 'outsider', 'disabled'] as $index => $username) {
    insertFixture($db, 'User', [
        'userId' => $index + 1, 'username' => $username, 'password' => $password,
        'email' => $username . '@example.test', 'lastname' => 'Fixture', 'firstname' => ucfirst($username),
        'gender' => 1, 'role' => $index === 0 ? 2 : ($index === 1 ? 1 : 0),
        (getenv('CHRONOS_TEST_V3') === '1' ? 'enabled' : 'alive') => $username === 'disabled' ? 0 : 1, 'activated' => 1,
        'created' => $timestamp, 'lastEdit' => $timestamp, 'lastLogin' => null,
    ]);
}

foreach ([1 => ['Fixture System', 3], 2 => ['Private System', 5]] as $id => [$name, $owner]) {
    insertFixture($db, 'System', [
        'systemId' => $id, 'name' => $name, 'description' => 'Synthetic system ' . $id, 'userId' => $owner,
        'vcsUrl' => '', 'vcsBranch' => 'master', 'vcsType' => 'git', 'vcsUser' => '', 'vcsPassword' => '',
        'created' => $timestamp, 'lastEdit' => $timestamp, 'isArchived' => 0, 'cem' => $id === 1 ? 1 : 0,
    ]);
    if (getenv('CHRONOS_TEST_V3') === '1') {
        insertFixture($db, 'SystemMaintainer', ['systemId' => $id, 'userId' => $owner]);
    }
    $path = $root . '/webroot/systems/' . $id;
    if (!is_dir($path)) {
        mkdir($path, 0770, true);
    }
    // Remove only known generated fixture configuration files, inside fixed storage.
    foreach (['config.json', 'parameters.json', 'resultConfigurations.json', 'results.json', 'resultsJob.json'] as $filename) {
        if (is_file($path . '/' . $filename)) {
            unlink($path . '/' . $filename);
        }
    }
    writeFixtureJson($path . '/config.json', ['name' => $name, 'identifier' => $id === 1 ? 'fixture-system' : 'private-system']);
    writeFixtureJson($path . '/parameters.json', $definition['parameters']);
    if ($id === 1) {
        writeFixtureJson($path . '/resultConfigurations.json', $definition['results']);
    } else {
        writeFixtureJson($path . '/results.json', $definition['overallPlots']);
        writeFixtureJson($path . '/resultsJob.json', $definition['plots']);
    }
    if (getenv('CHRONOS_TEST_V3') !== '1') copy($root . '/webroot/images/active.png', $path . '/logo.png');
    if (getenv('CHRONOS_TEST_V3') === '1') {
        $extensions = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/extensions', FilesystemIterator::SKIP_DOTS));
        foreach ($extensions as $extension) {
            if (!$extension->isFile() || $extension->isLink()) throw new RuntimeException('Unsafe synthetic extension source.');
            $target = $path . '/' . substr($extension->getPathname(), strlen(__DIR__ . '/extensions/'));
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0770, true);
            copy($extension->getPathname(), $target);
        }
        // Opt-in browser API fixtures get an independent, inert repository on
        // every reset. No real Git or parent checkout may be discovered here.
        foreach ([['init', '--quiet', '--initial-branch=master'], ['add', '--', '.'], ['commit', '-m', 'Initial synthetic system fixture']] as $arguments) {
            $process = proc_open(['/usr/local/bin/git', '-C', $path, '-c', 'core.hooksPath=/dev/null', ...$arguments],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $path);
            if (!is_resource($process)) { throw new RuntimeException('Unable to start the inert fixture repository adapter.'); }
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            if (proc_close($process) !== 0) { throw new RuntimeException('Unable to initialize the isolated fixture repository.'); }
        }
        if (!is_dir($path . '/.git') || is_link($path . '/.git') || !is_file($path . '/.git/chronos-fixture-commits')) {
            throw new RuntimeException('The synthetic repository boundary was not established.');
        }
    }
}

$settings = [
    [0, 'vcs', 'repoType', 'git'], [0, 'vcs', 'repoBranch', 'master'],
    [0, 'vcs', 'repoUrl', ''], [0, 'vcs', 'repoUsername', ''], [0, 'vcs', 'repoPassword', ''],
    [0, 'ftp', 'ftpServer', ''], [0, 'ftp', 'ftpPort', '21'], [0, 'ftp', 'ftpUsername', ''],
    [0, 'ftp', 'ftpPassword', ''], [0, 'ftp', 'localNetworkCIDR', '192.0.2.0/24'],
    [0, 'ftp', 'useFtpUploadForLocalClients', '0'], [0, 'other', 'rowsPerPage', '5'],
    [0, 'other', 'maxJobsPerEvaluation', '1000'], [0, 'other', 'uploadedDataHostname', 'http://127.0.0.1:8088'],
    [0, 'cem', 'minVersion', '2'], [0, 'cem', 'nodeMissingTimeout', '86400'],
    [0, 'cem', 'environments', '["fixture"]'],
];
foreach ([1, 2] as $systemId) {
    $settings[] = [$systemId, 'environments', 'local', 'unused value'];
    $settings[] = [$systemId, 'defaultValues', 'phase_warmUp', '1'];
    $settings[] = [$systemId, 'defaultValues', 'environment', 'system-local'];
    $settings[] = [$systemId, 'general', 'fixtureSetting', 'fixture-value'];
    $settings[] = [$systemId, 'setup', 'fixtureSetup', 'fixture-setup-value'];
}
foreach ($settings as [$systemId, $section, $item, $value]) {
    insertFixture($db, 'Setting', ['systemId' => $systemId, 'section' => $section, 'item' => $item, 'value' => $value]);
}

foreach ([1 => ['Fixture Project', 3, 1], 2 => ['Private Project', 5, 2]] as $id => [$name, $owner, $systemId]) {
    insertFixture($db, 'Project', [
        'projectId' => $id, 'name' => $name, 'description' => 'Synthetic project ' . $id,
        'userId' => $owner, 'systemId' => $systemId, 'isFinished' => 0, 'environment' => 'system-local', 'isArchived' => 0,
    ]);
}
foreach ([[1, 3], [1, 4], [2, 5]] as [$projectId, $userId]) {
    insertFixture($db, 'ProjectUser', ['userId' => $userId, 'projectId' => $projectId]);
}
foreach ([1 => ['Fixture Experiment', 1, 1, 3, 0], 2 => ['Archived Experiment', 1, 1, 3, 1], 3 => ['Private Experiment', 2, 2, 5, 0]] as $id => [$name, $projectId, $systemId, $owner, $archived]) {
    $postData = $definition['experimentPostData'];
    $postData['name'] = $name;
    $postData['projectId'] = (string) $projectId;
    insertFixture($db, 'Experiment', [
        'experimentId' => $id, 'name' => $name, 'userId' => $owner, 'description' => 'Synthetic experiment ' . $id,
        'systemId' => $systemId, 'phases' => 0, 'status' => 0, 'created' => $timestamp, 'projectId' => $projectId,
        'postData' => json_encode($postData, JSON_THROW_ON_ERROR), 'internalId' => $id === 3 ? 1 : $id,
        'isArchived' => $archived, 'resultId' => 'system-1', 'defaultEnvironment' => 'system-local',
    ]);
}
foreach ([1 => ['Mixed Evaluation', 1, 1, 0, 1], 2 => ['Complete Evaluation', 1, 1, 0, 2], 3 => ['Archived Evaluation', 1, 1, 1, 3], 4 => ['Private Evaluation', 3, 2, 0, 1], 90 => ['Agent Contract Evaluation', 1, 1, 0, 90]] as $id => [$name, $experimentId, $systemId, $archived, $internalId]) {
    insertFixture($db, 'Evaluation', [
        'evaluationId' => $id, 'name' => $name, 'description' => 'Synthetic evaluation ' . $id,
        'systemId' => $systemId, 'experimentId' => $experimentId, 'internalId' => $internalId,
        'isArchived' => $archived, 'isStarred' => $id === 2 ? 1 : 0,
    ]);
}

for ($id = 1; $id <= 14; $id++) {
    $evaluationId = $id <= 6 ? 1 : ($id <= 12 ? 2 : ($id === 13 ? 3 : 4));
    $status = $id <= 6 ? [0, 1, 2, -2, 3, -1][$id - 1] : 3;
    $size = getenv('CHRONOS_TEST_V3') === '1' && $id === 4 ? 30 : ($id >= 10 && $id <= 12 ? 20 : 10);
    $run = $evaluationId === 2 ? ($id - 7) % 3 : 0;
    $systemId = $id === 14 ? 2 : 1;
    $result = ['metric' => $size + $run * 2, 'series' => '[' . implode(', ', [$size, $size + 2, $size + 4]) . ']', 'read' => 60, 'write' => 40];
    $configuration = ['parameters' => ['size' => $size, 'quantity' => 10, 'run' => $run], 'title' => 'Job[size: ' . $size . ']'];
    insertFixture($db, 'Job', [
        'jobId' => $id, 'userId' => $id === 14 ? 5 : 3, 'description' => 'Fixture Job ' . $id,
        'systemId' => $systemId, 'environment' => $id === 2 ? 'cem-fixture' : 'system-local',
        'phases' => 0, 'currentPhase' => $status === 2 ? 3 : ($status === 3 ? 5 : null),
        'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR), 'status' => $status,
        'progress' => $status === 3 ? 100 : ($status === 2 ? 50 : 0),
        'result' => $status === 3 ? json_encode($result, JSON_THROW_ON_ERROR) : '', 'created' => $timestamp,
        'started' => $status === 0 ? null : '2026-01-15 12:01:00',
        'finished' => in_array($status, [3, -1, -2], true) ? '2026-01-15 12:02:00' : null,
        'evaluationId' => $evaluationId, 'internalId' => $evaluationId === 2 ? $id - 6 : ($id >= 13 ? 1 : $id),
        'configurationIdentifier' => sha1('size:' . $size),
    ]);
    file_put_contents($root . '/webroot/data/log/' . $id . '.log', "Fixture job $id log\n\033[32mCompleted fixture step\033[0m\nLiteral <script>fixture</script>\n");
    if ($status === 3) {
        $zip = new ZipArchive();
        if ($zip->open($root . '/webroot/data/evaluation/' . $id . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create fixture archive.');
        }
        $zip->addFromString('result.json', json_encode($result, JSON_THROW_ON_ERROR) . "\n");
        $zip->setMtimeName('result.json', 1768474800);
        $zip->close();
    }
}

foreach ([90 => 0, 91 => 2] as $id => $status) {
    insertFixture($db, 'Job', [
        'jobId' => $id, 'userId' => 3, 'description' => 'Agent Fixture Job ' . $id,
        'systemId' => 1, 'environment' => 'system-agent', 'phases' => 0,
        'currentPhase' => $status === 2 ? 3 : null,
        'configuration' => json_encode(['parameters' => ['quantity' => 10, 'run' => 0], 'title' => 'Agent Fixture'], JSON_THROW_ON_ERROR),
        'status' => $status, 'progress' => $status === 2 ? 25 : 0, 'result' => '', 'created' => $timestamp,
        'started' => $status === 2 ? '2026-01-15 12:01:00' : null, 'finished' => null,
        'evaluationId' => 90, 'internalId' => $id - 89, 'configurationIdentifier' => sha1('agent-fixture'),
    ]);
    file_put_contents($root . '/webroot/data/log/' . $id . '.log', "Agent fixture log\n");
}

date_default_timezone_set('Europe/Zurich');
foreach (['idle', 'busy', 'missing', 'warning'] as $index => $kind) {
    insertFixture($db, 'Node', [
        'nodeId' => 'fixture-' . $kind, 'environment' => 'fixture', 'version' => '1',
        'currentJob' => $kind === 'busy' ? 2 : null, 'cpu' => $kind === 'warning' ? 55 : 15,
        'memoryUsed' => $kind === 'warning' ? 950 : 250, 'memoryTotal' => 1000,
        'hostname' => $kind . '.fixture.test', 'ip' => '192.0.2.' . ($index + 10),
        'os' => 'Fixture Linux', 'healthStatus' => $kind === 'warning' ? "Fixture memory warning\nSecond warning line" : '',
        'lastUpdate' => $kind === 'missing' ? $timestamp : date('Y-m-d H:i:s'),
    ]);
}
insertFixture($db, 'Event', [
    'eventId' => 1, 'title' => 'Fixture project created', 'time' => $timestamp,
    'eventText' => 'Synthetic activity for Fixture Project.', 'eventType' => 'project', 'relatedId' => 1, 'userId' => 3, 'nodeId' => null,
]);
insertFixture($db, 'Event', [
    'eventId' => 2, 'title' => 'Fixture node warning', 'time' => $timestamp,
    'eventText' => 'Synthetic CEM warning.', 'eventType' => 'node', 'relatedId' => null, 'userId' => null, 'nodeId' => 'fixture-warning',
]);
$db->commit();

// An independent connection proves the fixture transaction survived, rather
// than merely reading this seeder's own uncommitted writes.
$verificationDb = new PDO('mysql:host=db;dbname=chronos_test;charset=utf8mb4', 'chronos_test', 'chronos_test_only', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if ((int) $verificationDb->query('SELECT COUNT(*) FROM `User`')->fetchColumn() !== 6
    || (int) $verificationDb->query('SELECT COUNT(*) FROM `Job`')->fetchColumn() !== 16) {
    throw new RuntimeException('Fixture persistence verification failed; stack is not ready.');
}

$config = str_replace(
    ['__DB_HOST__', '__DB_USER__', '__DB_PASS__', '__DB_NAME__', "define('DEBUGMODE', true);"],
    ['db', 'chronos_test', 'chronos_test_only', 'chronos_test', "define('DEBUGMODE', false);"],
    file_get_contents($root . '/config.template.php')
);
if (getenv('CHRONOS_TEST_V3') === '1') $config .= "\ndefine('CHRONOS_TRUSTED_EXTENSION_SYSTEMS', [1]);\n";
file_put_contents($root . '/config.php', $config);
if (getenv('CHRONOS_TEST_V3') === '1') {
    // Maintenance UI tests review disposable simulations. Real archive install
    // and rollback use separate filesystem tests, without network access.
    $config = str_replace("define('CHRONOS_ENABLE_UPDATES', true);", "define('CHRONOS_ENABLE_UPDATES', false);", $config);
    file_put_contents($root . '/config.php', $config);
}
file_put_contents($root . '/webroot/healthz.txt', "chronos-test-ready\n");
if (getenv('CHRONOS_TEST_V3') === '1') {
    $liveNotify = @stream_socket_client('udg://' . $root . '/locks/live-notify.sock', $liveNumber, $liveError, 0.05);
    if (is_resource($liveNotify)) { stream_set_blocking($liveNotify, false); @fwrite($liveNotify, 'fixture-reset'); fclose($liveNotify); }
}
fwrite(STDOUT, "Synthetic Chronos fixtures ready. No production configuration or remote commands used.\n");
