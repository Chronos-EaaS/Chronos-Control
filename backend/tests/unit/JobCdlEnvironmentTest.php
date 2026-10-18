<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Infrastructure\Agents\JobCdl;
use Chronos\Infrastructure\Persistence\Connection;
use Chronos\Tests\Support\JobFixture;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class JobCdlEnvironmentTest extends TestCase
{
    public static function storedEnvironments(): iterable
    {
        yield 'local job' => ['system-local', false];
        yield 'local name containing cem' => ['system-cem-cluster', false];
        yield 'unprefixed historical local job' => ['Historical local environment', false];
        yield 'CEM job' => ['cem-cluster', true];
    }

    #[DataProvider('storedEnvironments')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStoredEnvironmentControlsSetupAfterTheSystemConfigurationChanges(string $environment, bool $cem): void
    {
        Connection::configure('sqlite::memory:');
        $pdo = Connection::get();
        $pdo->exec('CREATE TABLE System(systemId INTEGER PRIMARY KEY,name TEXT,description TEXT,userId INTEGER,vcsUrl TEXT,vcsBranch TEXT,vcsType TEXT,vcsUser TEXT,vcsPassword TEXT,created TEXT,lastEdit TEXT,isArchived INTEGER,cem INTEGER)');
        $pdo->exec("INSERT INTO System VALUES(1,'Synthetic system','',1,'','','','','',NULL,NULL,0,0)");
        $pdo->exec('CREATE TABLE Setting(settingId INTEGER PRIMARY KEY,systemId INTEGER,section TEXT,item TEXT,value TEXT)');
        $pdo->exec("INSERT INTO Setting VALUES(1,1,'setup','prepare','enabled'),(2,1,'general','storage','persistent')");
        $job = JobFixture::make(1, ['workers' => 0, 'mode' => 'false'], []);
        $job->setEnvironment($environment);
        $before = $job->getKeyValueDict();
        $first = null;

        foreach ([0, 1, 0] as $currentSystemCem) {
            $pdo->exec('UPDATE System SET cem=' . $currentSystemCem);
            $xml = JobCdl::forJob($job);
            $document = new DOMDocument();
            self::assertTrue($document->loadXML($xml));
            $xpath = new DOMXPath($document);
            self::assertSame($cem ? 1.0 : 0.0, $xpath->evaluate('count(/chronos/setup/prepare)'));
            self::assertSame('persistent', $xpath->evaluate('string(/chronos/evaluation/storage)'));
            self::assertSame('0', $xpath->evaluate('string(/chronos/evaluation/workers)'));
            self::assertSame('false', $xpath->evaluate('string(/chronos/evaluation/mode)'));
            if ($first !== null) self::assertSame($first, $xml, 'Changing the current system must not change whether this stored job requires setup.');
            $first = $xml;
        }

        self::assertSame($before, $job->getKeyValueDict(), 'CDL generation must not rewrite the stored job.');
    }
}
