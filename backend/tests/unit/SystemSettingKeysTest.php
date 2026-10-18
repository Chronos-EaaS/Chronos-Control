<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Agents\JobCdlAssembler;
use Chronos\Application\Shared\Context;
use Chronos\Application\Systems\SystemSettings;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Persistence\Store;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SystemSettingKeysTest extends TestCase
{
    private function service(): array
    {
        // Only the authorization read is supplied. Any transaction marks the
        // actual write boundary; these validation tests need no database driver.
        $pdo = new class extends \PDO {
            public bool $transactionAttempted = false;
            public function __construct() {}
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return new class extends \PDOStatement {
                    public function __construct() {}
                    public function execute(?array $params = null): bool { return true; }
                    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
                    {
                        return [['systemId' => 1, 'userId' => 3]];
                    }
                };
            }
            public function inTransaction(): bool { return false; }
            public function beginTransaction(): bool
            {
                $this->transactionAttempted = true;
                throw new \LogicException('Reached the setting write boundary.');
            }
        };
        return [new SystemSettings(new Context(new Store($pdo), ['id' => 3, 'role' => 0], ''), ''), $pdo];
    }

    public static function invalidXmlKeys(): array
    {
        $cases = [];
        foreach (['general', 'setup'] as $section) foreach (['bad key', '9start', 'ns:value', 'two:parts:again', '<tag>', ' leading', 'trailing '] as $key) {
            $cases[$section . ': ' . $key] = [$section, $key];
        }
        return $cases;
    }

    #[DataProvider('invalidXmlKeys')]
    public function testInvalidAgentSettingKeysAreRejectedBeforeAnyWrite(string $section, string $key): void
    {
        [$service, $pdo] = $this->service();
        try { $service->replaceSettings(1, [$section => [$key => '42']]); self::fail('An unusable CDL key was accepted.'); }
        catch (HttpError $error) {
            self::assertSame(422, $error->status);
            self::assertArrayHasKey($section . '.' . $key, $error->fields);
        }
        self::assertFalse($pdo->transactionAttempted);
    }

    public static function validXmlKeys(): array
    {
        return [['workers'], ['query.mode'], ['batch-size'], ['_private'], ['Größe'], ['测量'], ["e\u{0301}"], [str_repeat('x', 50)]];
    }

    #[DataProvider('validXmlKeys')]
    public function testValidXmlNamesRemainLosslessAgentKeys(string $key): void
    {
        foreach (['general', 'setup'] as $section) {
            [$service, $pdo] = $this->service();
            try { $service->replaceSettings(1, [$section => [$key => 'A & B']]); self::fail('The write boundary was not reached.'); }
            catch (\LogicException $error) { self::assertSame('Reached the setting write boundary.', $error->getMessage()); }
            self::assertTrue($pdo->transactionAttempted);
            $document = new DOMDocument();
            self::assertTrue($document->loadXML(JobCdlAssembler::build(1, [], $section === 'general' ? [$key => 'A & B'] : [], $section === 'setup' ? [$key => 'A & B'] : [])));
            self::assertSame('A & B', $document->getElementsByTagName($key)->item(0)?->textContent);
        }
    }

    public function testDefaultValueKeysKeepTheirExistingNonXmlContract(): void
    {
        [$service, $pdo] = $this->service();
        try { $service->replaceSettings(1, ['defaultValues' => ['bad key' => 'kept', '9start' => '0', 'ns:value' => 'false']]); }
        catch (\LogicException $error) { self::assertSame('Reached the setting write boundary.', $error->getMessage()); }
        self::assertTrue($pdo->transactionAttempted);
    }

    public function testDefaultValueKeyLengthIsCheckedBeforeTrimmingOrWriting(): void
    {
        [$service, $pdo] = $this->service();
        try { $service->replaceSettings(1, ['defaultValues' => [str_repeat('x', 50) . ' ' => 'value']]); self::fail('An oversized raw key was accepted.'); }
        catch (HttpError $error) { self::assertSame(422, $error->status); }
        self::assertFalse($pdo->transactionAttempted);
    }

    public static function invalidXmlText(): array
    {
        return [["before\0after"], ["before\x0Bafter"], ["before\u{FFFE}after"]];
    }

    #[DataProvider('invalidXmlText')]
    public function testInvalidXmlTextCannotReplaceWorkingAgentSettings(string $value): void
    {
        foreach (['general', 'setup'] as $section) {
            [$service, $pdo] = $this->service();
            try { $service->replaceSettings(1, [$section => ['validName' => $value]]); self::fail('Invalid XML text was accepted.'); }
            catch (HttpError $error) {
                self::assertSame(422, $error->status);
                self::assertArrayHasKey($section . '.validName', $error->fields);
            }
            self::assertFalse($pdo->transactionAttempted);
        }
    }
}
