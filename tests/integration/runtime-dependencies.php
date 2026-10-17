<?php
declare(strict_types=1);

// Exercise the production loader order without config, sessions, DBA or services.
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('The dependency smoke test runs only from the CLI.');
}
$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . '/chronos-runtime-dependencies-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
define('SERVER_ROOT', $root);
define('LOG_DIRECTORY', $directory);
try {
    require $root . '/vendor/autoload.php';
    require $root . '/core/autoloader.php';
    $logger = Logger_Library::getInstance();
    if (!$logger instanceof Psr\Log\LoggerInterface) {
        throw new RuntimeException('The production logger does not implement PSR-3.');
    }
    $logger->setLogLevelThreshold(Psr\Log\LogLevel::DEBUG);
    $logger->notice(new class implements Stringable {
        public function __toString(): string { return 'Synthetic dependency smoke test'; }
    });
    $file = $logger->getLogFilePath();
    if (!str_contains((string) file_get_contents($file), 'Synthetic dependency smoke test')) {
        throw new RuntimeException('The production logger did not write the message.');
    }
    foreach (get_included_files() as $included) {
        if (str_ends_with($included, '/config.php') || str_contains($included, '/libraries/dba/')) {
            throw new RuntimeException('The dependency test loaded an operational bootstrap.');
        }
    }
    echo 'Runtime Composer/PSR logger smoke test passed on PHP ' . PHP_VERSION . ".\n";
} finally {
    foreach (glob($directory . '/log_*.txt') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
