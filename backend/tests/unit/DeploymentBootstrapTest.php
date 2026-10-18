<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\TestCase;

final class DeploymentBootstrapTest extends TestCase
{
    public function testInstallationAndRecoveryServicesLoadWithoutComposerDatabaseOrGlobalAliases(): void
    {
        $source = dirname(__DIR__, 2) . '/src/Infrastructure/Deployment/';
        $script = 'require ' . var_export($source . 'ReleaseManager.php', true) . '; require ' . var_export($source . 'Installer.php', true) . ';' . <<<'PHP'
foreach (['Chronos\\Infrastructure\\Deployment\\ReleaseManager', 'Chronos\\Infrastructure\\Deployment\\Installer'] as $class) {
    if (!class_exists($class, false)) throw new RuntimeException('A bootstrap-free deployment service is unavailable.');
}
foreach (['Release_Library', 'Install_Library', 'DBA\\Factory', 'Chronos\\Infrastructure\\Configuration\\SystemSettings'] as $class) {
    if (class_exists($class, false)) throw new RuntimeException('A deployment service loaded operational state or a compatibility alias.');
}
if (spl_autoload_functions() !== []) throw new RuntimeException('Deployment recovery unexpectedly requires an application autoloader.');
echo 'independent deployment services ready';
PHP;
        $process = proc_open([PHP_BINARY, '-n', '-r', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error . $output);
        self::assertSame('', $error);
        self::assertSame('independent deployment services ready', $output);
    }
}
