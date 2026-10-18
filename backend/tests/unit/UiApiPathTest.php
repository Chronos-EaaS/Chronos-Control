<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Http\Api\Ui\ApiPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UiApiPathTest extends TestCase
{
    public static function paths(): iterable
    {
        yield ['/api/ui', ''];
        yield ['/api/ui/', '/'];
        yield ['/api/ui/projects/1', '/projects/1'];
        yield ['/api/ui/jobs/90/download', '/jobs/90/download'];
        yield ['/api/v3', ''];
        yield ['/api/v3/meta', '/meta'];
        yield ['/api/v3/session', '/session'];
        yield ['/api/v1/job/id=90', null];
        yield ['/api/uix/meta', null];
        yield ['/api/ui-other/meta', null];
        yield ['/api/v30/meta', null];
        yield ['/other/api/ui/meta', null];
    }

    #[DataProvider('paths')]
    public function testExactApiPrefixesPreserveTheResourcePath(string $path, ?string $relative): void
    {
        self::assertSame($relative, ApiPath::relative($path));
    }
}
