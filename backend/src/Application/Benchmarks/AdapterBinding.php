<?php
declare(strict_types=1);

namespace Chronos\Application\Benchmarks;

use Chronos\Application\Shared\Input;
use Chronos\Domain\Configuration\CdlDocument;

final class AdapterBinding
{
    public const COMMAND = 'chronos-command@1';
    public const CDL = 'chronos-cdl@1';

    public static function validate(array $body, array $contract): array
    {
        Input::fields($body, ['systemVersion', 'adapter', 'parameterMap', 'constants', 'artifacts']);
        if (($body['adapter'] ?? null) !== self::COMMAND) {
            Input::invalid('adapter', 'Choose chronos-command@1, which executes a digest-verified driver.');
        }
        $map = BenchmarkContract::map($body['parameterMap'] ?? [], 'parameterMap');
        if (array_diff_key($map, $contract['parameters']) || array_diff_key($contract['parameters'], $map)) {
            Input::invalid('parameterMap', 'Map every declared benchmark parameter exactly once.');
        }
        foreach ($map as $target) BenchmarkContract::key($target, 'parameterMap');
        $constants = BenchmarkContract::map($body['constants'] ?? [], 'constants');
        foreach ($constants as $name => $value) {
            BenchmarkContract::key($name, 'constants');
            if (!is_scalar($value) || (!CdlDocument::acceptsText((string) $value) || (is_float($value) && !is_finite($value))) || (is_string($value) && strlen($value) > 16384)) {
                Input::invalid('constants.' . $name, 'Provide a scalar adapter value.');
            }
        }
        $targets = [...array_values($map), ...array_keys($constants)];
        if (count($targets) !== count(array_unique($targets))) {
            Input::invalid('parameterMap', 'Adapter destinations and constants must have distinct names.');
        }
        return [
            'systemVersion' => Input::text($body['systemVersion'] ?? null, 'systemVersion', 100),
            'adapter' => self::COMMAND,
            'parameterMap' => $map,
            'constants' => $constants,
            'artifacts' => self::artifacts($body['artifacts'] ?? null),
        ];
    }

    public static function parameters(array $binding, array $parameters): array
    {
        $mapped = $binding['constants'];
        foreach ($parameters as $key => $value) $mapped[$binding['parameterMap'][$key]] = $value;
        return $mapped;
    }

    /** Manifest schema version 1 uses the same artifact identity as the build integration. */
    private static function artifacts(mixed $manifest): array
    {
        $manifest = BenchmarkContract::map($manifest, 'artifacts');
        Input::fields($manifest, ['schemaVersion', 'artifacts', 'sourceRevision', 'buildId']);
        $entries = $manifest['artifacts'] ?? null;
        if (($manifest['schemaVersion'] ?? null) !== 1 || !is_array($entries) || !array_is_list($entries) || count($entries) > 30) {
            Input::invalid('artifacts', 'Provide a version 1 artifact manifest with up to 30 entries.');
        }
        $roles = [];
        $validated = [];
        foreach ($entries as $entry) {
            $entry = BenchmarkContract::map($entry, 'artifacts');
            Input::fields($entry, ['role', 'uri', 'sha256', 'sizeBytes', 'sourceRevision', 'buildId']);
            $role = BenchmarkContract::key($entry['role'] ?? null, 'artifacts.role');
            $uri = Input::text($entry['uri'] ?? null, 'artifacts.uri', 2048);
            $hash = $entry['sha256'] ?? null;
            $size = $entry['sizeBytes'] ?? null;
            if (isset($roles[$role]) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || !is_int($size) || $size < 0) {
                Input::invalid('artifacts', 'Give each artifact a distinct role, a lowercase SHA-256 digest and a nonnegative byte count.');
            }
            $identity = ['role' => $role, 'uri' => $uri, 'sha256' => $hash, 'sizeBytes' => $size];
            foreach (['sourceRevision', 'buildId'] as $key) {
                if (array_key_exists($key, $entry)) $identity[$key] = Input::text($entry[$key], 'artifacts.' . $key, 256);
            }
            $validated[] = $identity;
            $roles[$role] = true;
        }
        if (!isset($roles['driver'])) Input::invalid('artifacts', 'The command adapter requires a driver artifact.');
        $manifest['artifacts'] = $validated;
        foreach (['sourceRevision', 'buildId'] as $key) {
            if (array_key_exists($key, $manifest)) $manifest[$key] = Input::text($manifest[$key], 'artifacts.' . $key, 250);
        }
        return $manifest;
    }
}
