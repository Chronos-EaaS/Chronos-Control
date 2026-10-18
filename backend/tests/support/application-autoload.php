<?php
declare(strict_types=1);

// Native, network-disabled harnesses do not install Composer. Resolve only the
// application's inert classes with the same PSR-4 mapping as composer.json.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Chronos\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
}, true, true);
