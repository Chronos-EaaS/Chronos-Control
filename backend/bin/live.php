<?php
declare(strict_types=1);

// Foreground PHP service. Installation of the supervisor and proxy is an operator action.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CHRONOS_RUNTIME_ROOT') ?: dirname(__DIR__);
if (is_link($root) || ($root = realpath($root)) === false) { fwrite(STDERR, "The application root is unavailable.\n"); exit(1); }
if (file_exists($root . '/.chronos-maintenance.json') || is_link($root . '/.chronos-maintenance.json')) exit(75);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) { fwrite(STDERR, "Install the complete Chronos CI package before starting live updates.\n"); exit(1); }
require $autoload;
if (($argv[1] ?? '') === '--check') {
    foreach ([\Amp\Websocket\Server\Websocket::class, \Amp\Parallel\Worker\ContextWorkerPool::class, \Chronos\Infrastructure\Realtime\Server::class] as $class) {
        if (!class_exists($class)) throw new RuntimeException('A packaged live dependency is missing.');
    }
    if (!function_exists('proc_open') || !function_exists('stream_socket_server')) throw new RuntimeException('PHP CLI process and stream socket support is required.');
    echo "Chronos live runtime ready\n";
    exit;
}
if (!is_file($root . '/config.php')) { fwrite(STDERR, "Install Chronos before starting live updates.\n"); exit(1); }
$logger = new class extends \Psr\Log\AbstractLogger {
    public function log($level, string|Stringable $message, array $context = []): void {
        fwrite(STDERR, gmdate('c') . ' [' . $level . '] ' . $message . "\n");
    }
};
$origin = getenv('CHRONOS_LIVE_PUBLIC_ORIGIN') ?: null;
if ($origin !== null && (!preg_match('~^https?://[^/?#]+$~D', $origin) || filter_var($origin, FILTER_VALIDATE_URL) === false)) {
    throw new RuntimeException('CHRONOS_LIVE_PUBLIC_ORIGIN must be an exact HTTP(S) origin.');
}
$workerCount = getenv('CHRONOS_LIVE_WORKERS') ?: '2';
if (!ctype_digit($workerCount)) throw new RuntimeException('CHRONOS_LIVE_WORKERS must be an integer.');
$server = new \Chronos\Infrastructure\Realtime\Server($root, $logger, getenv('CHRONOS_LIVE_BIND') ?: '127.0.0.1:9081', $origin, (int) $workerCount);
$server->run();
