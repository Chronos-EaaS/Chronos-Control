<?php
declare(strict_types=1);

namespace Chronos\Http\Api\Ui;

/** The published preview prefix uses the same authentication and handlers. */
final class ApiPath
{
    public const PREFIX = '/api/ui';
    public const PREVIOUS_PREFIX = '/api/v3';

    public static function relative(string $path): ?string
    {
        foreach ([self::PREFIX, self::PREVIOUS_PREFIX] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return substr($path, strlen($prefix));
            }
        }
        return null;
    }
}
