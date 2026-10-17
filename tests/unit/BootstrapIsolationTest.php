<?php

declare(strict_types=1);

namespace Chronos\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BootstrapIsolationTest extends TestCase
{
    public function testNoApplicationConfigurationOrOperationalBootstrapWasLoaded(): void
    {
        $loaded = array_map('realpath', get_included_files());
        foreach (['config.php', 'webroot/index.php', 'core/autoloader.php', 'libraries/dba/init.php',
            'libraries/vcs.php', 'libraries/mount.php', 'libraries/auth.php'] as $forbiddenFile) {
            self::assertNotContains(realpath(CHRONOS_TEST_ROOT . '/' . $forbiddenFile), $loaded);
        }
        self::assertFalse(defined('DB_HOST'));
        self::assertFalse(defined('DB_PASSWORD'));
        self::assertSame('Europe/Zurich', date_default_timezone_get());
    }

    #[DataProvider('operationalClasses')]
    public function testAccidentalOperationalServiceAutoloadIsRejected(string $class): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Operational services are forbidden in unit tests');
        class_exists($class);
    }

    public static function operationalClasses(): iterable
    {
        foreach (['DBA\\Factory', 'DBA\\JobFactory', 'VCS_Library', 'Mount_Library',
            'MAAS_Library', 'REST_Library', 'Auth_Library', 'Settings_Library', 'System'] as $class) {
            yield $class => [$class];
        }
    }
}
