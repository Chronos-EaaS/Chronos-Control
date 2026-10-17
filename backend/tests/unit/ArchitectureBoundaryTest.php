<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\TestCase;

final class ArchitectureBoundaryTest extends TestCase
{
    public function testApplicationAndHttpAccessLegacyClassesThroughInfrastructure(): void
    {
        $violations = [];
        foreach (['Application', 'Http'] as $layer) {
            foreach (self::files(CHRONOS_BACKEND_ROOT . '/src/' . $layer) as $file) {
                $tokens = array_values(array_filter(token_get_all(file_get_contents($file)),
                    static fn(mixed $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
                foreach ($tokens as $index => $token) {
                    if (!is_array($token) || !in_array($token[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_STRING], true)) continue;
                    $name = ltrim($token[1], '\\');
                    if (!preg_match('/^(?:DBA(?:\\\\|$)|(?:Element|Plot|Util)$|[A-Za-z]+_Library$)/i', $name)) continue;
                    $previous = $tokens[$index - 1] ?? null;
                    $next = $tokens[$index + 1] ?? null;
                    if ($token[0] === T_STRING
                        && !(is_array($previous) && in_array($previous[0], [T_NEW, T_USE, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF], true))
                        && !(is_array($next) && $next[0] === T_DOUBLE_COLON)) continue;
                    $violations[] = substr($file, strlen(CHRONOS_BACKEND_ROOT) + 1) . ':' . $token[2] . ' ' . $token[1];
                }
            }
        }
        self::assertSame([], $violations, 'Keep retained legacy implementation dependencies inside Infrastructure adapters.');
    }

    private static function files(string $directory): iterable
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') yield $entry->getPathname();
        }
    }
}
