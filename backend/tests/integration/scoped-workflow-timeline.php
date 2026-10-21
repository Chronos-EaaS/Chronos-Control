<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/application-autoload.php';

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Input;
use Chronos\Application\Workflows\WorkflowProjection;
use Chronos\Http\Api\Ui\WorkflowRoutes;
use Chronos\Http\HttpError;
use Chronos\Http\Request;
use Chronos\Infrastructure\Persistence\Store;

// Never read application configuration or accept a configurable database target.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run' || getenv('CHRONOS_TEST_MIGRATIONS') !== '1' || !is_file('/.dockerenv')) {
    throw new RuntimeException('Run only in the explicitly enabled isolated migration fixture.');
}
$database = new PDO('mysql:host=chronos-migration-db;dbname=chronos_migration_test;charset=utf8mb4', 'chronos_migration', 'chronos_migration_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($database->query('SELECT DATABASE()')->fetchColumn() !== 'chronos_migration_test'
    || !str_starts_with((string) $database->query('SELECT CURRENT_USER()')->fetchColumn(), 'chronos_migration@')) {
    throw new RuntimeException('Refusing to change an unexpected database or account.');
}
date_default_timezone_set('Europe/Zurich');
$checks = 0;
function checkScopedTimeline(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}

try {
    $objects = $database->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach (['VIEW', 'BASE TABLE'] as $kind) {
        foreach ($objects as [$name, $type]) {
            if ($type !== $kind) continue;
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) throw new RuntimeException('Unexpected fixture object.');
            $database->exec(($kind === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . '`' . $name . '`');
        }
    }
    foreach ([
        '`User` (userId INT PRIMARY KEY,firstname VARCHAR(50),lastname VARCHAR(50))',
        '`System` (systemId INT PRIMARY KEY,name VARCHAR(50))',
        '`Project` (projectId INT PRIMARY KEY,systemId INT,userId INT,name VARCHAR(50))',
        '`ProjectUser` (projectUserId INT PRIMARY KEY,projectId INT,userId INT)',
        '`Experiment` (experimentId INT PRIMARY KEY,projectId INT,systemId INT,internalId INT,name VARCHAR(50),INDEX(projectId))',
        '`Evaluation` (evaluationId INT PRIMARY KEY,experimentId INT,systemId INT,internalId INT,name VARCHAR(50),INDEX(experimentId))',
        '`Job` (jobId INT PRIMARY KEY,evaluationId INT,systemId INT,internalId INT,INDEX(evaluationId))',
        '`Setting` (systemId INT,section VARCHAR(50),item VARCHAR(50),value TEXT)',
        '`Event` (eventId INT PRIMARY KEY,title VARCHAR(256),time DATETIME,eventText TEXT,eventType VARCHAR(50),relatedId INT,userId INT,nodeId VARCHAR(64),INDEX(relatedId),INDEX(time,eventId),INDEX chronos_event_resource_timeline(eventType,relatedId,eventId,time))',
    ] as $definition) $database->exec('CREATE TABLE ' . $definition . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8_unicode_ci');
    $database->exec("INSERT INTO `User` VALUES(1,'Synthetic','Owner'); INSERT INTO `System` VALUES(1,'Fixture'); INSERT INTO `Project` VALUES(1,1,1,'Own project'),(2,1,2,'Shared project'); INSERT INTO `ProjectUser` VALUES(1,2,1),(2,2,1); INSERT INTO `Experiment` VALUES(1,1,1,1,'Own experiment'),(2,1,1,2,'Sibling experiment'),(3,2,1,1,'Shared experiment'); INSERT INTO `Evaluation` VALUES(1,1,1,1,'Own evaluation'),(2,2,1,1,'Sibling evaluation'),(3,3,1,1,'Shared evaluation'); INSERT INTO `Job` VALUES(1,1,1,1),(2,1,1,2),(3,2,1,1),(4,3,1,1)");
    $insert = $database->prepare('INSERT INTO `Event` VALUES(?,?,?,?,?,?,?,NULL)');
    $records = [
        [1,'Project &amp; progress','2026-01-02 12:00:00','50% done_1','project',1,1],
        [2,'Experiment &lt;delta&gt;','2026-01-01 12:00:00','A &amp;amp; B','experiment',1,1],
        [3,'Zo&euml; &alpha;','2026-01-01 12:00:00','&#x1F680; rocket &#945;','evaluation',1,null],
        [4,'Own job','2026-01-01 12:00:00','green','job',1,999],
        [5,'Other own job','2026-01-01 12:00:00','blue','job',2,1],
        [6,'Sibling job','2026-01-01 12:00:00','yellow','job',3,1],
        [7,'Shared job','2026-01-01 12:00:00','red','job',4,1],
        [8,'Unrelated type','2026-01-02 12:00:00','private','node',1,1],
        [9,'Deleted job','2026-01-02 12:00:00','orphan','job',999,1],
        [10,'Sibling experiment','2026-01-01 12:00:00','yellow','experiment',2,1],
        [11,'Sibling evaluation','2026-01-01 12:00:00','yellow','evaluation',2,1],
        [12,'Same project timestamp','2026-01-02 12:00:00','second','project',1,1],
    ];
    foreach ($records as $record) $insert->execute($record);
    $context = new Context(new Store($database), ['id'=>1,'role'=>0], '/synthetic');
    $projection = new WorkflowProjection($context);
    $scopes = ['project'=>[1,2,3,4,5,6,10,11,12], 'experiment'=>[2,3,4,5], 'evaluation'=>[3,4,5], 'job'=>[4]];
    foreach ($scopes as $type => $ids) {
        foreach (['id','-id','at','-at'] as $sort) {
            $expected = array_values(array_filter($records, static fn(array $record): bool => in_array($record[0], $ids, true)));
            usort($expected, static function (array $left, array $right) use ($sort): int {
                $column = str_contains($sort, 'at') ? 2 : 0;
                $order = $left[$column] <=> $right[$column];
                if (str_starts_with($sort, '-')) $order = -$order;
                return $order ?: ($left[0] <=> $right[0]);
            });
            foreach ([1,2,999] as $requestedPage) {
                $options = Input::listOptions(['sort'=>$sort,'page'=>(string) $requestedPage,'pageSize'=>'2'], ['id'=>'v.eventId','at'=>'v.time'], '-id');
                $result = $projection->events($type, 1, $options);
                $page = min($requestedPage, max(1, (int) ceil(count($ids) / 2)));
                checkScopedTimeline(array_column($result['items'], 'id') === array_column(array_slice($expected, ($page - 1) * 2, 2), 0), $type . ': real MySQL preserves ' . $sort . ' order and page ' . $requestedPage);
                checkScopedTimeline($result['total'] === count($ids) && $result['page'] === $page && $result['pageSize'] === 2, $type . ': exact totals and last-page clamping');
            }
        }
    }
    foreach ([['&',[2,1]], ['50%',[1]], ['done_',[1]], ['<delta>',[2]], ['&amp;',[2]], ['Zoë',[3]], ['α',[3]], ['🚀',[3]], ['green',[4]], ["%' OR 1=1 --",[]], ['missing',[]]] as [$search,$ids]) {
        $result = $projection->events('project', 1, Input::listOptions(['search'=>$search], ['id'=>'v.eventId','at'=>'v.time'], '-id'));
        checkScopedTimeline(array_column($result['items'], 'id') === $ids && $result['total'] === count($ids), 'Real MySQL decodes once and searches literal displayed text: ' . $search);
    }
    $shared = $projection->events('project', 2, Input::listOptions([], ['id'=>'v.eventId','at'=>'v.time'], '-id'));
    checkScopedTimeline(array_column($shared['items'], 'id') === [7] && $shared['total'] === 1, 'Duplicate memberships do not duplicate events.');
    $route = new WorkflowRoutes(new Request('GET', '/api/ui/projects/1/events'), new Store($database), ['id'=>2,'role'=>0], '/synthetic');
    try {
        $route->handle('/projects/1/events');
        throw new RuntimeException('Expected forbidden resource timeline.');
    } catch (HttpError $error) {
        checkScopedTimeline($error->status === 403, 'HTTP and live resource routes retain their resource authorization gate.');
    }
    $job = $projection->events('job', 1, Input::listOptions([], ['id'=>'v.eventId','at'=>'v.time'], '-id'))['items'][0];
    checkScopedTimeline($job['actorName'] === '' && $job['resources'][3] === ['type'=>'job','id'=>1,'label'=>'Job #1'], 'A deleted actor retains the event and complete authorized navigation.');
    echo $checks . ' scoped workflow timeline assertions passed on PHP ' . PHP_VERSION . ".\n";
} finally {
    // The wrapper removes this entire isolated database volume after the suite.
    $database = null;
}
