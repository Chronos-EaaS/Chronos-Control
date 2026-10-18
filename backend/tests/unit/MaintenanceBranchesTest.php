<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Maintenance\{AdminOperationExecutor, MaintenanceService};
use Chronos\Application\Shared\Context;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\{Authentication, Sessions};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaintenanceBranchesTest extends TestCase
{
    private string $root;
    private string|false $previousPath;
    private mixed $previousSession;
    private \PDO $pdo;
    private MaintenanceService $service;
    private AdminOperationExecutor $executor;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) self::markTestSkipped('The isolated maintenance regression requires pdo_sqlite.');
        $this->root = sys_get_temp_dir() . '/chronos-maintenance-branches-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/bin', 0700, true);
        $this->writeGit("printf '%s\\n' 'refs/heads/master' 'refs/heads/_maintenance' 'refs/heads/release/v3+hotfix' 'refs/remotes/origin/feature/änderung' 'refs/heads/feature/版本' 'refs/heads/feature/Ångström' 'refs/heads/origin/local' 'refs/heads/@' 'refs/remotes/origin/HEAD' 'refs/heads/bad..name'\n");
        $this->previousPath = getenv('PATH'); putenv('PATH=' . $this->root . '/bin');
        $this->previousSession = $_SESSION ?? null;
        $this->pdo = new class('sqlite::memory:') extends \PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
            }
        };
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE Setting(settingId INTEGER PRIMARY KEY AUTOINCREMENT,systemId INTEGER,section TEXT,item TEXT,value TEXT)');
        $this->pdo->exec("INSERT INTO Setting(systemId,section,item,value) VALUES(0,'vcs','repoBranch','master')");
        $this->pdo->exec('CREATE TABLE Event(eventId INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,time TEXT,eventText TEXT,eventType TEXT,relatedId INTEGER,userId INTEGER,nodeId INTEGER)');
        $this->executor = new class implements AdminOperationExecutor {
            public array $planned = [];
            public function capabilities(): array { return []; }
            public function plan(string $action, array $parameters): array { $this->planned[] = [$action, $parameters]; return ['impact' => 'Synthetic branch plan', 'canExecute' => false]; }
            public function execute(string $action, array $plan): array { throw new \LogicException('This test must never execute an operation.'); }
        };
        $auth = (new \ReflectionClass(Authentication::class))->newInstanceWithoutConstructor();
        $this->service = new MaintenanceService(new Context(new Store($this->pdo), ['id' => 1, 'role' => 2], $this->root), new Sessions($auth), $this->executor);
    }

    protected function tearDown(): void
    {
        if (!isset($this->root)) return;
        putenv($this->previousPath === false ? 'PATH' : 'PATH=' . $this->previousPath);
        if ($this->previousSession === null) unset($_SESSION); else $_SESSION = $this->previousSession;
        unlink($this->root . '/bin/git'); rmdir($this->root . '/bin'); rmdir($this->root);
    }

    private function writeGit(string $body): void
    {
        file_put_contents($this->root . '/bin/git', "#!/bin/sh\n" . 'case "$*" in *"--format=%(refname)"*) ;; *) exit 65 ;; esac' . "\n" . $body);
        chmod($this->root . '/bin/git', 0700);
    }

    public function testValidBranchNamesAreEnumeratedSavedAndPassedUnchangedToUpdatePlanning(): void
    {
        $status = $this->service->maintenance();
        foreach (['_maintenance', 'release/v3+hotfix', 'feature/änderung', 'feature/版本', 'feature/Ångström', 'origin/local', '@'] as $branch) {
            self::assertContains($branch, $status['branches']);
            $saved = $this->service->selectBranch(['branch' => $branch]);
            self::assertSame($branch, $saved['selectedBranch']);
            self::assertContains($branch, $saved['branches']);
            self::assertSame($branch, $this->pdo->query("SELECT value FROM Setting WHERE item='repoBranch'")->fetchColumn());
            $plan = $this->service->planOperation(['action' => 'update']);
            self::assertSame($branch, $plan['branch']);
            self::assertSame(['update', ['branch' => $branch]], $this->executor->planned[array_key_last($this->executor->planned)]);
        }
        self::assertNotContains('HEAD', $status['branches']);
        self::assertNotContains('bad..name', $status['branches']);
        self::assertSame(7, (int) $this->pdo->query('SELECT COUNT(*) FROM Event')->fetchColumn());
    }

    public static function invalidBranches(): iterable
    {
        foreach (['HEAD', '-option', '/leading', 'trailing/', 'double//slash', 'two..dots', 'hidden/.name', 'part.lock/child', 'trailing.', 'reflog@{1}', 'has space', "has\ttab", 'bad~ref', 'bad^ref', 'bad:ref', 'bad?ref', 'bad*ref', 'bad[ref', 'bad\\ref', str_repeat('a', 201), str_repeat('界', 84), ' main', 'main ', "main\n", "main\t"] as $branch) yield [$branch];
    }

    #[DataProvider('invalidBranches')]
    public function testMalformedBranchesFailBeforeSavingOrPreparingOperations(string $branch): void
    {
        foreach (['save', 'plan'] as $action) {
            try {
                if ($action === 'save') $this->service->selectBranch(['branch' => $branch]);
                else $this->service->planOperation(['action' => 'update', 'branch' => $branch]);
                self::fail('An invalid branch was accepted: ' . $branch);
            } catch (HttpError $error) {
                self::assertSame(422, $error->status);
                self::assertArrayHasKey('branch', $error->fields);
            }
        }
        self::assertSame('master', $this->pdo->query("SELECT value FROM Setting WHERE item='repoBranch'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM Event')->fetchColumn());
        self::assertSame([], $this->executor->planned);
    }

    public function testPackageOnlyInstallationKeepsItsSelectedBranchWithoutGit(): void
    {
        $this->writeGit("exit 127\n");
        $saved = $this->service->selectBranch(['branch' => 'release/v3+hotfix']);
        self::assertSame(['release/v3+hotfix'], $saved['branches']);
        self::assertSame('release/v3+hotfix', $saved['selectedBranch']);
        self::assertSame('release/v3+hotfix', $this->service->planOperation(['action' => 'update'])['branch']);
    }
}
