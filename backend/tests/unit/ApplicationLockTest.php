<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Infrastructure\Deployment\ApplicationLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApplicationLockTest extends TestCase
{
    private string $root;
    private array $children = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/chronos-application-lock-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        ApplicationLock::leaveRequest();
        foreach ($this->children as [$process, $pipes]) {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
        $this->remove($this->root);
    }

    public function testSharedRequestsCoexistAndAnUpdaterWaitsForTheLastOne(): void
    {
        self::assertTrue(ApplicationLock::enterRequest($this->root));
        [$request, $requestPipes] = $this->child('echo L::enterRequest($argv[2]) ? "entered\n" : "blocked\n"; fgets(STDIN);');
        self::assertSame("entered\n", $this->line($requestPipes[1]));
        $this->maintenance();
        [$updater, $updatePipes] = $this->child('$lock=L::acquireExclusive($argv[2], 3); echo "exclusive\n"; L::release($lock);');
        ApplicationLock::leaveRequest();
        self::assertSame('', $this->available($updatePipes[1], 100_000), 'The other request still owns its shared lock.');
        fwrite($requestPipes[0], "finish\n");
        $this->finish($request, $requestPipes);
        self::assertSame("exclusive\n", $this->line($updatePipes[1]));
        $this->finish($updater, $updatePipes);
        self::assertSame(0600, fileperms($this->root . '/locks/.application-runtime.lock') & 0777);
    }

    public function testMaintenanceRejectsNewRequestsBeforeAnyStorageIsCreated(): void
    {
        $this->maintenance();
        self::assertFalse(ApplicationLock::enterRequest($this->root));
        self::assertDirectoryDoesNotExist($this->root . '/locks');
    }

    public function testMaintenanceEnabledDuringRequestEntryReleasesItsSharedLock(): void
    {
        // Pause immediately before the real shared flock in an independent process.
        $preamble = 'namespace Chronos\\Infrastructure\\Deployment { function flock($handle, $mode) { if (($mode & ~LOCK_NB) === LOCK_SH) { echo "before-shared\\n"; fgets(STDIN); } return \\flock($handle, $mode); } } ';
        [$request, $pipes] = $this->child('echo L::enterRequest($argv[2]) ? "entered\n" : "blocked\n";', $preamble);
        self::assertSame("before-shared\n", $this->line($pipes[1]));
        $this->maintenance();
        fwrite($pipes[0], "continue\n");
        self::assertSame("blocked\n", $this->line($pipes[1]));
        $this->finish($request, $pipes);
        $exclusive = ApplicationLock::acquireExclusive($this->root, 0);
        self::assertIsResource($exclusive);
        ApplicationLock::release($exclusive);
    }

    public function testExclusiveLockBlocksRequestsEvenIfMaintenanceMarkerDisappears(): void
    {
        $this->maintenance();
        $exclusive = ApplicationLock::acquireExclusive($this->root, 0);
        try {
            unlink($this->root . '/.chronos-maintenance.json');
            [$child, $pipes] = $this->child('echo L::enterRequest($argv[2]) ? "entered\n" : "blocked\n";');
            self::assertSame("blocked\n", $this->line($pipes[1]));
            $this->finish($child, $pipes);
        } finally { ApplicationLock::release($exclusive); }
        self::assertTrue(ApplicationLock::enterRequest($this->root));
    }

    public function testAnUpdatingRequestReleasesItsOwnSharedLockAndReleaseIsIdempotent(): void
    {
        self::assertTrue(ApplicationLock::enterRequest($this->root));
        $this->maintenance();
        $exclusive = ApplicationLock::acquireExclusive($this->root, 0);
        self::assertIsResource($exclusive);
        ApplicationLock::leaveRequest();
        ApplicationLock::release($exclusive);
        ApplicationLock::release($exclusive);
        unlink($this->root . '/.chronos-maintenance.json');
        self::assertTrue(ApplicationLock::enterRequest($this->root));
    }

    public function testExclusiveDrainingRequiresMaintenance(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Maintenance must be active');
        ApplicationLock::acquireExclusive($this->root, 0);
    }

    public function testShutdownDoesNotCloseAStreamOpenedAfterLeavingTheRequest(): void
    {
        [$child, $pipes] = $this->child('L::enterRequest($argv[2]); L::leaveRequest(); $other=fopen($argv[2]."/unrelated", "wb"); register_shutdown_function(static function () use ($other) { echo is_resource($other) ? "still-open\n" : "closed\n"; });');
        self::assertSame("still-open\n", $this->line($pipes[1]));
        $this->finish($child, $pipes);
    }

    public function testAStalledRequestCausesABoundedTimeoutWithoutRemovingMaintenance(): void
    {
        [$request, $pipes] = $this->child('L::enterRequest($argv[2]); echo "entered\n"; fgets(STDIN);');
        self::assertSame("entered\n", $this->line($pipes[1]));
        $this->maintenance();
        $started = hrtime(true);
        try {
            ApplicationLock::acquireExclusive($this->root, 1);
            self::fail('The running request must prevent an exclusive lock.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('timeout', $error->getMessage());
            self::assertGreaterThanOrEqual(1.0, (hrtime(true) - $started) / 1_000_000_000);
            self::assertLessThan(3.0, (hrtime(true) - $started) / 1_000_000_000);
            self::assertFileExists($this->root . '/.chronos-maintenance.json');
        }
        fwrite($pipes[0], "finish\n");
        $this->finish($request, $pipes);
        $exclusive = ApplicationLock::acquireExclusive($this->root, 0);
        ApplicationLock::release($exclusive);
    }

    public function testExistingPrivateLockNeedsNoWritableApplicationDirectory(): void
    {
        self::assertTrue(ApplicationLock::enterRequest($this->root));
        ApplicationLock::leaveRequest();
        chmod($this->root, 0500);
        chmod($this->root . '/locks', 0500);
        try { self::assertTrue(ApplicationLock::enterRequest($this->root)); }
        finally { chmod($this->root, 0700); chmod($this->root . '/locks', 0700); }
    }

    public function testLinkedOrSharedLockFilesNeverChangeTheirTarget(): void
    {
        mkdir($this->root . '/locks', 0700);
        file_put_contents($this->root . '/outside', 'untouched');
        chmod($this->root . '/outside', 0600);
        $path = $this->root . '/locks/.application-runtime.lock';
        foreach (['symlink', 'link'] as $link) {
            $link($this->root . '/outside', $path);
            try {
                ApplicationLock::enterRequest($this->root);
                self::fail('A linked lock file was accepted.');
            } catch (RuntimeException) { self::assertSame('untouched', file_get_contents($this->root . '/outside')); }
            unlink($path);
        }
    }

    public function testLinkedAndWorldWritableLockDirectoriesAreRejected(): void
    {
        mkdir($this->root . '/outside-directory', 0700);
        symlink($this->root . '/outside-directory', $this->root . '/locks');
        try {
            ApplicationLock::enterRequest($this->root);
            self::fail('A linked lock directory was accepted.');
        } catch (RuntimeException) { self::assertSame([], array_values(array_diff(scandir($this->root . '/outside-directory'), ['.', '..']))); }
        unlink($this->root . '/locks');
        mkdir($this->root . '/locks', 0700); chmod($this->root . '/locks', 0777);
        $this->expectException(RuntimeException::class);
        ApplicationLock::enterRequest($this->root);
    }

    public function testApplicationClassLoadingHasNoRuntimeSideEffects(): void
    {
        [$child, $pipes] = $this->child('echo is_dir($argv[2]."/locks") ? "created\n" : "inert\n";');
        self::assertSame("inert\n", $this->line($pipes[1]));
        $this->finish($child, $pipes);
    }

    public function testNonregularAndPublicLockFilesAreRejectedWithoutChanges(): void
    {
        mkdir($this->root . '/locks', 0700);
        $path = $this->root . '/locks/.application-runtime.lock';
        mkdir($path, 0700);
        try {
            ApplicationLock::enterRequest($this->root);
            self::fail('A directory was accepted as a lock file.');
        } catch (RuntimeException) { self::assertDirectoryExists($path); }
        rmdir($path);
        file_put_contents($path, 'private'); chmod($path, 0644);
        try {
            ApplicationLock::enterRequest($this->root);
            self::fail('A public lock file was accepted.');
        } catch (RuntimeException) {
            self::assertSame('private', file_get_contents($path));
            self::assertSame(0644, fileperms($path) & 0777);
        }
    }

    private function maintenance(): void
    {
        file_put_contents($this->root . '/.chronos-maintenance.json', '{}');
    }

    private function child(string $body, string $preamble = ''): array
    {
        $script = $preamble . 'namespace { require $argv[1]; class_alias("Chronos\\\\Infrastructure\\\\Deployment\\\\ApplicationLock", "L"); ' . $body . ' }';
        $process = proc_open([PHP_BINARY, '-n', '-r', $script, CHRONOS_BACKEND_ROOT . '/src/Infrastructure/Deployment/ApplicationLock.php', $this->root], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->children[] = [$process, $pipes];
        return [$process, $pipes];
    }

    private function line(mixed $pipe): string
    {
        $ready = [$pipe]; $write = null; $except = null;
        self::assertSame(1, stream_select($ready, $write, $except, 5), 'The child process did not respond.');
        return (string) fgets($pipe);
    }

    private function available(mixed $pipe, int $microseconds): string
    {
        $ready = [$pipe]; $write = null; $except = null;
        return stream_select($ready, $write, $except, 0, $microseconds) > 0 ? (string) fgets($pipe) : '';
    }

    private function finish(mixed $process, array $pipes): void
    {
        fclose($pipes[0]);
        self::assertSame('', stream_get_contents($pipes[1])); fclose($pipes[1]);
        self::assertSame('', stream_get_contents($pipes[2])); fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (!is_dir($path)) return;
        chmod($path, 0700);
        foreach (array_diff(scandir($path), ['.', '..']) as $name) $this->remove($path . '/' . $name);
        rmdir($path);
    }
}
