<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Security;

/** Keep remembered credentials on the browser's actual host, including Apache aliases. */
final class RememberCookie
{
    public static function write(string $value, int $expires): void
    {
        $options = ['expires' => $expires, 'path' => '/', 'secure' => Sessions::https(),
            'httponly' => true, 'samesite' => 'Lax'];
        $legacyDomain = $_SERVER['SERVER_NAME'] ?? null;
        if (is_string($legacyDomain) && $legacyDomain !== '') {
            // Older versions scoped this cookie to Apache's canonical name.
            // Remove that credential before setting the host-only replacement.
            setcookie('rememberMe', '', array_replace($options, ['expires' => 1, 'domain' => $legacyDomain]));
        }
        setcookie('rememberMe', $value, $options);
    }
}
