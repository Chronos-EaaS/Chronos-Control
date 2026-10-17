<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Shared\TextSearch;
use PHPUnit\Framework\TestCase;

final class TextSearchTest extends TestCase
{
    public function testRawFieldsKeepLiteralEntitySpellingsAndLikeEscaping(): void
    {
        [$where, $args] = TextSearch::condition('&amp; %= _', [], ['environment']);
        self::assertSame("(CONVERT(environment USING utf8mb4) LIKE ? ESCAPE '=')", $where);
        self::assertSame(['%&amp; =%== =_%'], $args);
    }
}
