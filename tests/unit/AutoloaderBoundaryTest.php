<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AutoloaderBoundaryTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLegacyLoaderAllowsSubsequentNamespacedAutoloaders(): void
    {
        require_once CHRONOS_TEST_ROOT . '/core/autoloader.php';
        $requested = [];
        spl_autoload_register(static function (string $class) use (&$requested): void {
            $requested[] = $class;
        });

        self::assertFalse(class_exists('OtherVendor\\MissingService'));
        self::assertSame(['OtherVendor\\MissingService'], $requested);
    }
}
