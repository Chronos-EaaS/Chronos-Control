<?php
declare(strict_types=1);

namespace Chronos\Application\Benchmarks;

use Chronos\Application\Shared\Input;
use Chronos\Domain\Configuration\CdlDocument;

/** Validate the independent benchmark inputs and the selected measurement. */
final class BenchmarkContract
{
    public static function validate(array $body): array
    {
        Input::fields($body, ['parameters', 'measurement', 'lifecycle', 'results']);
        $lifecycle = self::map($body['lifecycle'] ?? null, 'lifecycle');
        Input::fields($lifecycle, ['warmUp']);
        $warmUp = $lifecycle['warmUp'] ?? null;
        if (!in_array($warmUp, ['required', 'optional', 'disabled'], true)) {
            Input::invalid('lifecycle.warmUp', 'Choose required, optional or disabled.');
        }
        return [
            'parameters' => self::schema($body['parameters'] ?? [], 'parameters'),
            'measurement' => Input::text($body['measurement'] ?? null, 'measurement', 4000),
            'lifecycle' => ['warmUp' => $warmUp],
            'results' => self::schema($body['results'] ?? null, 'results'),
        ];
    }

    private static function schema(mixed $value, string $field): array
    {
        $schema = self::map($value, $field);
        if ($field === 'results' && $schema === []) Input::invalid($field, 'Declare at least one measured result.');
        foreach ($schema as $key => &$entry) {
            $path = $field . '.' . self::key($key, $field);
            $entry = self::map($entry, $path);
            Input::fields($entry, $field === 'parameters' ? ['type', 'default'] : ['type', 'unit']);
            if (!in_array($entry['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)) {
                Input::invalid($path, 'Use string, integer, number or boolean.');
            }
            if (array_key_exists('default', $entry)) $entry['default'] = self::value($entry['default'], $entry['type'], $path);
            if (array_key_exists('unit', $entry)) $entry['unit'] = Input::text($entry['unit'], $path, 100);
        }
        unset($entry);
        return $schema;
    }

    public static function parameters(array $schema, array $parameters, bool $generated): array
    {
        $resolved = [];
        Input::fields($parameters, array_keys($schema));
        foreach ($schema as $key => $entry) {
            $resolved[$key] = self::value(array_key_exists($key, $parameters) ? $parameters[$key] : ($entry['default'] ?? null), $entry['type'], 'parameters.' . $key, $generated);
        }
        return $resolved;
    }

    public static function result(array $schema, array $result): void
    {
        foreach ($schema as $name => $entry) self::value($result[$name] ?? null, $entry['type'], 'result.' . $name);
    }

    public static function value(mixed $value, string $type, string $field, bool $generated = false): mixed
    {
        // Existing processors commonly produce textual boolean or number values.
        if ($generated && is_string($value)) {
            if ($type === 'boolean' && in_array($value, ['true', 'false', '1', '0'], true)) $value = in_array($value, ['true', '1'], true);
            if ($type === 'integer' && filter_var($value, FILTER_VALIDATE_INT) !== false) $value = (int) $value;
            if ($type === 'number' && is_numeric($value)) $value = (float) $value;
        }
        $valid = match ($type) {
            'string' => is_string($value) && strlen($value) <= 16384 && CdlDocument::acceptsText($value),
            'integer' => is_int($value),
            'number' => is_int($value) || (is_float($value) && is_finite($value)),
            'boolean' => is_bool($value),
        };
        if (!$valid) Input::invalid($field, 'Provide a value of type ' . $type . '.');
        return $value;
    }

    public static function map(mixed $value, string $field): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > 200) {
            Input::invalid($field, 'Provide an object with at most 200 entries.');
        }
        return $value;
    }

    public static function key(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,99}$/D', $value) || $value === 'run') {
            Input::invalid($field, 'Use an XML-compatible name other than run.');
        }
        return $value;
    }
}
