<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RememberToken_Library;

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
        $hash = RememberToken_Library::hash($token);
        self::assertStringStartsWith('b64:$2', $hash);
        self::assertLessThanOrEqual(128, strlen($hash));
        self::assertTrue(RememberToken_Library::verify($token, $hash));
        self::assertFalse(RememberToken_Library::verify(substr_replace($token, $token[0] === 'a' ? 'b' : 'a', 0, 1), $hash));
    }

    public function testPreviouslyIssuedRawBcryptTokensContinueToVerify(): void
    {
        $token = str_repeat('L', 33);
        $hash = password_hash($token, PASSWORD_BCRYPT);
        self::assertTrue(RememberToken_Library::verify($token, $hash));
        self::assertFalse(RememberToken_Library::verify(str_repeat('X', 33), $hash));
        self::assertFalse(RememberToken_Library::verify(str_repeat("\0", 33), $hash));
    }

    public function testCookieWireFormatIsUnchangedAndBinarySafe(): void
    {
        $selector = base64_encode('123456789');
        $token = "\0\xff" . str_repeat('x', 31);
        self::assertSame([$selector, $token], RememberToken_Library::decodeCookie($selector . ':' . base64_encode($token)));
    }

    public static function invalidTokens(): array
    {
        return [[null], [false], [[]], [33], [''], [str_repeat('x', 32)], [str_repeat('x', 34)]];
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidRawTokenInputsCannotAuthenticate(mixed $token): void
    {
        self::assertFalse(RememberToken_Library::verify($token, 'invalid'));
        $this->expectException(\InvalidArgumentException::class);
        RememberToken_Library::hash($token);
    }

    public function testMalformedCookiesAndHashesAreRejectedWithoutWarnings(): void
    {
        foreach ([null, [], '', 'missing-colon', ':', 'a:b:c', '!!!:???', base64_encode('123456789') . ':' . base64_encode('short'), base64_encode('123456789') . ': ' . base64_encode(str_repeat('a', 33))] as $cookie) {
            self::assertNull(RememberToken_Library::decodeCookie($cookie));
        }
        foreach ([null, [], '', 'b64:', 'b64:invalid', '$2y$12$short', 'b64:$2y$12$short'] as $hash) {
            self::assertFalse(RememberToken_Library::verify(str_repeat('a', 33), $hash));
        }
    }
}
