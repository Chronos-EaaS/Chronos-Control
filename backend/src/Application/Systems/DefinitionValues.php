<?php
declare(strict_types=1);

namespace Chronos\Application\Systems;

use Chronos\Application\Shared\Input;

final class DefinitionValues
{
    public static function arrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) $value = get_object_vars($value);
        return is_array($value) ? array_map(self::arrays(...), $value) : $value;
    }

    public static function identifier(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D', $value)) Input::invalid($field, 'Use a stable ID containing letters, numbers, underscores or hyphens.');
        return $value;
    }
}
