<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\TestCase;

final class EntrypointTlsTest extends TestCase
{
    public function testBrowserAndV1AgentTlsRequirementsAreIndependentAtTheActualEntrypoint(): void
    {
        $root = sys_get_temp_dir() . '/chronos-entrypoint-tls-' . bin2hex(random_bytes(8));
        foreach (['webroot/ui', 'vendor', 'api/ui', 'api/v1', 'app/Infrastructure/Deployment'] as $directory) mkdir($root . '/' . $directory, 0700, true);
        copy(CHRONOS_BACKEND_ROOT . '/public/index.php', $root . '/webroot/index.php');
        copy(CHRONOS_BACKEND_ROOT . '/resources/constants.php', $root . '/constants.php');
        copy(CHRONOS_BACKEND_ROOT . '/src/Infrastructure/Deployment/ApplicationLock.php', $root . '/app/Infrastructure/Deployment/ApplicationLock.php');
        file_put_contents($root . '/database.php', <<<'PHP'
<?php return ['format' => 1, 'run' => static function (object $database, string $action): array {
    if (($_SERVER['HTTP_X_FIXTURE_SCHEMA_PENDING'] ?? '') === '1') throw new RuntimeException('Pending synthetic schema.');
    return [];
}];
PHP);
        file_put_contents($root . '/webroot/ui/index.html', '<app-root>TLS fixture browser</app-root>');
        file_put_contents($root . '/router.php', '<?php require __DIR__ . "/webroot/index.php";');
        file_put_contents($root . '/config.php', <<<'PHP'
<?php
define('REQUIRE_SSL', ($_SERVER['HTTP_X_FIXTURE_BROWSER_TLS'] ?? '') === '1');
define('REQUIRE_API_SSL', ($_SERVER['HTTP_X_FIXTURE_AGENT_TLS'] ?? '') === '1');
define('DEBUGMODE', false);
define('LOG_LEVEL', 0);
PHP);
        $stubs = <<<'PHP'
<?php
namespace Chronos\Infrastructure\Persistence { final class Connection { public static function configureFromConstants(): void {} public static function get(): object { return new \stdClass(); } } }
namespace Chronos\Infrastructure\Security { final class Authentication { public static function getInstance(): object { return new \stdClass(); } } }
namespace Chronos\Infrastructure\Logging { final class Logger { public static function getInstance(): self { return new self(); } public function setLogLevelThreshold(mixed $level): void {} public function debug(string $message): void {} } }
namespace {
PHP;
        // Real routing and SPA classes execute; only downstream database/auth
        // services and API handlers are inert fixtures beyond the TLS boundary.
        foreach (['Http/Api/Ui/ApiPath.php', 'Http/BrowserUi.php', 'Http/Api/V1/AgentRouter.php'] as $source) {
            $stubs .= "\nrequire " . var_export(CHRONOS_BACKEND_ROOT . '/src/' . $source, true) . ';';
        }
        file_put_contents($root . '/vendor/autoload.php', $stubs . "\n}\n");
        file_put_contents($root . '/api/ui/bootstrap.php', '<?php echo "TLS fixture UI handler";');
        file_put_contents($root . '/api/v1/bootstrap.php', <<<'PHP'
<?php
if (($_SERVER['HTTP_X_FIXTURE_ROUTE_DETAILS'] ?? '') === '1') {
    header('Content-Type: application/json');
    echo json_encode(['path' => $requestPath, 'method' => $_SERVER['REQUEST_METHOD'], 'route' => \Chronos\Http\Api\V1\AgentRouter::route($requestPath, $_SERVER['REQUEST_METHOD']), 'body' => file_get_contents('php://input')]);
} else echo 'TLS fixture v1 handler';
PHP);

        $reservation = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertIsResource($reservation, $errorMessage);
        $address = stream_socket_get_name($reservation, false);
        fclose($reservation);
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, $root . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes, $root);
        try {
            self::assertIsResource($process);
            $ready = false;
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $probe = @stream_socket_client('tcp://' . $address, $errorNumber, $errorMessage, 0.05);
                if (is_resource($probe)) { fclose($probe); $ready = true; break; }
                usleep(10_000);
            }
            foreach ([['GET', '/api/ui/meta'], ['GET', '/api/v1/job/id=1'], ['GET', '/index.php?/api/v1/job/id=1'], ['POST', '/index.php?/api/v1/cem'], ['PATCH', '/index.php?/api/v1/job/id=1']] as [$method, $path]) {
                [$status, $headers, $body] = self::request($address, $method, $path, false, false, true);
                self::assertSame(503, $status);
                self::assertSame('database_upgrade_required', json_decode($body, true, 8, JSON_THROW_ON_ERROR)['error']['code']);
                self::assertContains('Cache-Control: no-store', $headers);
                self::assertStringNotContainsString('handler', $body);
            }
            [$status, , $body] = self::request($address, 'GET', '/login', false, false, true);
            self::assertSame(200, $status);
            self::assertSame('<app-root>TLS fixture browser</app-root>', $body);
            [$status, $headers] = self::request($address, 'GET', '/api/ui/meta', true, false, true);
            self::assertSame(302, $status);
            self::assertContains('Location: https://chronos.invalid/api/ui/meta', $headers);
            self::assertTrue($ready, (string) file_get_contents($root . '/server.log'));
            foreach ([[false, false], [true, false], [false, true], [true, true]] as [$browserTls, $agentTls]) {
                foreach ([
                    ['GET', '/login', false, 200, '<app-root>TLS fixture browser</app-root>'],
                    ['POST', '/api/ui/session?returnUrl=%2Fprojects', false, 200, 'TLS fixture UI handler'],
                    ['GET', '/api/ui/meta', false, 200, 'TLS fixture UI handler'],
                    ['GET', '/api/ui/meta?/api/v1/job/id=1', false, 200, 'TLS fixture UI handler'],
                    ['GET', '/api/ui', false, 200, 'TLS fixture UI handler'],
                    ['POST', '/api/v1/job/id=1', true, 200, 'TLS fixture v1 handler'],
                    ['POST', '/api/v1/cem/uniqueId=fixture', true, 200, 'TLS fixture v1 handler'],
                    ['GET', '/api/v1', true, 200, 'TLS fixture v1 handler'],
                    ['GET', '/index.php?/api/v1', true, 200, 'TLS fixture v1 handler'],
                    ['GET', '/index.php?/api/v1/job/id=1', true, 200, 'TLS fixture v1 handler'],
                    ['POST', '/index.php?/api/v1/cem/uniqueId=node%2FZ%C3%BCrich', true, 200, 'TLS fixture v1 handler'],
                    ['PATCH', '/index.php?/api/v1/job/id=1', true, 200, 'TLS fixture v1 handler'],
                    ['GET', '/index.php?/api/v10/job', false, 404, 'File not found.'],
                    ['GET', '/index.php?next=/api/v1/job/id=1', false, 404, 'File not found.'],
                    ['GET', '/index.php?/api/ui/meta', false, 404, 'File not found.'],
                    ['GET', '/login?/api/v1/job/id=1', false, 200, '<app-root>TLS fixture browser</app-root>'],
                    ['GET', '/api/v10/job', false, 404, null],
                    ['GET', '/api/uix', false, 404, null],
                ] as [$method, $path, $agent, $status, $body]) {
                    $label = $method . ' ' . $path . ' browserTLS=' . (int) $browserTls . ' agentTLS=' . (int) $agentTls;
                    [$actualStatus, $headers, $actualBody] = self::request($address, $method, $path, $browserTls, $agentTls);
                    if ($agent ? $agentTls : $browserTls) {
                        self::assertSame(302, $actualStatus, $label);
                        self::assertContains('Location: https://chronos.invalid' . $path, $headers, $label);
                        self::assertSame('', $actualBody, 'TLS redirect must precede downstream handling: ' . $label);
                    } else {
                        self::assertSame($status, $actualStatus, $label);
                        if ($body !== null) self::assertSame($body, $actualBody, $label);
                        else self::assertSame('api_not_available', json_decode($actualBody, true, 8, JSON_THROW_ON_ERROR)['error']['code'], $label);
                    }
                }
            }
            foreach (['GET', 'POST', 'PATCH'] as $method) {
                $path = '/api/v1/job/id=42/supports=fixture%2FZ%C3%BCrich/environment=local+test/token=literal%252F%26%3D';
                $payload = $method === 'GET' ? '' : 'message=A%26B+%2B+Z%C3%BCrich';
                [$status, , $body] = self::request($address, $method, '/index.php?' . $path, true, false, false, $payload, true);
                self::assertSame(200, $status);
                $value = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
                self::assertSame($path, $value['path']);
                self::assertSame($method, $value['method']);
                self::assertSame($payload, $value['body']);
                self::assertTrue($value['route']['allowed']);
                self::assertSame(['id' => '42', 'supports' => 'fixture/Zürich', 'environment' => 'local test', 'token' => 'literal%2F&='], $value['route']['variables']);
            }
            file_put_contents($root . '/.chronos-maintenance.json', '{}');
            foreach (['GET', 'POST', 'PATCH'] as $method) {
                [$status, $headers, $body] = self::request($address, $method, '/index.php?/api/v1/job/id=1', true, true);
                self::assertSame(503, $status);
                self::assertContains('Content-Type: application/json; charset=utf-8', $headers);
                self::assertContains('Cache-Control: no-store', $headers);
                self::assertSame('maintenance', json_decode($body, true, 8, JSON_THROW_ON_ERROR)['error']['code']);
            }
        } finally {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
            }
            rmdir($root);
        }
    }

    private static function request(string $address, string $method, string $path, bool $browserTls, bool $agentTls, bool $schemaPending = false, string $payload = '', bool $routeDetails = false): array
    {
        $socket = stream_socket_client('tcp://' . $address, $errorNumber, $errorMessage, 2);
        self::assertIsResource($socket, $errorMessage);
        stream_set_timeout($socket, 2);
        try {
            $request = $method . ' ' . $path . " HTTP/1.1\r\nHost: chronos.invalid\r\nConnection: close\r\n"
                . 'X-Fixture-Browser-TLS: ' . (int) $browserTls . "\r\nX-Fixture-Agent-TLS: " . (int) $agentTls . "\r\nX-Fixture-Schema-Pending: " . (int) $schemaPending
                . "\r\nX-Fixture-Route-Details: " . (int) $routeDetails . "\r\nContent-Length: " . strlen($payload) . "\r\n\r\n" . $payload;
            self::assertSame(strlen($request), fwrite($socket, $request));
            $response = stream_get_contents($socket);
            self::assertFalse(stream_get_meta_data($socket)['timed_out'], 'The local PHP entrypoint did not finish its response.');
        } finally { fclose($socket); }
        [$head, $body] = explode("\r\n\r\n", $response, 2);
        $headers = explode("\r\n", $head);
        self::assertSame(1, preg_match('#^HTTP/1\.[01] ([0-9]{3})#', array_shift($headers), $match));
        return [(int) $match[1], $headers, $body];
    }
}
