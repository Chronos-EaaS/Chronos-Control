<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\TestCase;

final class EntrypointTlsTest extends TestCase
{
    public function testBrowserAndV1AgentTlsRequirementsAreIndependentAtTheActualEntrypoint(): void
    {
        $root = sys_get_temp_dir() . '/chronos-entrypoint-tls-' . bin2hex(random_bytes(8));
        foreach (['webroot/ui', 'vendor', 'api/ui', 'api/v1'] as $directory) mkdir($root . '/' . $directory, 0700, true);
        copy(CHRONOS_BACKEND_ROOT . '/public/index.php', $root . '/webroot/index.php');
        copy(CHRONOS_BACKEND_ROOT . '/resources/constants.php', $root . '/constants.php');
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
namespace Chronos\Infrastructure\Persistence { final class Connection { public static function configureFromConstants(): void {} } }
namespace Chronos\Infrastructure\Security { final class Authentication { public static function getInstance(): object { return new \stdClass(); } } }
namespace Chronos\Infrastructure\Logging { final class Logger { public static function getInstance(): self { return new self(); } public function setLogLevelThreshold(mixed $level): void {} public function debug(string $message): void {} } }
namespace {
PHP;
        // Real routing and SPA classes execute; only downstream database/auth
        // services and API handlers are inert fixtures beyond the TLS boundary.
        foreach (['Http/Api/Ui/ApiPath.php', 'Http/BrowserUi.php'] as $source) {
            $stubs .= "\nrequire " . var_export(CHRONOS_BACKEND_ROOT . '/src/' . $source, true) . ';';
        }
        file_put_contents($root . '/vendor/autoload.php', $stubs . "\n}\n");
        file_put_contents($root . '/api/ui/bootstrap.php', '<?php echo "TLS fixture UI handler";');
        file_put_contents($root . '/api/v1/bootstrap.php', '<?php echo "TLS fixture v1 handler";');

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
            self::assertTrue($ready, (string) file_get_contents($root . '/server.log'));
            foreach ([[false, false], [true, false], [false, true], [true, true]] as [$browserTls, $agentTls]) {
                foreach ([
                    ['GET', '/login', false, 200, '<app-root>TLS fixture browser</app-root>'],
                    ['POST', '/api/ui/session?returnUrl=%2Fprojects', false, 200, 'TLS fixture UI handler'],
                    ['GET', '/api/ui/meta', false, 200, 'TLS fixture UI handler'],
                    ['GET', '/api/ui', false, 200, 'TLS fixture UI handler'],
                    ['POST', '/api/v1/job/id=1', true, 200, 'TLS fixture v1 handler'],
                    ['POST', '/api/v1/cem/uniqueId=fixture', true, 200, 'TLS fixture v1 handler'],
                    ['GET', '/api/v1', true, 200, 'TLS fixture v1 handler'],
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
        } finally {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
            }
            rmdir($root);
        }
    }

    private static function request(string $address, string $method, string $path, bool $browserTls, bool $agentTls): array
    {
        $socket = stream_socket_client('tcp://' . $address, $errorNumber, $errorMessage, 2);
        self::assertIsResource($socket, $errorMessage);
        stream_set_timeout($socket, 2);
        try {
            $request = $method . ' ' . $path . " HTTP/1.1\r\nHost: chronos.invalid\r\nConnection: close\r\n"
                . 'X-Fixture-Browser-TLS: ' . (int) $browserTls . "\r\nX-Fixture-Agent-TLS: " . (int) $agentTls . "\r\nContent-Length: 0\r\n\r\n";
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
