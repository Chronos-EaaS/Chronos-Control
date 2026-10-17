<?php
declare(strict_types=1);
namespace Chronos\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;
require_once CHRONOS_TEST_ROOT . '/core/browserui.php';

final class BrowserUiTest extends TestCase
{
    public static function bookmarks(): iterable
    {
        yield ['/project/detail/id=12', '/projects/12'];
        yield ['/project/detail/id=12/archive=true', '/projects/12'];
        yield ['/experiment/detail/id=3/', '/experiments/3'];
        yield ['/evaluation/detail/id=4', '/evaluations/4'];
        yield ['/job/detail/id=5', '/jobs/5'];
        yield ['/admin/system/id=1', '/systems/1'];
        yield ['/builder/build/systemId=1', '/systems/1?tab=parameters'];
        yield ['/builder/create/projectId=1/copy=2', '/projects/1/experiments/new?copy=2'];
        yield ['/results/build/systemId=1/type=2/resultId=system-1', '/systems/1?tab=results&configuration=system-1&scope=job'];
        yield ['/results/build/experimentId=2/type=1/resultId=experiment-2-a', '/experiments/2?tab=results&configuration=experiment-2-a&scope=all'];
        yield ['/evaluation/download/id=2', '/api/v3/evaluations/2/download'];
        yield ['/admin/systemExport/id=1', '/api/v3/systems/1/export'];
        yield ['/cem/detail/id=fixture-busy', '/cem/nodes/fixture-busy'];
        yield ['/user/edit/id=4', '/admin/users/4'];
        yield ['/user/login', '/login'];
        yield ['/home/main', '/'];
    }

    #[DataProvider('bookmarks')]
    public function testLegacyBookmarksUseExplicitNewResources(string $old, string $new): void
    {
        self::assertSame($new, \BrowserUi::legacyLocation($old));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSystemDetailDeepLinksAndPublicAssetsAreServedWithoutLoadingServices(): void
    {
        $root = sys_get_temp_dir() . '/chronos-browser-' . bin2hex(random_bytes(8));
        mkdir($root . '/webroot/ui', 0700, true);
        file_put_contents($root . '/webroot/ui/index.html', '<app-root>SPA fixture</app-root>');
        file_put_contents($root . '/webroot/ui/chunk-aBc_1234.js', 'public build');
        file_put_contents($root . '/config.php', 'PRIVATE CONFIG');
        try {
            foreach (['/systems/1', '/projects/1', '/experiments/1', '/evaluations/1', '/jobs/1', '/extensions/systems/1/pages/overview'] as $path) {
                ob_start(); self::assertTrue(\BrowserUi::serve($root, $path)); $body = ob_get_clean(); self::assertSame('<app-root>SPA fixture</app-root>', $body);
            }
            ob_start(); \BrowserUi::serve($root, '/chunk-aBc_1234.js'); self::assertSame('public build', ob_get_clean());
            foreach (['/../config.php', '/systems/1/parameters.json', '/data/evaluation/1.zip', '/media/anything.png', '/chunk-missing123.js'] as $path) {
                ob_start(); \BrowserUi::serve($root, $path); $body = ob_get_clean(); self::assertSame(404, http_response_code()); self::assertStringNotContainsString('PRIVATE CONFIG', $body);
            }
            ob_start(); self::assertTrue(\BrowserUi::serve($root, '/api/system/legacy/action')); ob_end_clean(); self::assertSame(404, http_response_code());
            self::assertFalse(\BrowserUi::serve($root, '/api/v1/job'));
        } finally {
            unlink($root . '/config.php'); unlink($root . '/webroot/ui/index.html'); unlink($root . '/webroot/ui/chunk-aBc_1234.js'); rmdir($root . '/webroot/ui'); rmdir($root . '/webroot'); rmdir($root);
        }
    }
}
