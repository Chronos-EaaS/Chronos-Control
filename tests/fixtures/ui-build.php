<?php
declare(strict_types=1);

// Exercise the real metadata/version boundary only inside the fixed v3 fixture.
// No caller-supplied path, build ID, URL or source file can reach this helper.
if (PHP_SAPI !== 'cli' || getenv('CHRONOS_TEST_STACK') !== '1' || getenv('CHRONOS_TEST_V3') !== '1'
    || !is_file('/.dockerenv') || __FILE__ !== '/opt/chronos/tests/fixtures/ui-build.php'
    || !in_array($argv[1] ?? '', ['newer', 'restore'], true)) {
    fwrite(STDERR, "Refusing UI build mutation outside the fixed disposable v3 fixture.\n"); exit(1);
}
$root = '/opt/chronos';
if (!is_file($root . '/webroot/healthz.txt') || trim((string) file_get_contents($root . '/webroot/healthz.txt')) !== 'chronos-test-ready') throw new RuntimeException('Fixture health identity check failed.');
$file = $root . '/webroot/ui/build.json'; $backup = '/tmp/chronos-ui-build-fixture.backup';
if (is_link($file) || !is_file($file) || is_link($backup)) throw new RuntimeException('Unsafe fixture build state.');
$replace = static function (string $bytes) use ($file): void {
    // docker cp can preserve a host-owned build.json. Replacing its directory
    // entry needs only the fixture-owned UI directory, without chmod/chown or
    // a privileged process. The generated temporary file stays in that exact
    // directory so rename is atomic and never touches a compiled asset.
    $stage = tempnam(dirname($file), '.chronos-build-fixture-');
    if ($stage === false || dirname($stage) !== dirname($file)) throw new RuntimeException('Cannot stage fixture build metadata.');
    try {
        if (file_put_contents($stage, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($stage, 0644) || !rename($stage, $file)) throw new RuntimeException('Cannot replace fixture build metadata.');
    } finally { if (is_file($stage)) unlink($stage); }
};
$lock = fopen('/tmp/chronos-ui-build-fixture.lock', 'c'); if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock fixture build state.');
try {
    if ($argv[1] === 'restore') {
        if (is_file($backup)) { $original = file_get_contents($backup); if (!is_string($original)) throw new RuntimeException('Cannot read fixture build backup.'); $replace($original); unlink($backup); }
        echo "Fixture UI build restored.\n";
    } else {
        if (file_exists($backup)) throw new RuntimeException('Restore the previous fixture build before changing it again.');
        $original = file_get_contents($file); $document = json_decode((string) $original, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($document) || !is_string($document['id'] ?? null) || $document['id'] === '') throw new RuntimeException('Fixture build metadata is unavailable.');
        if (file_put_contents($backup, $original, LOCK_EX) !== strlen($original) || !chmod($backup, 0600)) throw new RuntimeException('Cannot protect fixture build backup.');
        $document['id'] = str_repeat('f', 40); $changed = json_encode($document, JSON_THROW_ON_ERROR);
        $replace($changed);
        echo "Fixture UI advertises a newer build.\n";
    }
} finally { flock($lock, LOCK_UN); fclose($lock); }
