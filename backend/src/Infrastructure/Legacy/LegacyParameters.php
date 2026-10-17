<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Legacy;

/** Run installed built-in parameter processors using their existing data contract. */
final class LegacyParameters
{
    public static function process(string $root, string $directory, array $data, string $parameter, array &$configurations): void
    {
        (new \Element($root . '/libraries/elements/' . $directory))->process($data, $parameter, $configurations);
    }
}
