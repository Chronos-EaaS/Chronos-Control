<?php
declare(strict_types=1);

// The only mutable input is this synthetic installation's single feature flag.
// No path, configuration text, credentials or flag name is accepted from argv.
if (PHP_SAPI !== 'cli' || getenv('CHRONOS_TEST_STACK') !== '1' || getenv('CHRONOS_TEST_V3') !== '1'
    || !is_file('/.dockerenv') || __FILE__ !== '/opt/chronos/tests/fixtures/repository-policy.php'
    || count($argv) !== 2 || !in_array($argv[1], ['enable', 'restore'], true)) {
    fwrite(STDERR, "Refusing repository-policy mutation outside the fixed disposable v3 fixture.\n"); exit(1);
}
$root = '/opt/chronos'; $file = $root . '/config.php';
$backup = '/tmp/chronos-repository-policy.backup'; $lockFile = '/tmp/chronos-repository-policy.lock';
if (is_link($root) || is_link($file) || !is_file($file) || realpath($file) !== $file || filesize($file) > 1048576
    || is_link($backup) || (file_exists($backup) && !is_file($backup)) || is_link($lockFile)
    || !is_file($root . '/webroot/healthz.txt') || trim((string) file_get_contents($root . '/webroot/healthz.txt')) !== 'chronos-test-ready') {
    throw new RuntimeException('The fixed fixture configuration identity is unavailable.');
}
define('SERVER_ROOT', $root);
require $file;
if (!defined('DB_HOST') || DB_HOST !== 'db' || !defined('DB_NAME') || DB_NAME !== 'chronos_test'
    || !defined('DB_USER') || DB_USER !== 'chronos_test' || !defined('ENABLE_REMOTE_REPOSITORY')) {
    throw new RuntimeException('Refusing a nonfixture database configuration.');
}
$replace = static function (string $bytes) use ($root, $file): void {
    $stage = tempnam($root, '.chronos-repository-policy-');
    if ($stage === false || dirname($stage) !== $root) throw new RuntimeException('Cannot stage fixture repository policy.');
    try {
        if (file_put_contents($stage, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($stage, 0600) || !rename($stage, $file)) throw new RuntimeException('Cannot replace fixture repository policy.');
        // The HTTP process may independently cache PHP files; changing mtime
        // makes the narrowly scoped configuration change observable there too.
        touch($file, time() + 2);
        if (function_exists('opcache_invalidate')) opcache_invalidate($file, true);
    } finally { if (is_file($stage)) unlink($stage); }
};
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock fixture repository policy.');
try {
    if ($argv[1] === 'restore') {
        if (is_file($backup)) {
            $original = file_get_contents($backup);
            if (!is_string($original) || substr_count($original, "define('ENABLE_REMOTE_REPOSITORY', false);") !== 1) throw new RuntimeException('The fixture policy backup is invalid.');
            $replace($original); unlink($backup);
        }
        echo "Fixture repository policy restored.\n";
    } else {
        if (file_exists($backup)) throw new RuntimeException('Restore the previous fixture policy before enabling it again.');
        $original = file_get_contents($file);
        $disabled = "define('ENABLE_REMOTE_REPOSITORY', false);";
        if (!is_string($original) || ENABLE_REMOTE_REPOSITORY !== false || substr_count($original, $disabled) !== 1) throw new RuntimeException('The fixture repository policy must start disabled.');
        $handle = fopen($backup, 'x');
        if (!$handle) throw new RuntimeException('Cannot create the private fixture policy backup.');
        try { if (!chmod($backup, 0600) || fwrite($handle, $original) !== strlen($original)) throw new RuntimeException('Cannot protect the fixture policy backup.'); }
        finally { fclose($handle); }
        $replace(str_replace($disabled, "define('ENABLE_REMOTE_REPOSITORY', true);", $original));
        echo "Fixture repository policy enabled. No repository operation was executed.\n";
    }
} finally { flock($lock, LOCK_UN); fclose($lock); }
