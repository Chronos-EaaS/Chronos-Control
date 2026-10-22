<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Infrastructure\Realtime\ChangeJournal;
use Chronos\Infrastructure\Realtime\NotifyingPDO;
use PDO;
use PHPUnit\Framework\TestCase;
final class LiveJournalTest extends TestCase
{
    private function database(): NotifyingPDO
    {
        $db = new NotifyingPDO('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE LiveChange(id INTEGER PRIMARY KEY AUTOINCREMENT,topic TEXT,resourceId TEXT)');
        $db->exec('CREATE TABLE Job(jobId INTEGER PRIMARY KEY AUTOINCREMENT,progress INTEGER NOT NULL)');
        return $db;
    }

    public function testAutocommitStatementsRetainBusinessIdentityAndJournalAtomically(): void
    {
        $db = $this->database();
        (new ChangeJournal($db))->record('Fixture');
        $statement = $db->prepare('INSERT INTO `Job` (progress) VALUES (?)');
        self::assertTrue($statement->execute([40]));
        self::assertSame('1', $db->lastInsertId());
        self::assertFalse($db->inTransaction());
        self::assertSame(['Fixture', 'Job'], array_column((new ChangeJournal($db))->drain(), 'topic'));
        self::assertSame(40, (int) $db->query('SELECT progress FROM Job')->fetchColumn());
    }

    public function testRollbackCannotLeaveAFalseChangeAndCommitDoesNotReplaceInsertIdentity(): void
    {
        $db = $this->database();
        $journal = new ChangeJournal($db);
        $db->beginTransaction();
        $db->exec('INSERT INTO Job(progress) VALUES (10)');
        self::assertCount(1, $journal->drain());
        $db->rollBack();
        self::assertSame([], $journal->drain());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM Job')->fetchColumn());
        $db->beginTransaction();
        $db->exec('INSERT INTO Job(progress) VALUES (20)');
        $db->exec('UPDATE Job SET progress=30');
        $id = $db->lastInsertId();
        $db->commit();
        self::assertSame($id, $db->lastInsertId());
        self::assertCount(2, $journal->drain());
    }

    public function testJournalFailureRollsBackAnOtherwiseSuccessfulAutocommitWrite(): void
    {
        $db = $this->database();
        $db->exec('DROP TABLE LiveChange');
        try {
            $db->exec('INSERT INTO Job(progress) VALUES (10)');
            self::fail('Missing outbox was silently ignored.');
        } catch (\PDOException) {
            self::assertFalse($db->inTransaction());
        }
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM Job')->fetchColumn());
    }

    public function testAcknowledgementDeletesOnlyTheDeliveredRowsWithoutAnIdCursor(): void
    {
        $db = $this->database();
        $journal = new ChangeJournal($db);
        $db->exec("INSERT INTO LiveChange(id,topic) VALUES(2,'Job')");
        $first = $journal->drain();
        $journal->acknowledge(array_column($first, 'id'));
        // Models a lower ID becoming visible after a later transaction committed.
        $db->exec("INSERT INTO LiveChange(id,topic) VALUES(1,'Event')");
        self::assertSame([['id' => 1, 'topic' => 'Event', 'resourceId' => null]], $journal->drain());
        $journal->acknowledge([1]);
        self::assertSame([], $journal->drain());
    }

    public function testReadQueriesAndJournalHousekeepingNeverCreateRecursiveChanges(): void
    {
        $db = $this->database();
        $statement = $db->prepare('SELECT ? AS progress');
        $statement->execute([1]);
        self::assertSame([], (new ChangeJournal($db))->drain());
        self::assertNull(NotifyingPDO::changedTable('SELECT * FROM Job'));
        self::assertSame('Job', NotifyingPDO::changedTable(' UPDATE `Job` SET progress=? WHERE jobId=?'));
        self::assertNull(NotifyingPDO::changedTable('DELETE FROM `LiveChange` WHERE id=?'));
        ChangeJournal::notify('/tmp/chronos-nonexistent-notification-root');
        self::assertSame([], (new ChangeJournal($db))->drain());
    }

    public function testStatementFactoryDoesNotKeepReplacedWorkerConnectionsAlive(): void
    {
        $database = $this->database();
        $reference = \WeakReference::create($database);
        unset($database);
        gc_collect_cycles();
        self::assertNull($reference->get());
    }

    public function testMaintainerChangesAreJournalledWithTheirBusinessTransaction(): void
    {
        $db = $this->database();
        $db->exec('CREATE TABLE SystemMaintainer(systemId INTEGER NOT NULL,userId INTEGER NOT NULL,PRIMARY KEY(systemId,userId))');
        $journal = new ChangeJournal($db);
        $db->exec('INSERT INTO `SystemMaintainer` (systemId,userId) VALUES (1,3)');
        self::assertSame(['SystemMaintainer'], array_column($journal->drain(), 'topic'));
        $journal->acknowledge(array_column($journal->drain(), 'id'));
        $db->beginTransaction();
        $statement = $db->prepare('DELETE FROM `SystemMaintainer` WHERE systemId=? AND userId=?');
        $statement->execute([1, 3]);
        $db->rollBack();
        self::assertSame([], $journal->drain());
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM SystemMaintainer')->fetchColumn());
        $statement->execute([1, 3]);
        self::assertSame(['SystemMaintainer'], array_column($journal->drain(), 'topic'));
    }

    public function testAnOutstandingStatementRetainsItsConnectionUntilReleased(): void
    {
        $database = $this->database();
        $reference = \WeakReference::create($database);
        $statement = $database->prepare('SELECT 1');
        unset($database);
        self::assertNotNull($reference->get());
        self::assertTrue($statement->execute());
        unset($statement);
        gc_collect_cycles();
        self::assertNull($reference->get());
    }
}
