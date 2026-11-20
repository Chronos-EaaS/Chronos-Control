<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Http\Api\Ui\Kernel;
use Chronos\Http\Api\Ui\WorkflowRoutes;
use Chronos\Http\HttpError;
use Chronos\Http\Request;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Authentication;
use Chronos\Infrastructure\Security\Sessions;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobLogAnalysisRouteTest extends TestCase
{
    private const PATH = '/jobs/11/log/analysis';
    private const LOG = "INFO setup complete\n[ERROR] connection refused\n";
    private string $root;
    private string $log;
    private PDO $database;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/chronos-log-route-' . bin2hex(random_bytes(8));
        $directory = $this->root . '/webroot/data/log';
        mkdir($directory, 0700, true);
        $this->log = $directory . '/11.log';
        file_put_contents($this->log, self::LOG);
        $this->database = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $concat = static fn(string $separator, ...$parts): string => implode($separator, array_filter($parts, static fn(mixed $part): bool => $part !== null));
        if ($this->database instanceof \Pdo\Sqlite) $this->database->createFunction('CONCAT_WS', $concat);
        else $this->database->sqliteCreateFunction('CONCAT_WS', $concat);
        $this->database->exec(<<<'SQL'
            CREATE TABLE System (systemId INTEGER, name TEXT);
            INSERT INTO System VALUES (1, 'Synthetic system');
            CREATE TABLE User (userId INTEGER, firstname TEXT, lastname TEXT);
            INSERT INTO User VALUES (1, 'Synthetic', 'Owner');
            CREATE TABLE Project (projectId INTEGER, systemId INTEGER, userId INTEGER, name TEXT);
            INSERT INTO Project VALUES (1, 1, 1, 'Synthetic project');
            CREATE TABLE ProjectUser (projectUserId INTEGER, projectId INTEGER, userId INTEGER);
            INSERT INTO ProjectUser VALUES (1, 1, 2);
            CREATE TABLE Experiment (experimentId INTEGER, internalId INTEGER, projectId INTEGER, name TEXT);
            INSERT INTO Experiment VALUES (1, 1, 1, 'Synthetic experiment');
            CREATE TABLE Evaluation (evaluationId INTEGER, internalId INTEGER, experimentId INTEGER, name TEXT);
            INSERT INTO Evaluation VALUES (1, 1, 1, 'Synthetic evaluation');
            CREATE TABLE Job (jobId INTEGER, evaluationId INTEGER, systemId INTEGER, status INTEGER, configuration TEXT NOT NULL);
            INSERT INTO Job VALUES (11, 1, 1, 3, '{}');
            SQL);
    }

    protected function tearDown(): void
    {
        unlink($this->log);
        rmdir(dirname($this->log));
        rmdir($this->root . '/webroot/data');
        rmdir($this->root . '/webroot');
        rmdir($this->root);
    }

    public function testKernelRequiresAUserBeforeAccessingAnalysis(): void
    {
        $auth = $this->createStub(Authentication::class);
        $auth->method('isLoggedIn')->willReturn(false);
        $request = new Request('GET', '/api/ui' . self::PATH);
        try {
            (new Kernel($request, new Sessions($auth), $this->root))->handle();
            self::fail('Expected authentication to be required.');
        } catch (HttpError $error) {
            self::assertSame(401, $error->status);
        }
    }

    public function testProjectMemberCanScanAndContinueWithoutMutatingLogOrJob(): void
    {
        $response = $this->dispatch(2);
        self::assertSame(200, $response['status']);
        $data = $response['body']['data'];
        self::assertCount(1, $data['findings']);
        self::assertSame('[ERROR] connection refused', $data['findings'][0]['excerpt']);
        $next = $this->dispatch(2, '?' . http_build_query(['cursor' => $data['cursor']]))['body']['data'];
        self::assertSame([], $next['findings']);
        self::assertSame(strlen(self::LOG), $next['offset']);
        self::assertSame(self::LOG, file_get_contents($this->log));
        self::assertSame(3, (int) $this->database->query('SELECT status FROM Job WHERE jobId=11')->fetchColumn());
        self::assertFileDoesNotExist($this->log . '.epoch');
    }

    public static function rejectedRequests(): iterable
    {
        yield 'outsider' => [3, 'GET', '', 'forbidden', 403];
        yield 'delete' => [1, 'DELETE', '', 'method_not_allowed', 405];
        yield 'client byte limit' => [2, 'GET', '?limit=99999999', 'validation_failed', 422];
        yield 'array cursor' => [2, 'GET', '?cursor[]=invalid', 'validation_failed', 422];
        yield 'invalid cursor' => [2, 'GET', '?cursor=invalid', 'validation_failed', 422];
        $epoch = str_repeat('a', 64);
        yield 'offset beyond size' => [2, 'GET', '?cursor=' . $epoch . ':15:10', 'validation_failed', 422];
        yield 'unsafe integer' => [2, 'GET', '?cursor=' . $epoch . ':0:9007199254740992', 'validation_failed', 422];
    }

    #[DataProvider('rejectedRequests')]
    public function testRejectsUnauthorizedOrInvalidRequests(int $actor, string $method, string $query, string $code, int $status): void
    {
        try {
            $this->dispatch($actor, $query, $method);
            self::fail('Expected the request to be rejected.');
        } catch (HttpError $error) {
            self::assertSame($status, $error->status);
            self::assertSame($code, $error->errorCode);
        }
    }

    public function testScanReturnsARetryableConflictWhileWriterHoldsTheLog(): void
    {
        $writer = fopen($this->log, 'ab');
        self::assertTrue(flock($writer, LOCK_EX | LOCK_NB));
        try {
            $this->dispatch(1);
            self::fail('Expected a retryable conflict.');
        } catch (HttpError $error) {
            self::assertSame(409, $error->status);
            self::assertSame('log_read_conflict', $error->errorCode);
        } finally {
            fclose($writer);
        }
        self::assertSame(200, $this->dispatch(1)['status']);
    }

    private function dispatch(int $actor, string $query = '', string $method = 'GET'): array
    {
        $request = new Request($method, '/api/ui' . self::PATH . $query);
        return (new WorkflowRoutes($request, new Store($this->database), ['id' => $actor, 'role' => 0], $this->root))->handle(self::PATH);
    }
}
