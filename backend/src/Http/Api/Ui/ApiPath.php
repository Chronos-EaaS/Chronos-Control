<?php
declare(strict_types=1);

namespace Chronos\Http\Api\Ui;

/** Match only the canonical UI API namespace, without partial-prefix routing. */
final class ApiPath
{
    public const PREFIX = '/api/ui';

    public static function relative(string $path): ?string
    {
        return $path === self::PREFIX || str_starts_with($path, self::PREFIX . '/')
            ? substr($path, strlen(self::PREFIX)) : null;
    }
}
