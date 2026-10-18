<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Infrastructure\Deployment\DatabaseDeployment;
use Closure;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseDeploymentTest extends TestCase
{
    private const VERSION = '202610180001_user_enabled';
    private string $root;
    private string $bundle;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/chronos-database-deployment-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($path, 0700));
        $this->root = realpath($path);
        $this->bundle = $this->root . '/database.php';
        $this->writeRuntime();
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
            else unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testPreparationInspectsWithoutChangingTheDatabaseAndRecordsNoCredentials(): void
    {
        $database = $this->database();
        $plan = DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION, static fn(): PDO => $database);
        self::assertSame(['before' => '0', 'after' => self::VERSION, 'bundleSha256' => $this->digest()], $plan);
        self::assertSame([['status', null]], $database->state->calls);
        self::assertSame('0', $database->state->version);
    }

    public function testUnconfiguredPackagesAndExplicitTrustedNoDatabaseFixturesDoNotRunMigrations(): void
    {
        $this->writeRuntime('throw new \\RuntimeException("The runtime must not run without a database.");');
        self::assertNull(DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION));
        file_put_contents($this->root . '/config.php', '<?php throw new \\RuntimeException("A synthetic configuration must never be included.");');
        self::assertNull(DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION, static fn(): null => null));
    }

    public function testForwardAndReverseMigrationReconnectAndUseTheRetainedTargetRuntime(): void
    {
        $state = $this->database()->state;
        $connections = [];
        $connect = static function () use ($state, &$connections): PDO {
            return $connections[] = new DatabaseDeploymentPdo($state);
        };
        $plan = DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION, $connect);
        self::assertSame(self::VERSION, DatabaseDeployment::migrate($this->root, $this->bundle, $plan, true, $connect)['version']);
        self::assertSame('0', DatabaseDeployment::migrate($this->root, $this->bundle, $plan, false, $connect)['version']);
        self::assertCount(3, $connections);
        self::assertNotSame($connections[0], $connections[1]);
        self::assertNotSame($connections[1], $connections[2]);
        self::assertSame([['status', null], ['migrate', self::VERSION], ['migrate', '0']], $state->calls);
    }

    public function testDeploymentLeaseForwardsOneConnectionAndItsReleaseAcrossEveryPhase(): void
    {
        $database = $this->database();
        $lease = DatabaseDeployment::lease($this->root, $this->bundle, $this->digest(), static fn(): PDO => $database);
        self::assertSame($database, $lease['connection']);
        self::assertInstanceOf(Closure::class, $lease['release']);
        $connect = static fn(): PDO => $lease['connection'];
        $plan = DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION, $connect);
        DatabaseDeployment::migrate($this->root, $this->bundle, $plan, true, $connect);
        DatabaseDeployment::migrate($this->root, $this->bundle, $plan, false, $connect);
        self::assertSame([['lock', null], ['status', null], ['migrate', self::VERSION], ['migrate', '0']], $database->state->calls);
        self::assertSame(0, $database->state->releases);
        ($lease['release'])();
        self::assertSame(1, $database->state->releases);
    }

    public function testUnconfiguredAndExplicitNoDatabaseLeasesRemainInert(): void
    {
        self::assertNull(DatabaseDeployment::lease($this->root, $this->bundle, $this->digest()));
        file_put_contents($this->root . '/config.php', '<?php throw new \\RuntimeException("Never include synthetic configuration.");');
        self::assertNull(DatabaseDeployment::lease($this->root, $this->bundle, $this->digest(), static fn(): null => null));
    }

    public function testLeaseRequiresBothAnAcquisitionClosureAndAReleaseClosure(): void
    {
        $database = $this->database();
        foreach ([null, '"var_dump"', 'static fn(\\PDO $database): null => null'] as $lock) {
            $this->writeRuntime(lock: $lock);
            $expected = $lock === null || $lock === '"var_dump"' ? 'cannot lock' : 'lock release';
            $this->rejects(fn() => DatabaseDeployment::lease($this->root, $this->bundle, $this->digest(), static fn(): PDO => $database), $expected);
        }
    }

    public function testEveryPhaseRejectsAChangedBundleBeforeConnectingOrExecutingIt(): void
    {
        $database = $this->database();
        $connects = 0;
        $connect = static function () use ($database, &$connects): PDO { ++$connects; return $database; };
        $hash = $this->digest();
        $plan = DatabaseDeployment::prepare($this->root, $this->bundle, $hash, self::VERSION, $connect);
        self::assertSame(1, $connects);
        file_put_contents($this->bundle, '<?php file_put_contents(' . var_export($this->root . '/executed', true) . ', "unexpected");');
        foreach ([
            fn() => DatabaseDeployment::lease($this->root, $this->bundle, $hash, $connect),
            fn() => DatabaseDeployment::prepare($this->root, $this->bundle, $hash, self::VERSION, $connect),
            fn() => DatabaseDeployment::migrate($this->root, $this->bundle, $plan, true, $connect),
            fn() => DatabaseDeployment::migrate($this->root, $this->bundle, $plan, false, $connect),
        ] as $operation) $this->rejects($operation, 'integrity check');
        self::assertSame(1, $connects);
        self::assertFileDoesNotExist($this->root . '/executed');
    }

    public function testBundleLinksAndLinkedParentsAreRejected(): void
    {
        $hash = $this->digest();
        symlink($this->bundle, $this->root . '/linked.php');
        symlink($this->root, $this->root . '/linked-parent');
        foreach ([$this->root . '/linked.php', $this->root . '/linked-parent/database.php'] as $bundle) {
            $this->rejects(fn() => DatabaseDeployment::prepare($this->root, $bundle, $hash, self::VERSION), 'unsafe or unavailable');
        }
    }

    public function testRuntimeDescriptorMustMatchTheApprovedVersionAndUseAnInertClosure(): void
    {
        foreach ([
            'return [];',
            'return ["format"=>1,"version"=>"0", "run"=>static function (\\PDO $db, string $action, ?string $target): array { return []; }];',
            'return ["format"=>1,"version"=>' . var_export(self::VERSION, true) . ', "run"=>"var_dump"];',
            'return ["version"=>' . var_export(self::VERSION, true) . ', "run"=>static function (\\PDO $db, string $action, ?string $target): array { return []; }];',
            'return ["format"=>2,"version"=>' . var_export(self::VERSION, true) . ', "run"=>static function (\\PDO $db, string $action, ?string $target): array { return []; }];',
        ] as $body) {
            file_put_contents($this->bundle, '<?php ' . $body);
            $this->rejects(fn() => DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION), 'approved package version');
        }
    }

    public static function invalidStatuses(): array
    {
        return [
            'unfinished' => [['version' => '0', 'clean' => false, 'inProgress' => null]],
            'strict boolean' => [['version' => '0', 'clean' => 1, 'inProgress' => null]],
            'unrecorded transition' => [['version' => '0', 'clean' => true]],
            'transition present' => [['version' => '0', 'clean' => true, 'inProgress' => ['state' => 'applying']]],
            'invalid version' => [['version' => '../invalid', 'clean' => true, 'inProgress' => null]],
            'wrong catalog' => [['version' => '0', 'clean' => true, 'inProgress' => null, 'latestVersion' => '0']],
        ];
    }

    #[DataProvider('invalidStatuses')]
    public function testUnfinishedAndUnverifiableHistoriesAreRefused(array $status): void
    {
        $this->writeRuntime('return ' . var_export($status, true) . ';');
        $this->rejects(fn() => DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION, fn(): PDO => $this->database()), 'migration history');
    }

    public function testTheMigrationResultMustActuallyReachTheRequestedCleanVersion(): void
    {
        $this->writeRuntime('return ["version" => "0", "clean" => true, "inProgress" => null];');
        $connect = fn(): PDO => $this->database();
        $plan = DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION, $connect);
        $this->rejects(fn() => DatabaseDeployment::migrate($this->root, $this->bundle, $plan, true, $connect), 'did not reach');
    }

    public function testAnApprovedDatabaseOperationCannotLaterBeSilentlySkipped(): void
    {
        $plan = DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION, fn(): PDO => $this->database());
        $this->rejects(fn() => DatabaseDeployment::migrate($this->root, $this->bundle, $plan, true, static fn(): null => null), 'cannot be skipped');
    }

    public function testDriverAndConnectorErrorsDoNotExposePrivateCredentials(): void
    {
        $secret = 'synthetic-database-password';
        $error = $this->rejects(fn() => DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION, static function () use ($secret): never { throw new PDOException($secret); }), 'connection failed');
        self::assertStringNotContainsString($secret, (string) $error);
        $this->writeRuntime('throw new \\PDOException(' . var_export($secret, true) . ');');
        $error = $this->rejects(fn() => DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION, fn(): PDO => $this->database()), 'migration failed');
        self::assertStringNotContainsString($secret, (string) $error);
        $this->writeRuntime(lock: 'static function(\\PDO $database): never { throw new \\PDOException(' . var_export($secret, true) . '); }');
        $error = $this->rejects(fn() => DatabaseDeployment::lease($this->root, $this->bundle, $this->digest(), fn(): PDO => $this->database()), 'lock failed');
        self::assertStringNotContainsString($secret, (string) $error);
    }

    public function testMalformedPlansAndWrongConnectorResultsAreRejected(): void
    {
        $this->rejects(fn() => DatabaseDeployment::migrate($this->root, $this->bundle, [], true), 'plan is invalid');
        $this->rejects(fn() => DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION, static fn(): object => new \stdClass()), 'must return a PDO');
    }

    public function testConfigurationForAnotherLoadedInstallationIsRejectedBeforeItExecutes(): void
    {
        $marker = $this->root . '/configuration-executed';
        file_put_contents($this->root . '/config.php', '<?php file_put_contents(' . var_export($marker, true) . ', "unexpected");');
        $this->rejects(fn() => DatabaseDeployment::prepare($this->root, $this->bundle, $this->digest(), self::VERSION), 'another installation');
        self::assertFileDoesNotExist($marker);
    }

    public function testRetainedConfigurationLoadsWithoutAnApplicationBootstrapAndIncompleteSettingsFailClosed(): void
    {
        file_put_contents($this->root . '/constants.php', '<?php define("APP_VERSION", "3.0-dev");');
        file_put_contents($this->root . '/config.php', '<?php define("DB_HOST", "unused.invalid"); define("DB_NAME", "unused"); define("DB_USER", "unused"); define("DB_PASSWORD", "private");');
        $source = CHRONOS_BACKEND_ROOT . '/src/Infrastructure/Deployment/DatabaseDeployment.php';
        $script = 'require ' . var_export($source, true) . '; try { \\Chronos\\Infrastructure\\Deployment\\DatabaseDeployment::prepare('
            . var_export($this->root, true) . ',' . var_export($this->bundle, true) . ',' . var_export($this->digest(), true) . ',' . var_export(self::VERSION, true) . '); throw new \\RuntimeException("Missing settings were accepted."); } '
            . 'catch (\\RuntimeException $error) { if ($error->getMessage() !== "The retained database configuration is incomplete.") throw $error; } '
            . 'if (APP_VERSION !== "3.0-dev" || SERVER_ROOT !== ' . var_export($this->root, true) . ' || spl_autoload_functions() !== [] || session_status() === PHP_SESSION_ACTIVE) throw new \\RuntimeException("Operational bootstrap loaded."); echo "configured failure retained";';
        $process = proc_open([PHP_BINARY, '-n', '-r', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error . $output);
        self::assertSame('', $error);
        self::assertSame('configured failure retained', $output);
    }

    private function writeRuntime(?string $body = null, ?string $lock = 'default'): void
    {
        $body ??= '$db->state->calls[] = [$action, $target]; if ($action === "migrate") $db->state->version = $target; return ["version"=>$db->state->version,"latestVersion"=>'
            . var_export(self::VERSION, true) . ',"clean"=>true,"inProgress"=>null];';
        if ($lock === 'default') $lock = 'static function(\\PDO $db): \\Closure { $db->state->calls[] = ["lock", null]; return static function()use($db):void { ++$db->state->releases; }; }';
        file_put_contents($this->bundle, '<?php return ["format"=>1,"version"=>' . var_export(self::VERSION, true) . ',"run"=>static function (\\PDO $db, string $action, ?string $target): array {' . $body . '}' . ($lock !== null ? ',"lock"=>' . $lock : '') . '];');
    }

    private function digest(): string
    {
        return hash_file('sha256', $this->bundle);
    }

    private function database(): DatabaseDeploymentPdo
    {
        return new DatabaseDeploymentPdo((object) ['version' => '0', 'calls' => [], 'releases' => 0]);
    }

    private function rejects(Closure $operation, string $message): RuntimeException
    {
        try { $operation(); self::fail('The invalid database deployment was accepted.'); }
        catch (RuntimeException $error) { self::assertStringContainsString($message, $error->getMessage()); return $error; }
    }
}

/** Connection-free service boundary fixture; the verified descriptor supplies all behavior. */
final class DatabaseDeploymentPdo extends PDO
{
    public function __construct(public readonly object $state) {}
}
