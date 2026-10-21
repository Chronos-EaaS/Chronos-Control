<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RememberCookieTest extends TestCase
{
    public static function requests(): array
    {
        return [
            'HTTP alias' => [['SERVER_NAME' => 'canonical.internal', 'HTTP_HOST' => 'chronos.example.test', 'SERVER_PORT' => '80'], false],
            'HTTPS alias' => [['SERVER_NAME' => 'canonical.internal', 'HTTP_HOST' => 'chronos.example.test', 'HTTPS' => 'on', 'SERVER_PORT' => '443'], true],
            'HTTPS port' => [['SERVER_NAME' => 'canonical.internal', 'HTTP_HOST' => 'chronos.example.test', 'SERVER_PORT' => '443'], true],
            'HTTP explicitly off' => [['SERVER_NAME' => 'canonical.internal', 'HTTPS' => 'off', 'SERVER_PORT' => '80'], false],
            'no canonical name' => [['HTTP_HOST' => 'chronos.example.test', 'SERVER_PORT' => '80'], false],
        ];
    }

    #[DataProvider('requests')]
    public function testCredentialIsHardenedAndHostOnlyEvenWhenApacheUsesACanonicalAlias(array $server, bool $secure): void
    {
        $calls = $this->cookies($server, 'synthetic-selector:synthetic-authenticator', 2000000000);
        self::assertCount(isset($server['SERVER_NAME']) ? 2 : 1, $calls);
        $issued = array_pop($calls);
        self::assertSame('rememberMe', $issued['name']);
        self::assertSame('synthetic-selector:synthetic-authenticator', $issued['value']);
        self::assertSame(['expires' => 2000000000, 'path' => '/', 'secure' => $secure,
            'httponly' => true, 'samesite' => 'Lax'], $issued['options']);
        if ($calls !== []) {
            self::assertSame('', $calls[0]['value']);
            self::assertSame(1, $calls[0]['options']['expires']);
            self::assertSame($server['SERVER_NAME'], $calls[0]['options']['domain']);
        }
    }

    public function testSignOutExpiresBothOldDomainAndCurrentHostCookiesAtTheRootPath(): void
    {
        $calls = $this->cookies(['SERVER_NAME' => 'chronos.example.test', 'SERVER_PORT' => '443'], '', 1);
        self::assertCount(2, $calls);
        foreach ($calls as $call) {
            self::assertSame('rememberMe', $call['name']);
            self::assertSame('', $call['value']);
            self::assertSame(1, $call['options']['expires']);
            self::assertSame('/', $call['options']['path']);
            self::assertTrue($call['options']['secure']);
            self::assertTrue($call['options']['httponly']);
            self::assertSame('Lax', $call['options']['samesite']);
        }
        self::assertSame('chronos.example.test', $calls[0]['options']['domain']);
        self::assertArrayNotHasKey('domain', $calls[1]['options']);
    }

    private function cookies(array $server, string $value, int $expires): array
    {
        // Isolate the header recorder so it cannot replace cookie handling in
        // another test or rely on CLI SAPIs retaining HTTP response headers.
        $script = 'namespace Chronos\\Infrastructure\\Security { function setcookie($name, $value, $options) {'
            . '$GLOBALS["cookies"][] = compact("name", "value", "options"); return true; } } namespace {'
            . 'require ' . var_export(CHRONOS_BACKEND_ROOT . '/vendor/autoload.php', true) . ';'
            . '$_SERVER = ' . var_export($server, true) . '; $GLOBALS["cookies"] = [];'
            . '\\Chronos\\Infrastructure\\Security\\RememberCookie::write(' . var_export($value, true) . ', ' . $expires . ');'
            . 'echo json_encode($GLOBALS["cookies"], JSON_THROW_ON_ERROR); }';
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
