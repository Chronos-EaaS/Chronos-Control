<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Shared\Context;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Application\Systems\{ParameterSchema, RepositorySettings, SystemSettings};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SystemServicesTest extends TestCase
{
    public static function repositoryUrls(): array
    {
        return [
            ['deploy@example.test:team/system.git'],
            ['git@example.test:team/system.git'],
            ['ssh://deploy@example.test/team/system'],
            ['https://example.test/team/system.git'],
        ];
    }

    #[DataProvider('repositoryUrls')]
    public function testCreationAndEditingAcceptTheSameRepositoryAddress(string $url): void
    {
        $created = RepositorySettings::creation(['url' => $url, 'type' => 'git', 'branch' => 'main']);
        self::assertSame($url, $created['vcsUrl']);
        self::assertSame(['vcsUrl' => $url], RepositorySettings::changes(['url' => $url]));
        self::assertSame(['vcsBranch' => 'main'], RepositorySettings::changes(['branch' => 'main']));
        self::assertSame(['vcsPassword' => ''], RepositorySettings::changes(['password' => '']));
    }

    public function testRepositoryCredentialsRemainByteExactWhenCreatedAndUpdated(): void
    {
        foreach (['username' => [' leading', 'trailing ', ' '], 'password' => [' secret ', ' ', str_repeat(' ', 256)]] as $field => $credentials) {
            foreach ($credentials as $credential) {
                $column = $field === 'username' ? 'vcsUser' : 'vcsPassword';
                $created = RepositorySettings::creation(['url' => 'https://example.test/repo', 'type' => 'git', 'branch' => 'main', $field => $credential]);
                self::assertSame($credential, $created[$column]);
                self::assertSame([$column => $credential], RepositorySettings::changes([$field => $credential]));
            }
        }
    }

    public function testRepositoryCredentialControlsAndOversizedRawValuesAreRejected(): void
    {
        foreach (['username' => 50, 'password' => 256] as $field => $limit) {
            foreach (["value\n", "value\t", str_repeat(' ', $limit + 1)] as $credential) {
                foreach (['creation', 'changes'] as $method) {
                    $values = [$field => $credential];
                    if ($method === 'creation') $values += ['url' => 'https://example.test/repo', 'type' => 'git', 'branch' => 'main'];
                    try {
                        RepositorySettings::$method($values);
                        self::fail('Repository credentials must be validated before storage.');
                    } catch (HttpError $error) {
                        self::assertSame(422, $error->status);
                    }
                }
            }
        }
    }

    public function testRepositoryEditingKeepsCredentialsInSeparateFields(): void
    {
        foreach (['https://user:secret@example.test/repo', 'https://example.test/repo?token=secret', 'file:///private/repo'] as $url) {
            try {
                RepositorySettings::changes(['url' => $url]);
                self::fail('An unsupported credential-bearing/local repository address was accepted.');
            } catch (HttpError $error) {
                self::assertSame(422, $error->status);
            }
        }
    }

    public function testRemovingTheLastSettingDeletesItAndPreservesOtherSections(): void
    {
        if (!extension_loaded('pdo_sqlite')) self::markTestSkipped('The isolated persistence regression requires pdo_sqlite.');
        // Translate only SQL dialect differences; all data, transactions and
        // application authorization run against a real, private in-memory DB.
        $pdo = new class('sqlite::memory:') extends \PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $query = str_replace(["CONCAT_WS(' ',owner.firstname,owner.lastname)", ' FOR UPDATE'], ["(owner.firstname || ' ' || owner.lastname)", ''], $query);
                return parent::prepare($query, $options);
            }
        };
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE User(userId INTEGER PRIMARY KEY,firstname TEXT,lastname TEXT)');
        $pdo->exec("INSERT INTO User VALUES(3,'Synthetic','Owner')");
        $pdo->exec('CREATE TABLE System(systemId INTEGER PRIMARY KEY,userId INTEGER)');
        $pdo->exec('INSERT INTO System VALUES(1,3)');
        $pdo->exec('CREATE TABLE SystemMaintainer(systemId INTEGER,userId INTEGER,PRIMARY KEY(systemId,userId))');
        $pdo->exec('INSERT INTO SystemMaintainer VALUES(1,3)');
        $pdo->exec('CREATE TABLE Setting(settingId INTEGER PRIMARY KEY AUTOINCREMENT,systemId INTEGER,section TEXT,item TEXT,value TEXT)');
        $pdo->exec("INSERT INTO Setting(systemId,section,item,value) VALUES(1,'general','last','0'),(1,'setup','mode','safe'),(1,'defaultValues','environment','fixture')");
        $pdo->exec('CREATE TABLE Event(eventId INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,time TEXT,eventText TEXT,eventType TEXT,relatedId INTEGER,userId INTEGER,nodeId INTEGER)');
        $store = new Store($pdo);
        $service = new SystemSettings(new Context($store, ['id' => 3, 'role' => 0], ''), '');
        $result = $service->replaceSettings(1, ['general' => (object) []]);
        self::assertSame([], get_object_vars($result['general']));
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM Setting WHERE section='general'")->fetchColumn());
        self::assertSame('safe', $result['setup']->mode);
        self::assertSame('fixture', $result['defaultValues']->environment);
        $outsider = new SystemSettings(new Context($store, ['id' => 5, 'role' => 0], ''), '');
        try {
            $outsider->replaceSettings(1, ['setup' => []]);
            self::fail('A different owner must not clear system settings.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }
        self::assertSame('safe', $pdo->query("SELECT value FROM Setting WHERE section='setup'")->fetchColumn());
        $result = $service->replaceSettings(1, ['setup' => []]);
        self::assertSame([], get_object_vars($result['setup']));
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM Setting WHERE section='setup'")->fetchColumn());
        self::assertSame('fixture', $service->settings(1)['defaultValues']->environment);
    }

    public static function overlappingControls(): array
    {
        return [
            [['id' => 'selection', 'type' => 'checkbox-selection', 'name' => 'Choice', 'parameter' => 'choice', 'options' => 'a,b'], 'choice-a'],
            [['id' => 'range', 'type' => 'interval', 'name' => 'Range', 'parameter' => 'size'], 'size-min'],
            [['id' => 'versions', 'type' => 'software-versions-list', 'name' => 'Version', 'parameter' => 'version'], 'version-version-0'],
            [['id' => 'versions', 'type' => 'software-versions-list', 'name' => 'Version', 'parameter' => 'version'], 'version-version-999'],
            [['id' => 'versions', 'type' => 'software-versions-list', 'name' => 'Version', 'parameter' => 'version'], 'version-numberOfVersions'],
            [['id' => 'share', 'type' => 'percentage', 'name' => 'Share', 'parameter' => 'share', 'dependency' => 'budget'], 'share-percentage'],
            [['id' => 'share', 'type' => 'percentage-interval', 'name' => 'Share', 'parameter' => 'share', 'dependency' => 'budget'], 'share-percentage-start'],
        ];
    }

    #[DataProvider('overlappingControls')]
    public function testAmbiguousControlsAreRejectedInEitherElementOrder(array $generated, string $name): void
    {
        $explicit = ['id' => 'literal', 'type' => 'integer', 'name' => 'Explicit value', 'parameter' => $name];
        foreach ([[$generated, $explicit], [$explicit, $generated]] as $elements) {
            if (str_starts_with($generated['type'], 'percentage')) {
                $elements[] = ['id' => 'budget-input', 'type' => 'integer', 'name' => 'Budget', 'parameter' => 'budget'];
            }
            try {
                ParameterSchema::validate([['id' => 'group', 'title' => 'Fixture', 'elements' => $elements]]);
                self::fail('Overlapping controls must never silently overwrite a value.');
            } catch (HttpError $error) {
                self::assertSame(422, $error->status);
            }
        }
    }

    public function testNonOverlappingPrefixesAndMetadataLikeParameterNamesRemainValid(): void
    {
        $elements = [
            ['id' => 'selection', 'type' => 'checkbox-selection', 'name' => 'Choice', 'parameter' => 'choice', 'options' => 'a'],
            ['id' => 'versions', 'type' => 'software-versions-list', 'name' => 'Version', 'parameter' => 'version'],
        ];
        foreach (['choice-extra', 'version-version-1000', 'runs', '_v3Values', 'choice-type'] as $index => $name) {
            $elements[] = ['id' => 'literal-' . $index, 'type' => 'string', 'name' => $name, 'parameter' => $name];
        }
        $groups = [['id' => 'group', 'title' => 'Fixture', 'elements' => $elements]];
        self::assertCount(count($elements), ParameterSchema::validate($groups)[0]['elements']);
    }
}
