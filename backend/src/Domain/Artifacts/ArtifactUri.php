<?php
declare(strict_types=1);

namespace Chronos\Domain\Artifacts;

use InvalidArgumentException;

/** Use canonical paths so allowed-source checks and filesystem lookup agree. */
final class ArtifactUri
{
    public static function isRelative(string $path): bool
    {
        // Every segment starts with a letter, digit, underscore or hyphen.
        return preg_match('#^[a-zA-Z0-9_-][a-zA-Z0-9_.-]*(?:/[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)*$#D', $path) === 1;
    }

    /** @return array{string, string} The origin and its workspace-relative path. */
    public static function remote(string $uri): array
    {
        if (preg_match('#^(https?)://([a-z0-9-]+(?:\.[a-z0-9-]+)*)(?::([1-9][0-9]{0,4}))?/(.*)$#D', $uri, $parts)
            && (int) $parts[3] <= 65535
            && ($parts[1] === 'https' || $parts[2] === '127.0.0.1')
        ) {
            return [$parts[1] . '://' . $parts[2] . ($parts[3] === '' ? '' : ':' . $parts[3]), $parts[4]];
        }
        throw new InvalidArgumentException('Use a relative artifact path or a canonical HTTPS URL. HTTP is supported only on 127.0.0.1.');
    }

    public static function validate(string $uri): void
    {
        if (strlen($uri) > 2048) {
            throw new InvalidArgumentException('Artifact URI exceeds 2048 bytes.');
        }
        if (self::isRelative($uri)) {
            return;
        }
        [, $path] = self::remote($uri);
        if (!self::isRelative($path)) {
            throw new InvalidArgumentException('Artifact URL paths must name a file using plain path segments.');
        }
    }
}
