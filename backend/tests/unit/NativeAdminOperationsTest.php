<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Http\HttpError;
use Chronos\Infrastructure\Administration\NativeAdminOperations;
use Chronos\Infrastructure\Persistence\Store;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class NativeAdminOperationsTest extends TestCase
{
    private function normalize(array $tables): array
    {
        $operations = new NativeAdminOperations(new Store(new PDO('sqlite::memory:')), '/unused');
        return (new ReflectionMethod($operations, 'snapshotMaintainers'))->invoke($operations, $tables);
    }

    private function legacy(): array
    {
        return ['User' => [['userId' => 3, 'enabled' => 1], ['userId' => '6', 'enabled' => 0]],
            'System' => [['systemId' => 1, 'userId' => 3], ['systemId' => '2', 'userId' => '6'], ['systemId' => 3, 'userId' => 99]]];
    }

    public function testLegacySnapshotsBackfillValidOwnersIncludingDisabledUsersFromSnapshotData(): void
    {
        self::assertSame([['systemId' => 1, 'userId' => 3], ['systemId' => 2, 'userId' => 6]], $this->normalize($this->legacy()));
    }

    public function testCurrentSnapshotsPreserveEveryMaintainerAndItsOriginalValues(): void
    {
        $tables = $this->legacy();
        $tables['SystemMaintainer'] = [['systemId' => 1, 'userId' => 3], ['systemId' => '1', 'userId' => '6'], ['systemId' => '2', 'userId' => '6']];
        self::assertSame($tables['SystemMaintainer'], $this->normalize($tables));
    }

    public function testValidOwnerAssociationCannotDisappearFromAnExplicitCurrentSnapshot(): void
    {
        $tables = $this->legacy();
        $tables['SystemMaintainer'] = [['systemId' => 1, 'userId' => 3]];
        $this->expectException(HttpError::class);
        $this->expectExceptionMessage('designated demo system owner');
        $this->normalize($tables);
    }

    #[DataProvider('invalidMemberships')]
    public function testCurrentSnapshotsRejectInvalidMembershipReferences(array $row): void
    {
        $tables = $this->legacy();
        $tables['SystemMaintainer'] = [['systemId' => 1, 'userId' => 3], ['systemId' => 2, 'userId' => 6], $row];
        try {
            $this->normalize($tables);
            self::fail('An invalid membership was accepted.');
        } catch (HttpError $error) {
            self::assertSame('invalid_snapshot', $error->errorCode);
        }
    }

    public static function invalidMemberships(): array
    {
        return [
            'missing user' => [['systemId' => 1, 'userId' => 99]],
            'missing system' => [['systemId' => 99, 'userId' => 3]],
            'duplicate numeric pair' => [['systemId' => '1', 'userId' => '3']],
            'partial row' => [['systemId' => 1]],
            'extra field' => [['systemId' => 1, 'userId' => 6, 'role' => 'owner']],
            'float ID' => [['systemId' => 1.0, 'userId' => 6]],
            'boolean ID' => [['systemId' => true, 'userId' => 6]],
            'overflow ID' => [['systemId' => '2147483648', 'userId' => 6]],
            'zero ID' => [['systemId' => 0, 'userId' => 6]],
        ];
    }
}
