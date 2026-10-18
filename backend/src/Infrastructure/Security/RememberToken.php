<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Security;

use InvalidArgumentException;
use ValueError;


/** Binary-safe persistent tokens; existing raw-bcrypt records remain valid. */
final class RememberToken
{
    private const PREFIX = 'b64:';
    public const TOKEN_BYTES = 33;

    public static function hash(mixed $token): string
    {
        if (!is_string($token) || strlen($token) !== self::TOKEN_BYTES) {
            throw new InvalidArgumentException('A remember token must contain exactly 33 bytes.');
        }
        return self::PREFIX . password_hash(base64_encode($token), PASSWORD_BCRYPT);
    }

    public static function verify(mixed $token, mixed $hash): bool
    {
        if (!is_string($token) || strlen($token) !== self::TOKEN_BYTES || !is_string($hash) || $hash === '') {
            return false;
        }
        $encoded = str_starts_with($hash, self::PREFIX);
        $stored = $encoded ? substr($hash, strlen(self::PREFIX)) : $hash;
        if (!preg_match('/^\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/D', $stored)) {
            return false;
        }
        try {
            return password_verify($encoded ? base64_encode($token) : $token, $stored);
        } catch (ValueError) {
            return false;
        }
    }

    /** @return array{string,string}|null Selector stays encoded for DB lookup. */
    public static function decodeCookie(mixed $cookie): ?array
    {
        if (!is_string($cookie)) {
            return null;
        }
        $parts = explode(':', $cookie);
        if (count($parts) !== 2) {
            return null;
        }
        [$selector, $authenticator] = $parts;
        $selectorBytes = base64_decode($selector, true);
        $token = base64_decode($authenticator, true);
        if ($selectorBytes === false || strlen($selectorBytes) !== 9 || base64_encode($selectorBytes) !== $selector
            || $token === false || strlen($token) !== self::TOKEN_BYTES || base64_encode($token) !== $authenticator) {
            return null;
        }
        return [$selector, $token];
    }
}
