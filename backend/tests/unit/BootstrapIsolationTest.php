<?php

declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\TestCase;

final class BootstrapIsolationTest extends TestCase
{
    public function testUnitProcessHasNoOperationalConfigurationOrRuntimeEntrypoints(): void
    {
        $loaded = array_map('realpath', get_included_files());
        foreach (['resources/config.template.php', 'resources/install.php', 'public/index.php', 'http/v1/bootstrap.php', 'http/ui/bootstrap.php',
            'compatibility/deployment/browser-ui.php', 'compatibility/deployment/release.php', 'compatibility/deployment/install.php'] as $forbiddenFile) {
            self::assertNotContains(realpath(CHRONOS_BACKEND_ROOT . '/' . $forbiddenFile), $loaded);
        }
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_PORT'] as $constant) self::assertFalse(defined($constant));
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame('Europe/Zurich', date_default_timezone_get());
    }

    public function testFreshUnitBootstrapDoesNotConfigureConnectionsStartSessionsOrActivateAliases(): void
    {
        $script = <<<'PHP'
$initialSession = session_status();
ob_start();
require $argv[1];
if (ob_get_clean() !== '') throw new RuntimeException('The unit bootstrap emitted output.');
if (session_status() !== $initialSession || isset($_SESSION)) throw new RuntimeException('The unit bootstrap started a session.');
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_PORT'] as $constant) {
    if (defined($constant)) throw new RuntimeException('The unit bootstrap defined database credentials.');
}
foreach (['BrowserUi', 'Release_Library', 'Install_Library'] as $class) {
    if (class_exists($class, false)) throw new RuntimeException('The unit bootstrap activated a compatibility alias.');
}
try {
    Chronos\Infrastructure\Persistence\Connection::get();
    throw new RuntimeException('The unit bootstrap configured a connection.');
} catch (LogicException $error) {
    if ($error->getMessage() !== 'Configure the database before requesting a connection.') throw $error;
}
echo 'isolated unit bootstrap ready';
PHP;
        $process = proc_open([PHP_BINARY, '-n', '-d', 'allow_url_fopen=0', '-r', $script, CHRONOS_BACKEND_ROOT . '/tests/bootstrap.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error . $output);
        self::assertSame('', $error);
        self::assertSame('isolated unit bootstrap ready', $output);
    }
}
