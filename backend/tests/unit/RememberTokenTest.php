<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use \Chronos\Infrastructure\Security\RememberToken;

final class RememberTokenTest extends TestCase
{
    public static function binaryTokens(): array
    {
        return [
            [str_repeat("\0", 33)], ["\0" . str_repeat('a', 32)],
            [str_repeat('z', 32) . "\0"], [str_repeat("\xff", 33)],
            ["\xff\xfe\0" . str_repeat('x', 30)], [str_repeat('a', 33)],
        ];
    }

    #[DataProvider('binaryTokens')]
    public function testBinaryTokensHaveSafeHashesThatFitTheExistingColumn(string $token): void
    {
        $hash = \Chronos\Infrastructure\Security\RememberToken::hash($token);
        self::assertStringStartsWith('b64:$2', $hash);
        self::assertLessThanOrEqual(128, strlen($hash));
        self::assertTrue(\Chronos\Infrastructure\Security\RememberToken::verify($token, $hash));
        self::assertFalse(\Chronos\Infrastructure\Security\RememberToken::verify(substr_replace($token, $token[0] === 'a' ? 'b' : 'a', 0, 1), $hash));
    }

    public function testPreviouslyIssuedRawBcryptTokensContinueToVerify(): void
    {
        $token = str_repeat('L', 33);
        $hash = password_hash($token, PASSWORD_BCRYPT);
        self::assertTrue(\Chronos\Infrastructure\Security\RememberToken::verify($token, $hash));
        self::assertFalse(\Chronos\Infrastructure\Security\RememberToken::verify(str_repeat('X', 33), $hash));
        self::assertFalse(\Chronos\Infrastructure\Security\RememberToken::verify(str_repeat("\0", 33), $hash));
    }

    public function testCookieWireFormatIsUnchangedAndBinarySafe(): void
    {
        $selector = base64_encode('123456789');
        $token = "\0\xff" . str_repeat('x', 31);
        self::assertSame([$selector, $token], \Chronos\Infrastructure\Security\RememberToken::decodeCookie($selector . ':' . base64_encode($token)));
    }

    public static function invalidTokens(): array
    {
        return [[null], [false], [[]], [33], [''], [str_repeat('x', 32)], [str_repeat('x', 34)]];
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidRawTokenInputsCannotAuthenticate(mixed $token): void
    {
        self::assertFalse(\Chronos\Infrastructure\Security\RememberToken::verify($token, 'invalid'));
        $this->expectException(\InvalidArgumentException::class);
        \Chronos\Infrastructure\Security\RememberToken::hash($token);
    }

    public function testMalformedCookiesAndHashesAreRejectedWithoutWarnings(): void
    {
        foreach ([null, [], '', 'missing-colon', ':', 'a:b:c', '!!!:???', base64_encode('123456789') . ':' . base64_encode('short'), base64_encode('123456789') . ': ' . base64_encode(str_repeat('a', 33))] as $cookie) {
            self::assertNull(\Chronos\Infrastructure\Security\RememberToken::decodeCookie($cookie));
        }
        foreach ([null, [], '', 'b64:', 'b64:invalid', '$2y$12$short', 'b64:$2y$12$short'] as $hash) {
            self::assertFalse(\Chronos\Infrastructure\Security\RememberToken::verify(str_repeat('a', 33), $hash));
        }
    }

    public function testNativeVerificationErrorsRejectTheTokenWithoutEscapingTheBoundary(): void
    {
        $script = <<<'PHP'
namespace Chronos\Infrastructure\Security {
    function password_verify(string $password, string $hash): bool {
        throw new \ValueError('Synthetic native verification error');
    }
}
namespace {
    require $argv[1];
    echo \Chronos\Infrastructure\Security\RememberToken::verify(str_repeat('L', 33), $argv[2]) ? 'accepted' : 'rejected';
}
PHP;
        $process = proc_open([PHP_BINARY, '-n', '-r', $script,
            CHRONOS_BACKEND_ROOT . '/src/Infrastructure/Security/RememberToken.php',
            password_hash(str_repeat('L', 33), PASSWORD_BCRYPT)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame('rejected', $output);
    }
}
