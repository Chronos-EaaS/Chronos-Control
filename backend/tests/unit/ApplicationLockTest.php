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

    public function testDrainingAnUpdatingSessionLetsItsWaitingRequestFinishAndReloadsConcurrentChanges(): void
    {
        $this->drainWaitingSession(false);
    }

    public function testConcurrentLogoutDoesNotRestoreTheUpdatingSessionsAuthentication(): void
    {
        $this->drainWaitingSession(true);
    }

    public function testADrainTimeoutReopensTheSessionSoTheFailedPlanCanBeSaved(): void
    {
        mkdir($this->root . '/sessions', 0700);
        [$owner, $ownerPipes] = $this->child($this->sessionConfiguration() . <<<'PHP'
session_start();
$_SESSION['v3AdminPlans']['operation']['plan']['state'] = 'running';
L::enterRequest($argv[2]);
echo "owner-ready\n";
fgets(STDIN);
try {
    L::acquireExclusive($argv[2], 0);
    throw new \RuntimeException('The stalled shared request was not drained.');
} catch (\RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'timeout')) throw $error;
    if (session_status() !== PHP_SESSION_ACTIVE) throw new \RuntimeException('The failed drain did not reopen its session.');
    $_SESSION['v3AdminPlans']['operation']['plan']['state'] = 'failed';
    session_write_close();
    echo "plan-failed\n";
}
PHP);
        self::assertSame("owner-ready\n", $this->line($ownerPipes[1]));
        [$waiter, $waiterPipes] = $this->child('L::enterRequest($argv[2]); echo "shared\n"; fgets(STDIN);');
        self::assertSame("shared\n", $this->line($waiterPipes[1]));
        $this->maintenance();
        fwrite($ownerPipes[0], "drain\n");
        self::assertSame("plan-failed\n", $this->line($ownerPipes[1]));
        $this->finish($owner, $ownerPipes);
        fwrite($waiterPipes[0], "finish\n");
        $this->finish($waiter, $waiterPipes);
        self::assertSame('failed', $this->savedSession()['v3AdminPlans']['operation']['plan']['state']);
        $exclusive = ApplicationLock::acquireExclusive($this->root, 0);
        ApplicationLock::release($exclusive);
    }

    public function testAnUnsavedSessionAbortsBeforeDroppingTheRequestsSharedLock(): void
    {
        mkdir($this->root . '/sessions', 0700);
        $preamble = 'namespace Chronos\\Infrastructure\\Deployment { function session_write_close() { return false; } } ';
        [$owner, $pipes] = $this->child($this->sessionConfiguration() . <<<'PHP'
session_start();
L::enterRequest($argv[2]);
file_put_contents($argv[2] . '/.chronos-maintenance.json', '{}');
try { L::acquireExclusive($argv[2], 0); throw new \RuntimeException('An unsaved session was accepted.'); }
catch (\RuntimeException $error) {
    $probe = fopen($argv[2] . '/locks/.application-runtime.lock', 'rb');
    $exclusive = flock($probe, LOCK_EX | LOCK_NB);
    echo json_encode(['error' => $error->getMessage(), 'active' => session_status() === PHP_SESSION_ACTIVE, 'sharedStillHeld' => !$exclusive], JSON_THROW_ON_ERROR) . "\n";
    fclose($probe);
}
session_write_close();
L::leaveRequest();
PHP, $preamble);
        $result = json_decode($this->line($pipes[1]), true, 64, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('could not be saved', $result['error']);
        self::assertTrue($result['active']);
        self::assertTrue($result['sharedStillHeld']);
        $this->finish($owner, $pipes);
    }

    public function testSessionResumeFailureAlwaysReleasesTheNewExclusiveHandle(): void
    {
        mkdir($this->root . '/sessions', 0700);
        foreach (['return false;', 'throw new \\RuntimeException("Injected session restart failure.");'] as $failure) {
            $preamble = 'namespace Chronos\\Infrastructure\\Deployment { function session_start() { ' . $failure . ' } } ';
            [$owner, $pipes] = $this->child($this->sessionConfiguration() . <<<'PHP'
session_start();
file_put_contents($argv[2] . '/.chronos-maintenance.json', '{}');
try { L::acquireExclusive($argv[2], 0); throw new \RuntimeException('A failed session resume was accepted.'); }
catch (\RuntimeException $error) {
    $retry = L::acquireExclusive($argv[2], 0);
    echo json_encode(['error' => $error->getMessage(), 'active' => session_status() === PHP_SESSION_ACTIVE, 'exclusiveReleased' => is_resource($retry)], JSON_THROW_ON_ERROR) . "\n";
    L::release($retry);
}
PHP, $preamble);
            $result = json_decode($this->line($pipes[1]), true, 64, JSON_THROW_ON_ERROR);
            self::assertStringContainsString(str_contains($failure, 'return') ? 'could not be reopened' : 'Injected session restart failure', $result['error']);
            self::assertFalse($result['active']);
            self::assertTrue($result['exclusiveReleased']);
            $this->finish($owner, $pipes);
        }
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

    private function drainWaitingSession(bool $logout): void
    {
        mkdir($this->root . '/sessions', 0700);
        [$owner, $ownerPipes] = $this->child($this->sessionConfiguration() . <<<'PHP'
session_start();
$_SESSION = ['login' => 'admin', 'v3Identity' => ['credentials' => 'original-proof'],
    'v3AdminPlans' => ['operation' => ['actorId' => 1, 'plan' => ['state' => 'running']]]];
L::enterRequest($argv[2]);
echo "owner-ready\n";
fgets(STDIN);
try { $exclusive = L::acquireExclusive($argv[2], 2); }
catch (\Throwable $error) { echo json_encode(['drainError' => $error->getMessage()], JSON_THROW_ON_ERROR) . "\n"; session_write_close(); exit; }
echo json_encode(['active' => session_status() === PHP_SESSION_ACTIVE, 'session' => $_SESSION], JSON_THROW_ON_ERROR) . "\n";
$_SESSION['v3AdminPlans']['operation']['plan']['state'] = 'completed';
session_write_close();
L::release($exclusive);
PHP);
        self::assertSame("owner-ready\n", $this->line($ownerPipes[1]));
        $change = $logout
            ? '$_SESSION = []; if (!session_regenerate_id(true)) throw new \\RuntimeException("Logout rotation failed."); $_SESSION["anonymous"] = true;'
            : '$_SESSION["otherTab"] = "retained"; $_SESSION["v3AdminPlans"]["other-operation"] = ["actorId" => 1, "plan" => ["state" => "planned"]];';
        [$waiter, $waiterPipes] = $this->child($this->sessionConfiguration() . <<<'PHP'
if (!L::enterRequest($argv[2])) throw new \RuntimeException('The peer request could not enter before maintenance.');
fwrite(STDOUT, "waiter-shared\n");
session_start();
PHP . $change . <<<'PHP'
session_write_close();
L::leaveRequest();
echo "waiter-finished\n";
PHP);
        self::assertSame("waiter-shared\n", $this->line($waiterPipes[1]));
        self::assertSame('', $this->available($waiterPipes[1], 100_000), 'The peer really waits for the updater\'s PHP session while holding its shared application lock.');
        $this->maintenance();
        fwrite($ownerPipes[0], "drain\n");
        self::assertSame("waiter-finished\n", $this->line($waiterPipes[1]));
        $resumed = json_decode($this->line($ownerPipes[1]), true, 64, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('drainError', $resumed, $resumed['drainError'] ?? '');
        self::assertTrue($resumed['active']);
        if ($logout) {
            self::assertArrayNotHasKey('login', $resumed['session']);
            self::assertArrayNotHasKey('v3Identity', $resumed['session']);
        } else {
            self::assertSame('running', $resumed['session']['v3AdminPlans']['operation']['plan']['state']);
            self::assertSame('retained', $resumed['session']['otherTab']);
            self::assertSame('planned', $resumed['session']['v3AdminPlans']['other-operation']['plan']['state']);
        }
        $this->finish($owner, $ownerPipes);
        $this->finish($waiter, $waiterPipes);
        $saved = $this->savedSession();
        self::assertSame('completed', $saved['v3AdminPlans']['operation']['plan']['state']);
        if ($logout) {
            self::assertArrayNotHasKey('login', $saved);
            self::assertArrayNotHasKey('v3Identity', $saved);
        } else {
            self::assertSame('retained', $saved['otherTab']);
            self::assertSame('planned', $saved['v3AdminPlans']['other-operation']['plan']['state']);
        }
    }

    private function sessionConfiguration(): string
    {
        return 'ini_set("session.use_cookies", "0"); session_cache_limiter(""); session_save_path($argv[2] . "/sessions"); session_id("chronos-shared-fixture");';
    }

    private function savedSession(): array
    {
        [$reader, $pipes] = $this->child($this->sessionConfiguration() . 'session_start(); echo json_encode($_SESSION, JSON_THROW_ON_ERROR) . "\\n"; session_abort();');
        $saved = json_decode($this->line($pipes[1]), true, 64, JSON_THROW_ON_ERROR);
        $this->finish($reader, $pipes);
        return $saved;
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
