<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/legacy/libraries/release.php';

final class ReleaseRuntimeTest extends TestCase
{
    public function testAllFreshInstallerRequirementsAreCheckedWithoutChangingGlobalState(): void
    {
        $checked = [];
        \Release_Library::assertRuntime(static function (string $extension) use (&$checked): bool { $checked[] = $extension; return true; });
        self::assertSame(['pdo_mysql', 'mbstring', 'dom', 'curl', 'zip', 'gd'], $checked);
    }

    public static function missingExtensions(): array
    {
        return array_map(static fn(string $extension): array => [$extension], ['pdo_mysql', 'mbstring', 'dom', 'curl', 'zip', 'gd']);
    }

    #[DataProvider('missingExtensions')]
    public function testEveryMissingRequiredExtensionProducesAnActionableError(string $missing): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The PHP ' . $missing . ' extension is required.');
        \Release_Library::assertRuntime(static fn(string $extension): bool => $extension !== $missing);
    }
}
