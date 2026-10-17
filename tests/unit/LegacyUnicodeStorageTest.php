<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Shared\Dto;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Persistence\DatabaseJson;
use Chronos\Infrastructure\Persistence\Store;
use PHPUnit\Framework\TestCase;

final class LegacyUnicodeStorageTest extends TestCase
{
    public function testHtmlBackedNamesRoundTripSupplementaryCharactersAndLiteralEntityText(): void
    {
        $text = 'Zürich 🧪 & &#129514; <value> 🚀';
        $stored = Dto::storedText($text, 'name', 256);
        self::assertSame('Zürich &#129514; &amp; &amp;#129514; &lt;value&gt; &#128640;', $stored);
        self::assertSame($text, Dto::text($stored));
        self::assertSame($stored, Dto::encodeStoredText($text));
    }

    public function testNameLengthLimitAppliesAfterSupplementaryEntityExpansion(): void
    {
        self::assertSame('&#129514;', Dto::storedText('🧪', 'name', 9));
        $this->expectException(HttpError::class);
        $this->expectExceptionMessage('Please check the submitted values.');
        Dto::storedText('🧪', 'name', 8);
    }

    public function testJsonPreservesBmpBytesAndEscapesSupplementaryKeysValuesAndObjects(): void
    {
        $document = ['emoji🧪' => 'Zürich 🚀 \\ <value>', 'zero' => 0, 'false' => false, 'empty' => new \stdClass()];
        $encoded = DatabaseJson::encode($document, 'parameters');
        self::assertStringContainsString('Zürich', $encoded);
        self::assertStringContainsString('"emoji\\ud83e\\uddea"', $encoded);
        self::assertStringContainsString('"empty":{}', $encoded);
        self::assertSame(0, preg_match('/[\x{10000}-\x{10FFFF}]/u', $encoded));
        self::assertSame(json_decode(json_encode($document, JSON_THROW_ON_ERROR), true), json_decode($encoded, true, 32, JSON_THROW_ON_ERROR));
    }

    public function testJsonLimitCountsExpandedSurrogatePairsBeforeDatabaseWrites(): void
    {
        self::assertSame(68, strlen(DatabaseJson::encode(['p' => str_repeat('🧪', 5)], 'parameters', 68)));
        try {
            DatabaseJson::encode(['p' => str_repeat('🧪', 5)], 'parameters', 67);
            self::fail('Expanded JSON unexpectedly exceeded its byte limit.');
        } catch (HttpError $error) {
            self::assertSame(422, $error->status);
            self::assertArrayHasKey('parameters', $error->fields);
        }
    }

    public function testOrdinaryTextDoesNotQueryDriverOrMetadata(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::never())->method('getAttribute');
        $pdo->expects(self::never())->method('prepare');
        (new Store($pdo))->assertText('User', 'username', 'Zürich', 'username');
    }

    public function testSqliteSupplementaryTextDoesNotQueryMysqlMetadata(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $pdo->expects(self::never())->method('prepare');
        (new Store($pdo))->assertText('User', 'username', 'review-🧪', 'username');
    }

    public function testLegacyRawIdentifiersFailBeforeInsertAndReuseCachedMetadata(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with(['User'])->willReturn(true);
        $statement->expects(self::once())->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn([
            ['COLUMN_NAME' => 'username', 'CHARACTER_SET_NAME' => 'utf8mb3'],
        ]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');
        $pdo->expects(self::once())->method('prepare')->with(self::stringContains('information_schema.COLUMNS'))->willReturn($statement);
        $store = new Store($pdo);
        foreach (['first-🧪', 'second-🚀'] as $username) {
            try {
                $store->insert('User', ['username' => $username]);
                self::fail('An unsupported identifier reached the INSERT.');
            } catch (HttpError $error) {
                self::assertSame(422, $error->status);
                self::assertArrayHasKey('username', $error->fields);
            }
        }
    }

    public function testExistingUtf8mb4AndBlobColumnsKeepSupportingSupplementaryText(): void
    {
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([
            ['COLUMN_NAME' => 'username', 'CHARACTER_SET_NAME' => 'utf8mb4'],
            ['COLUMN_NAME' => 'value', 'CHARACTER_SET_NAME' => null],
        ]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $store = new Store($pdo);
        $store->assertText('Fixture', 'username', 'review-🧪', 'username');
        $store->assertText('Fixture', 'value', 'BLOB 🧪', 'value');
    }

    public function testUnavailableMetadataFailsWithAnActionableServiceError(): void
    {
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');
        $pdo->method('prepare')->willThrowException(new \PDOException('Synthetic metadata access denial'));
        try {
            (new Store($pdo))->assertText('User', 'username', 'review-🧪', 'username');
            self::fail('Unavailable charset verification unexpectedly succeeded.');
        } catch (HttpError $error) {
            self::assertSame(503, $error->status);
            self::assertSame('storage_validation_unavailable', $error->errorCode);
            self::assertStringContainsString('column metadata', $error->getMessage());
        }
    }
}
