<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Extensions\ExtensionService;
use Chronos\Application\Projects\ProjectService;
use Chronos\Application\Shared\Context;
use Chronos\Application\Systems\{SystemCatalog, SystemCreation, SystemMaintainers, SystemSettings};
use Chronos\Http\Api\Ui\SystemRoutes;
use Chronos\Http\HttpError;
use Chronos\Http\Request;
use Chronos\Infrastructure\Persistence\Store;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** SQLite changes SQL dialect only; real services, predicates and transactions remain intact. */
final class SystemMaintainerDatabase extends \PDO
{
    public ?\Closure $beforeSystemLock = null;
    public ?\Closure $beforeMembershipLock = null;
    public int $reads = 0;

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        ++$this->reads;
        if (str_contains($query, 'FROM `System` s') && str_ends_with($query, ' FOR UPDATE') && $this->beforeSystemLock !== null) {
            $before = $this->beforeSystemLock; $this->beforeSystemLock = null; $before($this);
        }
        if (str_starts_with($query, 'SELECT userId FROM `SystemMaintainer`') && str_ends_with($query, ' FOR UPDATE') && $this->beforeMembershipLock !== null) {
            $before = $this->beforeMembershipLock; $this->beforeMembershipLock = null; $before($this);
        }
        return parent::prepare(str_replace(["CONCAT_WS(' ',owner.firstname,owner.lastname)", ' FOR UPDATE'], ["(owner.firstname || ' ' || owner.lastname)", ''], $query), $options);
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SystemMaintainersTest extends TestCase
{
    private SystemMaintainerDatabase $database;
    private string $root;

    protected function setUp(): void
    {
        define('ONLY_ACTIVATED_USERS', true);
        $this->database = new SystemMaintainerDatabase('sqlite::memory:');
        $this->database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->database->exec('CREATE TABLE User(userId INTEGER PRIMARY KEY,username TEXT,email TEXT,firstname TEXT,lastname TEXT,gender INTEGER,role INTEGER,enabled INTEGER,activated INTEGER,password TEXT); CREATE TABLE System(systemId INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,description TEXT,userId INTEGER,isArchived INTEGER,cem INTEGER,created TEXT,lastEdit TEXT,vcsUrl TEXT,vcsType TEXT,vcsBranch TEXT,vcsUser TEXT,vcsPassword TEXT); CREATE TABLE SystemMaintainer(systemId INTEGER,userId INTEGER,PRIMARY KEY(systemId,userId)); CREATE TABLE Setting(settingId INTEGER PRIMARY KEY AUTOINCREMENT,systemId INTEGER,section TEXT,item TEXT,value TEXT); CREATE TABLE Project(projectId INTEGER PRIMARY KEY AUTOINCREMENT,userId INTEGER,systemId INTEGER,name TEXT,description TEXT,isArchived INTEGER,isFinished INTEGER,environment TEXT); CREATE TABLE ProjectUser(projectUserId INTEGER PRIMARY KEY AUTOINCREMENT,projectId INTEGER,userId INTEGER); CREATE TABLE Event(eventId INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,time TEXT,eventText TEXT,eventType TEXT,relatedId INTEGER,userId INTEGER,nodeId TEXT)');
        $this->database->exec("INSERT INTO User VALUES(1,'owner','owner@example.test','Original','Owner',0,0,1,1,'private'),(2,'maintainer','maintainer@example.test','Another','Maintainer',0,0,1,1,'private'),(3,'outsider','outsider@example.test','Private','Outsider',0,0,1,1,'private'),(4,'disabled','disabled@example.test','Disabled','Account',0,0,0,1,'private'),(5,'inactive','inactive@example.test','Inactive','Account',0,0,1,0,'private'),(6,'admin','admin@example.test','Application','Admin',0,1,1,1,'private'); INSERT INTO System VALUES(10,'Shared system','Unchanged',1,0,0,'2026-01-15 12:00:00','2026-01-15 12:00:00','','git','master','',''),(11,'Private system','Private',3,0,0,'2026-01-15 12:00:00','2026-01-15 12:00:00','','git','master','',''); INSERT INTO SystemMaintainer VALUES(10,1),(10,2),(11,3); INSERT INTO Project VALUES(42,3,10,'Private project','Unchanged',0,0,''); INSERT INTO ProjectUser(projectId,userId) VALUES(42,3)");
        $this->root = sys_get_temp_dir() . '/chronos-maintainer-unit-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/webroot/systems/10', 0700, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    private function context(int $userId): Context
    {
        return new Context(new Store($this->database), ['id' => $userId, 'role' => $userId === 6 ? 1 : 0], $this->root);
    }

    private function service(int $userId = 1): SystemMaintainers { return new SystemMaintainers($this->context($userId)); }

    private function rejects(int $status, callable $operation): HttpError
    {
        try { $operation(); self::fail('The operation unexpectedly succeeded.'); }
        catch (HttpError $error) { self::assertSame($status, $error->status); return $error; }
    }

    public function testMaintainerListingAndPermissionsAreScopedWithoutPerRowQueries(): void
    {
        $this->database->reads = 0;
        $page = (new SystemCatalog($this->context(2)))->systems(['pageSize' => '20']);
        self::assertSame(2, $this->database->reads);
        self::assertSame([10], array_column($page['items'], 'id'));
        self::assertSame(['canEdit' => true, 'canManageMaintainers' => false, 'canChangeOwner' => false], $page['items'][0]['permissions']);
        self::assertSame(1, $page['items'][0]['ownerId']);
        $this->rejects(403, fn() => $this->context(2)->system(11));
        $this->rejects(403, fn() => (new SystemCatalog($this->context(2)))->systems(['all' => 'true']));
        self::assertSame(2, (new SystemCatalog($this->context(6)))->systems(['all' => 'true'])['total']);
        self::assertTrue($this->service()->read(10, [])['permissions']['canManageMaintainers']);
        self::assertFalse($this->service(2)->read(10, [])['permissions']['canChangeOwner']);
    }

    public function testMembersExposeEligibilityAndCandidatesExcludeOwnersExistingAndIneligibleAccounts(): void
    {
        $this->database->exec('INSERT INTO SystemMaintainer VALUES(10,4),(10,5)');
        $members = array_column($this->service()->read(10, [])['items'], null, 'id');
        self::assertFalse($members[4]['enabled']); self::assertFalse($members[4]['eligible']);
        self::assertFalse($members[5]['activated']); self::assertFalse($members[5]['eligible']);
        self::assertTrue($members[1]['eligible']); self::assertArrayNotHasKey('password', $members[1]);
        self::assertArrayNotHasKey('role', $members[1]);
        $ids = array_column($this->service()->read(10, ['candidates' => 'true'])['items'], 'id'); sort($ids);
        self::assertSame([3, 6], $ids);
        $this->rejects(403, fn() => $this->service(2)->read(10, ['candidates' => 'true']));
        $this->rejects(403, fn() => $this->service(3)->read(10, []));
        $this->rejects(422, fn() => $this->service()->read(10, ['unexpected' => 'true']));
    }

    public function testAddingAndRemovingMaintainersIsIdempotentAndDoesNotGrantProjectAccess(): void
    {
        self::assertSame(3, $this->service()->add(10, ['userId' => 3])['id']);
        $this->service()->add(10, ['userId' => 3]);
        self::assertSame(3, count($this->service()->read(10, [])['items']));
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM Event')->fetchColumn());
        $this->context(3)->system(10);
        $this->rejects(403, fn() => $this->context(2)->project(42));
        self::assertSame(['removed' => true], $this->service()->remove(10, 3));
        self::assertSame(['removed' => false], $this->service()->remove(10, 3));
        self::assertSame(2, (int) $this->database->query('SELECT COUNT(*) FROM Event')->fetchColumn());
        $this->rejects(403, fn() => $this->context(3)->system(10));
    }

    public function testOnlyEligibleAccountsCanBeAddedAndRejectedWritesAreAtomic(): void
    {
        foreach ([['userId' => 4], ['userId' => 5], ['userId' => '01'], ['userId' => 3, 'ownerId' => 3]] as $body) $this->rejects(422, fn() => $this->service()->add(10, $body));
        $this->rejects(404, fn() => $this->service()->add(10, ['userId' => 999]));
        self::assertSame(2, count($this->service()->read(10, [])['items']));
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM Event')->fetchColumn());
    }

    public function testOrdinaryMaintainersCannotManageAccessAndTheOwnerCannotBeRemoved(): void
    {
        $this->rejects(403, fn() => $this->service(2)->add(10, ['userId' => 3]));
        $this->rejects(403, fn() => $this->service(2)->remove(10, 1));
        $this->rejects(422, fn() => $this->service()->remove(10, 1));
        $this->rejects(422, fn() => $this->service(6)->remove(10, 1));
        self::assertSame(3, $this->service(6)->add(10, ['userId' => 3])['id']);
        self::assertSame(['removed' => true], $this->service(6)->remove(10, 3));
        $this->database->exec('DELETE FROM SystemMaintainer WHERE systemId=10 AND userId=1');
        $this->rejects(422, fn() => $this->service()->remove(10, 2));
    }

    public function testOwnershipDesignationRetainsThePreviousOwnerUntilExplicitRemoval(): void
    {
        $value = (new SystemSettings($this->context(1), $this->root))->update(10, ['ownerId' => 2]);
        self::assertSame(2, $value['ownerId']);
        self::assertTrue($value['permissions']['canEdit']); self::assertFalse($value['permissions']['canManageMaintainers']);
        self::assertSame(2, count($this->service(2)->read(10, [])['items']));
        self::assertSame(['removed' => true], $this->service(2)->remove(10, 1));
        $this->rejects(403, fn() => $this->context(1)->system(10));
        self::assertSame(3, (int) $this->database->query('SELECT userId FROM Project WHERE projectId=42')->fetchColumn());
    }

    public function testOwnershipTransferRequiresAnExistingEligibleMaintainerAndNeverPartiallyUpdatesMetadata(): void
    {
        $this->rejects(422, fn() => (new SystemSettings($this->context(1), $this->root))->update(10, ['ownerId' => 3, 'description' => 'Must not persist']));
        $this->database->exec('INSERT INTO SystemMaintainer VALUES(10,4),(10,5)');
        foreach ([4, 5] as $userId) $this->rejects(422, fn() => (new SystemSettings($this->context(6), $this->root))->update(10, ['ownerId' => $userId, 'description' => 'Must not persist']));
        $this->rejects(403, fn() => (new SystemSettings($this->context(2), $this->root))->update(10, ['ownerId' => 2]));
        self::assertSame(['userId' => 1, 'description' => 'Unchanged'], $this->database->query('SELECT userId,description FROM System WHERE systemId=10')->fetch(\PDO::FETCH_ASSOC));
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM Event')->fetchColumn());
    }

    public function testFreshMembershipReadsRevokeAnExistingContextWithoutCachingGrants(): void
    {
        $context = $this->context(2);
        self::assertTrue((bool) $context->system(10)['isMaintainer']);
        $this->database->exec('DELETE FROM SystemMaintainer WHERE systemId=10 AND userId=2');
        $this->rejects(403, fn() => $context->system(10));
        self::assertSame([], (new SystemCatalog($context))->systems([])['items']);
    }

    public function testAccessManagementAndTransferRecheckTheOwnerAfterAcquiringTheSystemLock(): void
    {
        foreach (['add', 'transfer'] as $action) {
            $this->database->beforeSystemLock = static fn(\PDO $database) => $database->exec('UPDATE System SET userId=2 WHERE systemId=10');
            $this->rejects(403, fn() => $action === 'add' ? $this->service()->add(10, ['userId' => 3]) : (new SystemSettings($this->context(1), $this->root))->update(10, ['ownerId' => 2]));
            self::assertSame(1, (int) $this->database->query('SELECT userId FROM System WHERE systemId=10')->fetchColumn());
            self::assertSame(2, count($this->service()->read(10, [])['items']));
        }
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM Event')->fetchColumn());
    }

    public function testProjectCreationAcceptsMaintainersButRechecksRevocationAfterTheSystemLock(): void
    {
        $projects = new ProjectService($this->context(2));
        $this->database->beforeMembershipLock = static fn(\PDO $database) => $database->exec('DELETE FROM SystemMaintainer WHERE systemId=10 AND userId=2');
        $this->rejects(403, fn() => $projects->createProject(['name' => 'Denied during recheck', 'systemId' => 10]));
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM Project')->fetchColumn());
        $project = $projects->createProject(['name' => 'Maintained system project', 'systemId' => 10]);
        self::assertSame(2, $project['ownerId']); self::assertSame(10, $project['systemId']);
        $this->rejects(403, fn() => $this->context(2)->project(42));
    }

    public function testFailedSystemCreationRollsBackItsNewOwnerAssociation(): void
    {
        mkdir($this->root . '/webroot/systems/12', 0700);
        try { (new SystemCreation($this->context(6)))->createSystem(['name' => 'Blocked storage', 'ownerId' => 2]); self::fail('Existing storage must prevent creation.'); }
        catch (\RuntimeException $error) { self::assertSame('System storage cannot be created.', $error->getMessage()); }
        self::assertSame(2, (int) $this->database->query('SELECT COUNT(*) FROM System')->fetchColumn());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM SystemMaintainer WHERE systemId=12')->fetchColumn());
    }

    public function testMaintainerRoutesDispatchOnlyTheSupportedMethodsAndReturnEligibility(): void
    {
        $store = new Store($this->database); $actor = ['id' => 1, 'role' => 0];
        $route = new SystemRoutes(new Request('GET', '/api/ui/systems/10/maintainers'), $store, $actor, $this->root);
        $value = $route->handle('/systems/10/maintainers');
        self::assertSame(200, $value['status']); self::assertSame(1, $value['body']['data']['ownerId']);
        self::assertTrue($value['body']['data']['items'][0]['eligible']);
        $request = new Request('POST', '/api/ui/systems/10/maintainers', ['content-type' => 'application/json'], '{"userId":3}');
        self::assertSame(3, (new SystemRoutes($request, $store, $actor, $this->root))->handle('/systems/10/maintainers')['body']['data']['id']);
        $request = new Request('DELETE', '/api/ui/systems/10/maintainers/3');
        self::assertSame(['removed' => true], (new SystemRoutes($request, $store, $actor, $this->root))->handle('/systems/10/maintainers/3')['body']['data']);
        $this->rejects(405, fn() => (new SystemRoutes(new Request('PATCH', '/api/ui/systems/10/maintainers'), $store, $actor, $this->root))->handle('/systems/10/maintainers'));
    }

    public function testMaintainersSeeOwnerExtensionPagesWithoutChangingPrivateProjectMembership(): void
    {
        file_put_contents($this->root . '/webroot/systems/10/extensions.v3.json', json_encode(['format' => 1, 'pages' => [['id' => 'private', 'title' => 'Owner page', 'access' => 'owner', 'blocks' => [['type' => 'text', 'text' => 'Maintainer-visible']]]]], JSON_THROW_ON_ERROR));
        $service = new ExtensionService($this->context(2));
        self::assertSame('private', $service->read(10, null)['pages'][0]['id']);
        self::assertSame('private', $service->read(10, 'private')['id']);
        $this->rejects(403, fn() => $this->context(2)->project(42));
        $this->rejects(403, fn() => (new ExtensionService($this->context(3)))->read(10, 'private'));
    }
}
