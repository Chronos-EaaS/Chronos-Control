<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Administration\SettingsService;
use Chronos\Application\Shared\Context;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Persistence\Store;
use PHPUnit\Framework\TestCase;

final class SettingsServiceTest extends TestCase
{
    private function fixture(): array
    {
        if (!extension_loaded('pdo_sqlite')) self::markTestSkipped('The isolated settings regression requires pdo_sqlite.');
        // Keep the real service, persistence and transactions. SQLite only lacks
        // the MySQL row-lock suffix used by this single-connection fixture.
        $pdo = new class('sqlite::memory:') extends \PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
            }
        };
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE Setting(settingId INTEGER PRIMARY KEY AUTOINCREMENT,systemId INTEGER,section TEXT,item TEXT,value TEXT)');
        $pdo->exec("INSERT INTO Setting(systemId,section,item,value) VALUES(0,'ftp','ftpPassword','old-secret'),(0,'ftp','ftpServer','old.example.test'),(0,'ftp','ftpUsername','old-user')");
        $pdo->exec('CREATE TABLE Event(eventId INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,time TEXT,eventText TEXT,eventType TEXT,relatedId INTEGER,userId INTEGER,nodeId INTEGER)');
        return [new SettingsService(new Context(new Store($pdo), ['id' => 3, 'role' => 2], '')), $pdo];
    }

    public function testSecretValuesRemainByteExactWithoutChangingOrdinarySettingNormalization(): void
    {
        [$service, $pdo] = $this->fixture();
        foreach ([' leading-and-trailing ', ' ', str_repeat(' ', 4096), ''] as $secret) {
            $before = $service->sections()[0];
            $saved = $service->saveSection('ftp', ['revision' => $before['revision'], 'values' => ['ftpPassword' => $secret, 'ftpUsername' => ' user ', 'ftpServer' => ' example.test ']]);
            self::assertSame($secret, $pdo->query("SELECT value FROM Setting WHERE item='ftpPassword'")->fetchColumn());
            self::assertSame(' user ', $pdo->query("SELECT value FROM Setting WHERE item='ftpUsername'")->fetchColumn());
            self::assertSame('example.test', $pdo->query("SELECT value FROM Setting WHERE item='ftpServer'")->fetchColumn());
            $password = array_values(array_filter($saved['settings'], static fn(array $setting): bool => $setting['key'] === 'ftpPassword'))[0];
            self::assertNull($password['value']);
            self::assertTrue($password['secret']);
            self::assertSame($secret !== '', $password['configured']);
        }
    }

    public function testFtpUsernameRemainsByteExactWhenEditingTheGlobalCredentials(): void
    {
        [$service, $pdo] = $this->fixture();
        $section = $service->sections()[0];
        $saved = $service->saveSection('ftp', ['revision' => $section['revision'], 'values' => ['ftpUsername' => ' user ']]);
        self::assertSame(' user ', $pdo->query("SELECT value FROM Setting WHERE item='ftpUsername'")->fetchColumn());
        $username = array_values(array_filter($saved['settings'], static fn(array $setting): bool => $setting['key'] === 'ftpUsername'))[0];
        self::assertSame(' user ', $username['value']);
        self::assertFalse($username['secret']);
    }

    public function testMultilinePrivateKeysRemainLosslessSecrets(): void
    {
        [$service, $pdo] = $this->fixture();
        $pdo->exec("INSERT INTO Setting(systemId,section,item,value) VALUES(0,'ssh','privateKey','old-key')");
        $section = array_values(array_filter($service->sections(), static fn(array $entry): bool => $entry['name'] === 'ssh'))[0];
        $privateKey = "-----BEGIN PRIVATE KEY-----\nsynthetic-fixture\n-----END PRIVATE KEY-----\n";
        $saved = $service->saveSection('ssh', ['revision' => $section['revision'], 'values' => ['privateKey' => $privateKey]]);
        self::assertSame($privateKey, $pdo->query("SELECT value FROM Setting WHERE item='privateKey'")->fetchColumn());
        self::assertNull($saved['settings'][0]['value']);
        self::assertTrue($saved['settings'][0]['secret']);
    }

    public function testOversizedSecretIsRejectedBeforeReplacingStoredCredentials(): void
    {
        [$service, $pdo] = $this->fixture();
        $section = $service->sections()[0];
        try {
            $service->saveSection('ftp', ['revision' => $section['revision'], 'values' => ['ftpPassword' => str_repeat(' ', 4097)]]);
            self::fail('The raw secret must respect its storage bound.');
        } catch (HttpError $error) {
            self::assertSame(422, $error->status);
            self::assertArrayHasKey('values.ftpPassword', $error->fields);
        }
        self::assertSame('old-secret', $pdo->query("SELECT value FROM Setting WHERE item='ftpPassword'")->fetchColumn());
    }
}
