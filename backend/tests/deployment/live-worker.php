<?php
declare(strict_types=1);

// A real packaged worker and IPC channel, without configuration or database access.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run' || !isset($argv[2])) {
    fwrite(STDERR, "Usage: php live-worker.php --run <assembled-runtime>\n"); exit(2);
}
$runtime = realpath($argv[2]);
if ($runtime === false || !is_file($runtime . '/vendor/autoload.php')) throw new RuntimeException('The complete test runtime is required.');
require $runtime . '/vendor/autoload.php';
$base = sys_get_temp_dir() . '/chronos-live-worker-' . bin2hex(random_bytes(8));
if (!mkdir($base, 0700)) throw new RuntimeException('The live worker temporary directory is unavailable.');
$pool = null;
try {
    mkdir($base . '/webroot/ui', 0700, true);
    copy($runtime . '/constants.php', $base . '/constants.php');
    // The metadata projection configures a dummy connection but never opens it.
    copy($runtime . '/config.template.php', $base . '/config.php');
    file_put_contents($base . '/webroot/ui/build.json', '{"id":"isolated-worker-probe"}');
    $pool = new \Amp\Parallel\Worker\ContextWorkerPool(2, new \Amp\Parallel\Worker\ContextWorkerFactory(
        $runtime . '/vendor/autoload.php', new \Amp\Parallel\Context\ProcessContextFactory(binary: PHP_BINARY)));
    $tasks = [];
    for ($index = 0; $index < 2; ++$index) $tasks[] = $pool->submit(new \Chronos\Infrastructure\Realtime\WorkerTask(
        $base, 'read', ['lease' => null, 'path' => '/meta', 'query' => []]));
    foreach ($tasks as $task) {
        $reply = $task->await(new \Amp\TimeoutCancellation(10));
        if (($reply['ok'] ?? false) !== true || ($reply['data']['uiBuildId'] ?? null) !== 'isolated-worker-probe') throw new RuntimeException('The packaged live worker did not return its isolated metadata.');
    }
    $pool->shutdown(); $pool = null;
    echo "PASS: packaged live workers and private temporary IPC\n";
} finally {
    $pool?->kill();
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($base);
}
