<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\TestCase;

final class ArchitectureBoundaryTest extends TestCase
{
    public function testEveryProductionLayerUsesNamespacedImplementationsWithoutSuffixAutoloading(): void
    {
        $violations = [];
        foreach (['src', 'http', 'public', 'resources'] as $layer) {
            foreach (self::files(CHRONOS_BACKEND_ROOT . '/' . $layer) as $file) {
                foreach (token_get_all(file_get_contents($file)) as $token) {
                    if (!is_array($token) || !in_array($token[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_STRING, T_CONSTANT_ENCAPSED_STRING], true)) continue;
                    $name = ltrim($token[1], '\\');
                    if (preg_match('/(?:^|\\\\)DBA(?:\\\\|$)|(?:^|\\\\)Legacy(?:\\\\|$)|\b[A-Za-z][A-Za-z0-9]*_(?:Library|System|API)\b/', $name)
                        || in_array($name, ['Element', 'Plot', 'Util', 'Define', 'ProcessException', 'API'], true)
                        || in_array(strtolower($name), ['class_alias', 'spl_autoload_register'], true)) {
                        $violations[] = substr($file, strlen(CHRONOS_BACKEND_ROOT) + 1) . ':' . $token[2] . ' ' . $token[1];
                    }
                }
            }
        }
        self::assertSame([], $violations, 'Every production layer must use PSR definitions, without legacy namespaces, class suffix loaders or aliases.');
        self::assertDirectoryDoesNotExist(CHRONOS_BACKEND_ROOT . '/legacy');
        self::assertDirectoryDoesNotExist(CHRONOS_BACKEND_ROOT . '/compatibility');
    }

    private static function files(string $directory): iterable
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') yield $entry->getPathname();
        }
    }
}
