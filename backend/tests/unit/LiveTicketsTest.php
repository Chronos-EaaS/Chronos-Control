<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Shared\Dto;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Realtime\LiveTickets;
use Chronos\Infrastructure\Security\SessionEpoch;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LiveTicketsTest extends TestCase
{
    private string $root;
    private PDO $db;
    private array $row;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/chronos-live-auth-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root . '/php-sessions', 0700);
        session_save_path($this->root . '/php-sessions');
        session_id(bin2hex(random_bytes(16)));
        session_start();
        $this->db = new PDO('sqlite::memory:');
        $this->db->exec('CREATE TABLE User(userId INTEGER PRIMARY KEY,username TEXT,password TEXT,firstname TEXT,lastname TEXT,email TEXT,gender INTEGER,role INTEGER,enabled INTEGER,activated INTEGER,created TEXT,lastLogin TEXT)');
        $this->db->exec("INSERT INTO User VALUES(1,'owner','password-hash','Synthetic','Owner','owner@example.test',0,0,1,1,NULL,NULL),(2,'admin','admin-hash','Synthetic','Admin','admin@example.test',0,1,1,1,NULL,NULL)");
        $this->row = $this->db->query('SELECT * FROM User WHERE userId=1')->fetch(PDO::FETCH_ASSOC);
        $this->bind();
    }

    private function bind(?array $original = null): void
    {
        $parts = fn(array $row): array => [(int) $row['userId'], (string) $row['password'], (int) $row['role'], (bool) $row['enabled'], (bool) $row['activated']];
        $_SESSION['v3Identity'] = ['epoch' => SessionEpoch::current($this->root), 'credentials' => hash('sha256', json_encode([...$parts($this->row), ...$original === null ? [] : $parts($original)], JSON_THROW_ON_ERROR))];
        $_SESSION['v3Csrf'] = str_repeat('a', 64);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testTicketIsOneUseAndNeverExposesThePhpSessionOrCredentialProof(): void
    {
        $tickets = new LiveTickets($this->root, $this->db);
        $ticket = $tickets->issueForCurrentSession(Dto::user($this->row));
        self::assertStringNotContainsString(session_id(), json_encode($ticket));
        self::assertStringNotContainsString('password-hash', json_encode($ticket));
        $lease = $tickets->consume($ticket['ticket']);
        self::assertNotNull($lease);
        self::assertNull($tickets->consume($ticket['ticket']));
        self::assertSame(1, $tickets->validate($lease)['actor']['id']);
        self::assertSame(str_repeat('a', 64), $tickets->validate($lease)['session']['csrfToken']);
        self::assertSame(0600, fileperms($this->root . '/locks/live-auth/leases/' . $lease . '.json') & 0777);
        $tickets->release($lease);
        self::assertNull($tickets->validate($lease));
    }

    public function testRotationLogoutAndCredentialChangesRevokeEstablishedLeases(): void
    {
        $tickets = new LiveTickets($this->root, $this->db);
        $lease = $tickets->consume($tickets->issueForCurrentSession(Dto::user($this->row))['ticket']);
        $tickets->revokeCurrent();
        self::assertNull($tickets->validate($lease));
        $lease = $tickets->consume($tickets->issueForCurrentSession(Dto::user($this->row))['ticket']);
        $this->db->exec("UPDATE User SET password='new-password' WHERE userId=1");
        self::assertNull($tickets->validate($lease));
        $this->db->exec("UPDATE User SET password='password-hash' WHERE userId=1");
        $lease = $tickets->consume($tickets->issueForCurrentSession(Dto::user($this->row))['ticket']);
        SessionEpoch::rotate($this->root);
        self::assertNull($tickets->validate($lease));
    }

    public function testImpersonatedLeasesAlsoValidateTheirOriginalAdministrator(): void
    {
        $original = $this->db->query('SELECT * FROM User WHERE userId=2')->fetch(PDO::FETCH_ASSOC);
        $this->bind($original);
        $tickets = new LiveTickets($this->root, $this->db);
        $snapshot = ['impersonation' => ['active' => true, 'originalUser' => Dto::user($original)]];
        $lease = $tickets->consume($tickets->issueForCurrentSession(Dto::user($this->row), $snapshot)['ticket']);
        self::assertTrue($tickets->validate($lease)['session']['impersonation']['active']);
        $this->db->exec('UPDATE User SET enabled=0 WHERE userId=2');
        self::assertNull($tickets->validate($lease));
    }

    public function testExpiredAndMalformedTicketsAreRejectedWithoutOpeningLeases(): void
    {
        $tickets = new LiveTickets($this->root, $this->db);
        $ticket = $tickets->issueForCurrentSession(Dto::user($this->row));
        $file = $this->root . '/locks/live-auth/tickets/' . hash('sha256', $ticket['ticket']) . '.json';
        $record = json_decode(file_get_contents($file), true);
        $record['ticketExpiresAt'] = time() - 1;
        file_put_contents($file, json_encode($record));
        self::assertNull($tickets->consume($ticket['ticket']));
        self::assertNull($tickets->consume('../outside'));
        self::assertNull($tickets->validate(str_repeat('0', 64)));
    }

    public function testForgedHttpCredentialProofCannotMintATicket(): void
    {
        $_SESSION['v3Identity']['credentials'] = str_repeat('0', 64);
        $this->expectException(HttpError::class);
        (new LiveTickets($this->root, $this->db))->issueForCurrentSession(Dto::user($this->row));
    }

    public function testPhpSessionGarbageCollectionAndLeaseExpiryRevokeSockets(): void
    {
        $tickets = new LiveTickets($this->root, $this->db);
        $lease = $tickets->consume($tickets->issueForCurrentSession(Dto::user($this->row))['ticket']);
        $file = $this->root . '/locks/live-auth/leases/' . $lease . '.json';
        $record = json_decode(file_get_contents($file), true);
        $record['expiresAt'] = time() - 1;
        file_put_contents($file, json_encode($record));
        self::assertNull($tickets->validate($lease));
        $lease = $tickets->consume($tickets->issueForCurrentSession(Dto::user($this->row))['ticket']);
        unlink($this->root . '/php-sessions/sess_' . session_id());
        self::assertNull($tickets->validate($lease));
    }

    public function testBoundedGenerationCleanupRetainsCurrentAndRenewedLeases(): void
    {
        $tickets = new LiveTickets($this->root, $this->db);
        $lease = $tickets->consume($tickets->issueForCurrentSession(Dto::user($this->row))['ticket']);
        $directory = $this->root . '/locks/live-auth/sessions/';
        $expired = $directory . str_repeat('0', 64) . '.json';
        file_put_contents($expired, json_encode(['generation' => str_repeat('0', 64), 'expiresAt' => time() - 1]));
        chmod($expired, 0600);
        $current = $directory . hash('sha256', session_id()) . '.json';
        $before = json_decode(file_get_contents($current), true);
        $tickets->issueForCurrentSession(Dto::user($this->row));
        self::assertFileDoesNotExist($expired);
        self::assertNotNull($tickets->validate($lease));
        $after = json_decode(file_get_contents($current), true);
        self::assertSame($before['generation'], $after['generation']);
        self::assertGreaterThanOrEqual($before['expiresAt'], $after['expiresAt']);
    }
}
