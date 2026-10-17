<?php
declare(strict_types=1);

// Fixed, independent MySQL fixture only. This script never reads config.php from
// the checkout and cannot run through a browser or against either UI fixture DB.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run' || getenv('CHRONOS_TEST_STACK') !== '1'
    || getenv('CHRONOS_TEST_V3') !== '1' || getenv('CHRONOS_TEST_INSTALLER') !== '1'
    || !is_file('/.dockerenv') || __FILE__ !== '/opt/chronos/tests/deployment/install.php') {
    fwrite(STDERR, "Refusing installer tests outside the fixed isolated container.\n"); exit(1);
}
require_once '/opt/chronos/libraries/install.php';

function ensure(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function denied(callable $operation, string $contains): void {
    try { $operation(); } catch (Throwable $error) { ensure(str_contains($error->getMessage(), $contains), 'Installer rejected for an unexpected reason: ' . get_class($error)); return; }
    throw new RuntimeException('An invalid installation unexpectedly succeeded.');
}
function tree(string $source, string $target): void {
    ensure(is_dir($source) && !is_link($source), 'Runtime fixture source must be a real directory.');
    if (!is_dir($target)) ensure(mkdir($target, 0700, true), 'Cannot prepare runtime fixture.');
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot() || $entry->isLink()) continue;
        if ($entry->isDir()) tree($entry->getPathname(), $target . '/' . $entry->getFilename());
        elseif ($entry->isFile()) ensure(copy($entry->getPathname(), $target . '/' . $entry->getFilename()), 'Cannot copy runtime fixture.');
    }
}
function removeTree(string $path, string $boundary): void {
    ensure(str_starts_with($path . '/', $boundary . '/') && !is_link($path), 'Unsafe fixture cleanup rejected.');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
    }
    rmdir($path);
}
$dbPassword = "Installer-'quote\\Zürich-2026!";
$db = new PDO('mysql:host=install-db;port=3306;dbname=chronos_install_test;charset=utf8mb4', 'chronos_installer', 'chronos_installer_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensure($db->query('SELECT DATABASE()')->fetchColumn() === 'chronos_install_test'
    && $db->query('SELECT @@hostname')->fetchColumn() === 'chronos-v3-installer-db'
    && str_starts_with((string) $db->query('SELECT VERSION()')->fetchColumn(), '8.'), 'Installer database identity guard failed.');
function emptyDatabase(PDO $db): void {
    ensure($db->query('SELECT DATABASE()')->fetchColumn() === 'chronos_install_test' && $db->query('SELECT @@hostname')->fetchColumn() === 'chronos-v3-installer-db', 'Cleanup database identity guard failed.');
    $tables = $db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach (['VIEW', 'BASE TABLE'] as $kind) foreach ($tables as [$name, $type]) {
        ensure(preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name) === 1, 'Unexpected fixture object name.');
        if ($type === $kind) $db->exec(($kind === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . '`' . $name . '`');
    }
}
ensure($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) === [], 'The dedicated installer database must initially be empty.');
// The upstream MySQL entrypoint cannot quote arbitrary MYSQL_PASSWORD values.
// Change only this dedicated account through PDO before testing escaped storage.
$db->exec("ALTER USER 'chronos_installer'@'%' IDENTIFIED BY " . $db->quote($dbPassword));
$base = '/tmp/chronos-installer-test-' . bin2hex(random_bytes(8)); ensure(mkdir($base, 0700), 'Cannot prepare installer test root.');
$input = ['dbHost' => 'install-db', 'dbPort' => 3306, 'dbName' => 'chronos_install_test', 'dbUser' => 'chronos_installer', 'dbPassword' => $dbPassword,
    'username' => "installer'admin", 'firstName' => 'Zoë "Q" 🧪', 'lastName' => "O'Connor Zürich", 'email' => 'installer+qa@example.test',
    'password' => "Fresh-'quote\\Zürich-2026!", 'passwordConfirmation' => "Fresh-'quote\\Zürich-2026!", 'branch' => 'feature/installer-qa'];
function application(string $base, string $name, bool $runtime = false): string {
    $root = $base . '/' . $name; ensure(mkdir($root, 0700), 'Cannot prepare an installation root.');
    foreach (['chronos.sql', 'config.template.php', 'constants.php'] as $file) ensure(copy('/opt/chronos/' . $file, $root . '/' . $file), 'Cannot prepare package source.');
    ensure(mkdir($root . '/webroot/ui', 0700, true), 'Cannot prepare UI package.'); file_put_contents($root . '/webroot/ui/index.html', '<app-root></app-root>'); file_put_contents($root . '/webroot/ui/build.json', '{"id":"installer-fixture"}');
    if ($runtime) {
        foreach (['app', 'api', 'core', 'libraries', 'vendor'] as $directory) tree('/opt/chronos/' . $directory, $root . '/' . $directory);
        ensure(copy('/opt/chronos/install.php', $root . '/install.php'), 'Cannot prepare installer entrypoint.');
        ensure(copy('/opt/chronos/webroot/index.php', $root . '/webroot/index.php'), 'Cannot prepare HTTP entrypoint.');
    } else { ensure(mkdir($root . '/vendor'), 'Cannot prepare PHP package.'); file_put_contents($root . '/vendor/autoload.php', '<?php'); }
    return $root;
}
function configConstants(string $root): array {
    $code = 'define("SERVER_ROOT",' . var_export($root, true) . '); require ' . var_export($root . '/config.php', true) . '; echo json_encode([DB_HOST,DB_PORT,DB_NAME,DB_USER,DB_PASSWORD], JSON_THROW_ON_ERROR);';
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    ensure(is_resource($process), 'Cannot verify isolated configuration.'); fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    ensure(proc_close($process) === 0 && $error === '', 'Saved configuration is not valid PHP.'); return json_decode($output, true, 16, JSON_THROW_ON_ERROR);
}
function verifyLogin(string $root, string $base, array $input): void {
    ensure(mkdir($base . '/sessions', 0700), 'Cannot prepare isolated sessions.');
    $router = $base . '/router.php'; file_put_contents($router, '<?php chdir(' . var_export($root . '/webroot', true) . '); require ' . var_export($root . '/webroot/index.php', true) . ';');
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'session.save_path=' . $base . '/sessions', '-S', '127.0.0.1:8097', '-t', $root . '/webroot', $router], [0 => ['pipe', 'r'], 1 => ['file', $base . '/server.log', 'a'], 2 => ['file', $base . '/server.log', 'a']], $pipes, $root . '/webroot');
    ensure(is_resource($process), 'Cannot start isolated installed runtime.'); fclose($pipes[0]);
    $curl = curl_init(); $jar = $base . '/cookies';
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 3]);
    $request = static function (string $method, ?array $body = null, string $csrf = '') use ($curl): array {
        curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1:8097/api/v3/session', CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: http://127.0.0.1:8097', 'X-CSRF-Token: ' . $csrf], CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)]);
        $text = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        return [$status, is_string($text) ? json_decode($text, true) : null];
    };
    try {
        $status = 0; $session = null;
        for ($attempt = 0; $attempt < 60; $attempt++) { [$status, $session] = $request('GET'); if ($status) break; usleep(50_000); }
        ensure($status === 200 && ($session['data']['user'] ?? null) === null, 'Fresh installed session endpoint failed.');
        [$status, $failed] = $request('POST', ['username' => $input['username'], 'password' => 'Incorrect-fixture-password!', 'remember' => false], $session['data']['csrfToken']); ensure($status === 401 && !isset($failed['data']['user']), 'An invalid initial password was accepted.');
        [$status, $signedIn] = $request('POST', ['username' => $input['username'], 'password' => $input['password'], 'remember' => false], $session['data']['csrfToken']);
        ensure($status === 200 && ($signedIn['data']['user']['role'] ?? null) === 2 && $signedIn['data']['user']['username'] === $input['username'], 'The initial administrator cannot sign in through the real API.');
        ensure($signedIn['data']['user']['firstName'] === $input['firstName'] && $signedIn['data']['user']['lastName'] === $input['lastName'], 'Encoded Unicode identity did not round-trip through the API.');
        [$status, $persisted] = $request('GET'); ensure($status === 200 && ($persisted['data']['user']['role'] ?? null) === 2, 'Installed administrator session did not persist.');
    } finally { unset($curl); proc_terminate($process); proc_close($process); }
}
function verifyInstallerForm(string $root, string $base, array $input, PDO $db): void {
    ensure(mkdir($base, 0700) && mkdir($base . '/sessions', 0700), 'Cannot prepare browser installer fixture.');
    file_put_contents($root . '/.chronos-release.json', json_encode(['branch' => $input['branch']], JSON_THROW_ON_ERROR));
    $router = $base . '/router.php'; file_put_contents($router, '<?php chdir(' . var_export($root . '/webroot', true) . '); require ' . var_export($root . '/webroot/index.php', true) . ';');
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'session.save_path=' . $base . '/sessions', '-S', '127.0.0.1:8097', '-t', $root . '/webroot', $router], [0 => ['pipe', 'r'], 1 => ['file', $base . '/server.log', 'a'], 2 => ['file', $base . '/server.log', 'a']], $pipes, $root . '/webroot');
    ensure(is_resource($process), 'Cannot start browser installer fixture.'); fclose($pipes[0]);
    $curl = curl_init(); $jar = $base . '/cookies'; curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1:8097/', CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 5]);
    try {
        $html = false; for ($attempt = 0; $attempt < 60; $attempt++) { $html = curl_exec($curl); if (is_string($html)) break; usleep(50_000); }
        ensure(curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200 && is_string($html) && str_contains($html, 'Initial administrator'), 'Unconfigured runtime did not show its installer.');
        ensure(preg_match('/name="csrf" value="([a-f0-9]{64})"/', $html, $match) === 1, 'Installer did not issue a CSRF token.');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($input + ['csrf' => str_repeat('0', 64)])]);
        $rejected = curl_exec($curl); ensure(is_string($rejected) && str_contains($rejected, 'installation session expired') && !is_file($root . '/config.php') && $db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) === [], 'Invalid installer CSRF changed state.');
        ensure(!str_contains($rejected, $input['dbPassword']) && !str_contains($rejected, htmlspecialchars($input['password'], ENT_QUOTES, 'UTF-8')), 'Installer echoed a submitted password.');
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($input + ['csrf' => $match[1]])); $completed = curl_exec($curl);
        ensure(is_string($completed) && str_contains($completed, 'Installation completed') && str_contains($completed, 'href="/login"') && is_file($root . '/config.php'), 'Browser installer did not complete the prepared offline package.');
        ensure(!str_contains($completed, $input['dbPassword']) && !str_contains($completed, $input['password']), 'Installer completion exposed credentials.');
        $schema = $db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM); $configHash = hash_file('sha256', $root . '/config.php'); curl_setopt_array($curl, [CURLOPT_POSTFIELDS => http_build_query($input + ['csrf' => $match[1]])]);
        $again = curl_exec($curl); ensure(is_string($again) && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 405 && $db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) === $schema && hash_file('sha256', $root . '/config.php') === $configHash && (int) $db->query('SELECT COUNT(*) FROM `User`')->fetchColumn() === 1, 'Configured application did not reject a repeated setup POST without changes.');
    } finally { unset($curl); proc_terminate($process); proc_close($process); }
}
$passed = [];
try {
    $root = application($base, 'fresh', true); $sourceHash = hash_file('sha256', '/opt/chronos/chronos.sql');
    $result = Install_Library::install($root, $input); ensure(($result['success'] ?? false) === true, 'Fresh installation failed.');
    $user = $db->query('SELECT * FROM `User`')->fetch(PDO::FETCH_ASSOC); ensure($user['username'] === $input['username'] && (int) $user['role'] === 2 && (int) $user['alive'] === 1 && (int) $user['activated'] === 1 && password_verify($input['password'], $user['password']), 'Initial administrator was not stored correctly.');
    ensure(configConstants($root) === ['install-db', 3306, 'chronos_install_test', 'chronos_installer', $dbPassword], 'Quoted Unicode database credentials changed in saved configuration.');
    ensure((fileperms($root . '/config.php') & 0777) === 0600 && glob($root . '/.chronos-config-*') === [], 'Configuration must be private and staged files removed.');
    ensure($db->query("SELECT value FROM `Setting` WHERE section='vcs' AND item='repoBranch'")->fetchColumn() === $input['branch'], 'Selected branch was lost.');
    verifyLogin($root, $base, $input); ensure(hash_file('sha256', '/opt/chronos/chronos.sql') === $sourceHash, 'Installer changed the package schema source.');
    $passed[] = 'fresh MySQL8 installation, private escaped configuration, Unicode/quoted identity, branch and real administrator login'; emptyDatabase($db);

    $root = application($base, 'existing-config'); file_put_contents($root . '/config.php', '<?php /* existing private configuration */'); $hash = hash_file('sha256', $root . '/config.php');
    denied(fn() => Install_Library::install($root, $input), 'already configured'); ensure(hash_file('sha256', $root . '/config.php') === $hash && $db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) === [], 'Existing configuration refusal changed data.'); $passed[] = 'existing configuration refusal without database writes';

    $db->exec('CREATE TABLE ExistingFixture (id INT PRIMARY KEY, payload VARCHAR(100))'); $db->exec("INSERT INTO ExistingFixture VALUES (1,'untouched fixture')");
    $before = $db->query('SHOW CREATE TABLE ExistingFixture')->fetch(PDO::FETCH_NUM); $root = application($base, 'nonempty');
    denied(fn() => Install_Library::install($root, $input), 'must be empty'); ensure($db->query('SHOW CREATE TABLE ExistingFixture')->fetch(PDO::FETCH_NUM) === $before && $db->query('SELECT payload FROM ExistingFixture')->fetchColumn() === 'untouched fixture' && !is_file($root . '/config.php'), 'A refused installation changed existing schema or rows.');
    $passed[] = 'nonempty database refusal preserves exact existing schema and rows'; emptyDatabase($db);

    $root = application($base, 'invalid-ddl'); file_put_contents($root . '/chronos.sql', "\nCREATE TABLE InstallerPartial (id INT);\nCREATE VIEW InstallerPartialView AS SELECT id FROM InstallerPartial;\nCREATE TABLE InstallerBroken (\n");
    denied(fn() => Install_Library::install($root, $input), 'SQLSTATE'); ensure($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) === [] && !is_file($root . '/config.php') && glob($root . '/.chronos-config-*') === [], 'DDL failure left schema or configuration behind.');
    $passed[] = 'malformed DDL removes only its own initially empty database objects';

    $root = application($base, 'filesystem-failure'); file_put_contents($root . '/locks', 'fixture blocker');
    set_error_handler(static function (int $number, string $message): never { throw new ErrorException($message, 0, $number); });
    try { denied(fn() => Install_Library::install($root, $input), 'mkdir'); } finally { restore_error_handler(); }
    ensure($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) === [] && !is_file($root . '/config.php') && glob($root . '/.chronos-config-*') === [] && file_get_contents($root . '/locks') === 'fixture blocker', 'Filesystem failure did not roll back its new schema.'); $passed[] = 'filesystem activation failure cleans new schema and retains preexisting paths';

    $root = application($base, 'invalid-template'); file_put_contents($root . '/config.template.php', '<?php /* missing placeholders */');
    denied(fn() => Install_Library::install($root, $input), 'template is invalid'); ensure($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) === [] && !is_file($root . '/config.php'), 'Invalid template changed the empty database.'); $passed[] = 'invalid package template refuses before schema creation';

    $root = application($base, 'invalid-port-template');
    file_put_contents($root . '/config.template.php', str_replace("define('DB_PORT', 3306);", '', file_get_contents($root . '/config.template.php')));
    denied(fn() => Install_Library::install($root, $input), 'template is invalid');
    ensure($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) === [] && !is_file($root . '/config.php') && glob($root . '/.chronos-config-*') === [], 'A missing port template changed schema or staged configuration.');
    $passed[] = 'missing port template refuses before schema or configuration writes';

    $root = application($base, 'concurrent'); $lock = 'chronos.install.' . substr(hash('sha256', 'chronos_install_test'), 0, 40); $statement = $db->prepare('SELECT GET_LOCK(?,0)'); $statement->execute([$lock]); ensure((int) $statement->fetchColumn() === 1, 'Cannot prepare concurrent installer fixture.');
    try { denied(fn() => Install_Library::install($root, $input), 'Another installation is using this database'); ensure($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) === [] && !is_file($root . '/config.php'), 'Concurrent installer changed data.'); }
    finally { $statement = $db->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$lock]); }
    $passed[] = 'database lock rejects a concurrent installation before writes';

    $root = application($base, 'invalid-input'); $invalid = $input; $invalid['passwordConfirmation'] = 'Different-fixture-password!';
    denied(fn() => Install_Library::install($root, $invalid), 'matching administrator password'); ensure($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) === [] && !is_file($root . '/config.php'), 'Invalid credentials changed data.'); $passed[] = 'invalid initial administrator credentials leave the database empty';
    $root = application($base, 'unsupported-username'); $invalid = $input; $invalid['username'] = 'installer-🧪';
    denied(fn() => Install_Library::install($root, $invalid), 'username without emoji');
    ensure($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) === [] && !is_file($root . '/config.php'), 'Unsupported username changed the database or configuration.');
    $passed[] = 'unsupported initial raw username refuses before database writes';
    $root = application($base, 'browser-package', true); verifyInstallerForm($root, $base . '/browser-session', $input, $db); $passed[] = 'real installer form, CSRF refusal, offline branch package, secret redaction and repeated-install refusal';
    foreach ($passed as $scenario) echo 'PASS PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ': ' . $scenario . ".\n";
    echo count($passed) . " installer scenarios passed.\n";
} finally { emptyDatabase($db); $db->exec("ALTER USER 'chronos_installer'@'%' IDENTIFIED BY 'chronos_installer_only'"); removeTree($base, $base); }
