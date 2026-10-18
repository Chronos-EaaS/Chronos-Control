<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Metadata\MetadataService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class MetadataServiceTest extends TestCase
{
    public static function siteNames(): array
    {
        return [[null, 'Chronos Control'], ['Research & <Lab> – Zürich', 'Research & <Lab> – Zürich']];
    }

    #[DataProvider('siteNames')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMetadataRetainsTheConfiguredBrowserTitle(?string $configured, string $expected): void
    {
        require CHRONOS_BACKEND_ROOT . '/resources/constants.php';
        define('PASSWORDS_MIN_LENGTH', 12);
        define('PASSWORDS_MAX_LENGTH', 72);
        define('REQUIRE_PASSWORD_COMPLEXITY', false);
        if ($configured !== null) define('SITE_NAME', $configured);
        $metadata = (new MetadataService(__DIR__))->snapshot();
        self::assertSame($expected, $metadata['siteName'] ?? null);
        self::assertSame('ui', $metadata['api']);
        self::assertSame('3.0-dev', $metadata['applicationVersion']);
        self::assertSame('Europe/Zurich', $metadata['timezone']);
    }
}
