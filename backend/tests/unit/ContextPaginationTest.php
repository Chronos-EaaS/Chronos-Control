<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Shared\Context;
use Chronos\Infrastructure\Persistence\Store;
use PHPUnit\Framework\TestCase;

final class ContextPaginationTest extends TestCase
{
    private function fixture(int $count): array
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE Item(itemId INTEGER PRIMARY KEY,visible INTEGER)');
        $insert = $pdo->prepare('INSERT INTO Item VALUES (?,?)');
        for ($id = 1; $id <= $count; $id++) $insert->execute([$id, 1]);
        // A row outside the authorized/filter scope must not affect page bounds.
        $insert->execute([1000, 0]);
        return [new Context(new Store($pdo), ['id' => 1, 'role' => 0], ''), $pdo];
    }

    private function page(Context $context, int $page): array
    {
        return $context->page('FROM Item', 'visible=?', [1],
            ['page' => $page, 'pageSize' => 20, 'order' => 'itemId ASC'],
            'itemId', static fn(array $row): int => (int) $row['itemId'], 'itemId');
    }

    public function testShrinkingLastPageReturnsTheRemainingLastPageAndItsItems(): void
    {
        [$context, $pdo] = $this->fixture(41);
        $before = $this->page($context, 3);
        self::assertSame(3, $before['page']);
        self::assertSame([41], $before['items']);

        $pdo->exec('DELETE FROM Item WHERE itemId=41');
        $after = $this->page($context, 3);
        self::assertSame(40, $after['total']);
        self::assertSame(2, $after['page']);
        self::assertSame(20, $after['pageSize']);
        self::assertSame(range(21, 40), $after['items']);
        self::assertSame(range(1, 20), $this->page($context, $after['page'] - 1)['items']);
    }

    public function testEmptyFilteredCollectionReturnsPageOne(): void
    {
        [$context] = $this->fixture(0);
        self::assertSame(['items' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20], $this->page($context, 1000000));
    }

    public function testValidLastPageIsPreservedAndOverlargeRequestsUseThePartialLastPage(): void
    {
        [$context] = $this->fixture(21);
        foreach ([2, 3, 1000000] as $requested) {
            $result = $this->page($context, $requested);
            self::assertSame(2, $result['page']);
            self::assertSame(21, $result['total']);
            self::assertSame([21], $result['items']);
        }
    }
}
