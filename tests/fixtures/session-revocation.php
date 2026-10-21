<?php
declare(strict_types=1);

// Fixture preparation only. No HTTP endpoint, DSN, command or path overrides.
if (PHP_SAPI !== 'cli' || getenv('CHRONOS_TEST_STACK') !== '1' || getenv('CHRONOS_TEST_V3') !== '1'
    || !is_file('/.dockerenv') || __FILE__ !== '/opt/chronos/tests/fixtures/session-revocation.php'
    || file_get_contents('/opt/chronos/webroot/healthz.txt') !== "chronos-test-ready\n") {
    throw new RuntimeException('Refusing session fixtures outside the isolated v3 test container.');
}
define('SERVER_ROOT', '/opt/chronos');
require SERVER_ROOT . '/constants.php';
require SERVER_ROOT . '/config.php';
if (DB_HOST !== 'db' || DB_NAME !== 'chronos_test') throw new RuntimeException('Unexpected fixture database.');
$db = new PDO('mysql:host=db;dbname=chronos_test;charset=utf8mb4', DB_USER, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$action = $argv[1] ?? '';
if (in_array($action, ['legacy-session', 'expire-session'], true)) {
    $id = trim((string) stream_get_contents(STDIN));
    if (!preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $id) || session_save_path() !== '/opt/chronos/tests/runtime/sessions') {
        throw new RuntimeException('Invalid synthetic session identifier or storage.');
    }
    if ($action === 'expire-session') {
        $file = session_save_path() . '/sess_' . $id;
        if (is_link($file) || !is_file($file) || !unlink($file)) {
            throw new RuntimeException('Unable to expire the synthetic PHP session.');
        }
        echo "Synthetic PHP session expired.\n";
        exit;
    }
    session_id($id);
    session_start();
    $_SESSION = ['login' => 'owner'];
    session_write_close();
    echo "Legacy synthetic session prepared.\n";
    exit;
}
if (($argv[1] ?? '') !== 'demo-snapshot') throw new RuntimeException('Choose a supported fixture action.');
$directory = '/tmp/chronos-test-session-revocation-demo';
if (is_link($directory)) throw new RuntimeException('Unsafe synthetic snapshot directory.');
if (is_dir($directory)) {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
    }
    rmdir($directory);
}
mkdir($directory . '/systems', 0700, true);
$source = '/opt/chronos/webroot/systems';
$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($entries as $entry) {
    if ($entry->isLink()) throw new RuntimeException('Synthetic definitions contain a link.');
    $target = $directory . '/systems/' . substr($entry->getPathname(), strlen($source) + 1);
    if ($entry->isDir()) mkdir($target, 0700); elseif (!copy($entry->getPathname(), $target)) throw new RuntimeException('Unable to copy synthetic definitions.');
}
$tables = [];
foreach (['Evaluation','Event','Experiment','Job','Project','ProjectUser','Session','Setting','System','User','Node'] as $table) {
    $tables[$table] = $table === 'Session' ? [] : $db->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
}
file_put_contents($directory . '/dataset.json', json_encode(['format' => 1, 'tables' => $tables], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
$config = file_get_contents(SERVER_ROOT . '/config.php');
foreach (['CHRONOS_ENABLE_DEMO_RESET' => true, 'CHRONOS_DEMO_SNAPSHOT' => '/tmp/chronos-test-session-revocation-demo', 'CHRONOS_MAINTENANCE_BACKUP_PATH' => '/tmp/chronos-test-session-revocation-backups'] as $name => $value) {
    $pattern = "/^define\('" . preg_quote($name, '/') . "', [^\r\n;]*\);[\t ]*$/m";
    $line = "define('" . $name . "', " . var_export($value, true) . ");";
    $count = preg_match_all($pattern, $config);
    if ($count > 1) throw new RuntimeException('Ambiguous synthetic maintenance configuration.');
    $config = $count === 1 ? preg_replace($pattern, $line, $config, 1) : $config . "\n" . $line . "\n";
}
file_put_contents(SERVER_ROOT . '/config.php', $config);
echo "Synthetic same-credential demo snapshot prepared.\n";
