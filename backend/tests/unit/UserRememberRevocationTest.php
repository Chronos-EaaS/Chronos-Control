<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Shared\Context;
use Chronos\Application\Users\UserService;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Authentication;
use Chronos\Infrastructure\Security\Sessions;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RememberRevocationDatabase extends PDO
{
    public ?\Closure $beforeLockedRead = null;
    public bool $lockedInsideTransaction = false;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, ' FOR UPDATE')) {
            $this->lockedInsideTransaction = $this->inTransaction();
            $callback = $this->beforeLockedRead;
            $this->beforeLockedRead = null;
            if ($callback !== null) $callback($this);
        }
        // SQLite models the transaction and data effects; the HTTP contracts
        // also execute the unchanged MySQL FOR UPDATE statement on real MySQL.
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}

final class UserRememberRevocationTest extends TestCase
{
    private RememberRevocationDatabase $db;
    private UserService $users;

    protected function setUp(): void
    {
        $this->db = new RememberRevocationDatabase('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE User(userId INTEGER PRIMARY KEY,username TEXT,password TEXT,firstname TEXT,lastname TEXT,email TEXT,gender INTEGER,role INTEGER,enabled INTEGER,activated INTEGER,created TEXT,lastEdit TEXT,lastLogin TEXT)');
        $this->db->exec("INSERT INTO User VALUES(3,'target','synthetic-hash','Target','User','target@example.test',0,0,1,1,NULL,NULL,NULL)");
        $this->db->exec('CREATE TABLE Session(sessionId INTEGER PRIMARY KEY,selector TEXT,userId INTEGER)');
        $this->db->exec("INSERT INTO Session VALUES(1,'first-target-token',3),(2,'second-target-token',3),(3,'other-user-token',4)");
        $this->db->exec('CREATE TABLE Event(eventId INTEGER PRIMARY KEY,title TEXT,time TEXT,eventText TEXT,eventType TEXT,relatedId INTEGER,userId INTEGER,nodeId TEXT)');
        $auth = new class extends Authentication {
            public function __construct() {}
            public function isSwitchedUser(): bool { return false; }
        };
        $this->users = new UserService(new Context(new Store($this->db), ['id' => 1, 'role' => 2], '/unused'), new Sessions($auth));
    }

    public static function changedCredentials(): array
    {
        return [
            'promotion' => [[], ['role' => 1]],
            'demotion' => [['role' => 1], ['role' => 0]],
            'deactivation' => [[], ['activated' => false]],
            'activation' => [['activated' => 0], ['activated' => true]],
        ];
    }

    #[DataProvider('changedCredentials')]
    public function testCredentialChangesRevokeAllTargetTokensButKeepOtherUsersTokens(array $initial, array $body): void
    {
        $this->initial($initial);
        $this->users->updateUser(3, false, $body);
        self::assertTrue($this->db->lockedInsideTransaction);
        self::assertSame([3], $this->db->query('SELECT sessionId FROM Session ORDER BY sessionId')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM Event')->fetchColumn());
        foreach ($body as $field => $value) {
            self::assertSame((int) $value, (int) $this->db->query('SELECT ' . $field . ' FROM User WHERE userId=3')->fetchColumn());
        }
    }

    public static function unchangedCredentials(): array
    {
        return [
            'same ordinary role' => [[], ['role' => 0]],
            'same administrator role' => [['role' => 1], ['role' => 1]],
            'same active state' => [[], ['activated' => true]],
            'same inactive state' => [['activated' => 0], ['activated' => false]],
            'name and email' => [[], ['firstName' => 'Updated', 'email' => 'updated@example.test']],
            'profile fields with unchanged credentials' => [[], ['firstName' => 'Updated', 'role' => 0, 'activated' => true]],
        ];
    }

    #[DataProvider('unchangedCredentials')]
    public function testOrdinaryEditsAndRepeatedCredentialValuesPreserveRememberTokens(array $initial, array $body): void
    {
        $this->initial($initial);
        $this->users->updateUser(3, false, $body);
        self::assertTrue($this->db->lockedInsideTransaction);
        self::assertSame([1, 2, 3], $this->db->query('SELECT sessionId FROM Session ORDER BY sessionId')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM Event')->fetchColumn());
    }

    public static function concurrentRoles(): array
    {
        return ['already updated before lock' => [1, false], 'changed since initial read' => [0, true]];
    }

    #[DataProvider('concurrentRoles')]
    public function testRevocationComparesTheLockedCurrentRowRatherThanTheEarlierRead(int $requestedRole, bool $revoke): void
    {
        $this->db->beforeLockedRead = static function (PDO $db): void {
            $db->exec('UPDATE User SET role=1 WHERE userId=3');
        };
        $this->users->updateUser(3, false, ['role' => $requestedRole]);
        self::assertTrue($this->db->lockedInsideTransaction);
        self::assertSame($revoke ? [3] : [1, 2, 3], $this->db->query('SELECT sessionId FROM Session ORDER BY sessionId')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame($requestedRole, (int) $this->db->query('SELECT role FROM User WHERE userId=3')->fetchColumn());
    }

    public function testAuditFailureRollsBackBothCredentialEditAndTokenRevocation(): void
    {
        $this->db->exec("CREATE TRIGGER reject_audit BEFORE INSERT ON Event BEGIN SELECT RAISE(ABORT,'Synthetic audit failure'); END");
        try {
            $this->users->updateUser(3, false, ['role' => 1]);
            self::fail('The synthetic audit failure was ignored.');
        } catch (PDOException $error) {
            self::assertStringContainsString('Synthetic audit failure', $error->getMessage());
        }
        self::assertFalse($this->db->inTransaction());
        self::assertSame(0, (int) $this->db->query('SELECT role FROM User WHERE userId=3')->fetchColumn());
        self::assertSame([1, 2, 3], $this->db->query('SELECT sessionId FROM Session ORDER BY sessionId')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM Event')->fetchColumn());
    }

    private function initial(array $values): void
    {
        foreach ($values as $field => $value) {
            $this->db->prepare('UPDATE User SET ' . $field . '=? WHERE userId=3')->execute([$value]);
        }
    }
}
