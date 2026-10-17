<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence;

use Chronos\Application\Shared\Input;

/** Lossless JSON for existing MySQL TEXT columns without changing their schema. */
final class DatabaseJson
{
    public static function encode(mixed $value, string $field, int $maxBytes = 65535): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        // Keep BMP text compact; surrogate pairs represent supplementary values
        // without sending four-byte characters to an utf8mb3 column.
        $json = preg_replace_callback('/[\x{10000}-\x{10FFFF}]/u', static function (array $match): string {
            return substr(json_encode($match[0], JSON_THROW_ON_ERROR), 1, -1);
        }, $json);
        if (strlen($json) > $maxBytes) Input::invalid($field, 'The saved JSON exceeds the database storage limit.');
        return $json;
    }
}
