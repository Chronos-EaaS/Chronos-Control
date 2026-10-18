<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AutoloaderBoundaryTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testComposerYieldsUnknownApplicationAndVendorClassesToSubsequentLoaders(): void
    {
        $requested = [];
        $observer = static function (string $class) use (&$requested): void { $requested[] = $class; };
        spl_autoload_register($observer);
        try {
            self::assertFalse(class_exists('OtherVendor\\MissingService'));
            self::assertFalse(class_exists('Chronos\\MissingModule\\MissingService'));
            self::assertSame(['OtherVendor\\MissingService', 'Chronos\\MissingModule\\MissingService'], $requested);
        } finally { spl_autoload_unregister($observer); }
    }

    public function testEveryProductionDefinitionLoadsThroughComposerWithoutOperationalBootstrap(): void
    {
        $script = <<<'PHP'
set_error_handler(static function (int $level, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $level, $file, $line); });
$backend = $argv[1];
$initialSession = session_status();
ob_start();
require $backend . '/vendor/autoload.php';
$definitions = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backend . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $class = 'Chronos\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($backend . '/src/'), -4));
    if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) throw new RuntimeException('Composer cannot load ' . $class);
    if (realpath((new ReflectionClass($class))->getFileName()) !== realpath($file->getPathname())) throw new RuntimeException('A class resolved outside its PSR source file: ' . $class);
    $definitions[] = $class;
}
if (ob_get_clean() !== '') throw new RuntimeException('Autoloading a production definition emitted output.');
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_PORT'] as $constant) {
    if (defined($constant)) throw new RuntimeException('Autoloading exposed operational configuration.');
}
if (session_status() !== $initialSession || isset($_SESSION)) throw new RuntimeException('Autoloading started a session.');
foreach (['BrowserUi', 'Release_Library', 'Install_Library', 'DBA\\Factory'] as $class) {
    if (class_exists($class, false)) throw new RuntimeException('Autoloading activated a historical compatibility class.');
}
foreach (get_included_files() as $file) {
    if (basename($file) === 'config.php' || str_contains($file, '/compatibility/') || in_array(realpath($file), [realpath($backend . '/public/index.php'), realpath($backend . '/resources/config.template.php'), realpath($backend . '/http/v1/bootstrap.php'), realpath($backend . '/http/v3/bootstrap.php')], true)) {
        throw new RuntimeException('Autoloading included a runtime entry or configuration.');
    }
}
try {
    Chronos\Infrastructure\Persistence\Connection::get();
    throw new RuntimeException('Autoloading configured a database connection.');
} catch (LogicException $error) {
    if ($error->getMessage() !== 'Configure the database before requesting a connection.') throw $error;
}
sort($definitions);
echo json_encode($definitions, JSON_THROW_ON_ERROR);
PHP;
        $process = proc_open([PHP_BINARY, '-n', '-d', 'allow_url_fopen=0', '-r', $script, CHRONOS_BACKEND_ROOT], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error . $output);
        self::assertSame('', $error);
        $expected = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(CHRONOS_BACKEND_ROOT . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') $expected[] = 'Chronos\\' . str_replace('/', '\\', substr($file->getPathname(), strlen(CHRONOS_BACKEND_ROOT . '/src/'), -4));
        }
        sort($expected);
        self::assertNotEmpty($expected);
        self::assertSame($expected, json_decode($output, true, 64, JSON_THROW_ON_ERROR));
    }
}
