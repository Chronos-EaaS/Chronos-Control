<?php
declare(strict_types=1);

// A fixed disposable fixture only. No DSN, host, storage path, size or SQL input is accepted.
if (PHP_SAPI !== 'cli' || getenv('CHRONOS_TEST_STACK') !== '1' || getenv('CHRONOS_TEST_V3') !== '1'
    || !is_file('/.dockerenv') || __FILE__ !== '/opt/chronos/tests/fixtures/evaluation-performance.php'
    || count($argv) !== 3 || $argv[1] !== 'chronos-v3-ui-regression' || $argv[2] !== 'seed') {
    fwrite(STDERR, "Refusing evaluation fixtures outside the fixed disposable v3 project.\n"); exit(1);
}
$root = '/opt/chronos';
if (is_link($root) || realpath($root) !== $root || is_link($root . '/config.php')
    || !is_file($root . '/webroot/healthz.txt') || file_get_contents($root . '/webroot/healthz.txt') !== "chronos-test-ready\n"
    || is_link($root . '/webroot/data/evaluation') || realpath($root . '/webroot/data/evaluation') !== $root . '/webroot/data/evaluation') {
    throw new RuntimeException('The fixed evaluation fixture root is unavailable.');
}
define('SERVER_ROOT', $root);
require $root . '/config.php';
if (DB_HOST !== 'db' || DB_NAME !== 'chronos_test' || DB_USER !== 'chronos_test' || DB_PASSWORD !== 'chronos_test_only') {
    throw new RuntimeException('Refusing a nonfixture database configuration.');
}
$db = new PDO('mysql:host=db;dbname=chronos_test;charset=utf8mb4', 'chronos_test', 'chronos_test_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'chronos_test'
    || (int) $db->query("SELECT COUNT(*) FROM Project p JOIN Experiment x ON x.projectId=p.projectId JOIN System s ON s.systemId=p.systemId JOIN User u ON u.userId=p.userId WHERE p.projectId=1 AND p.name='Fixture Project' AND x.experimentId=1 AND x.name='Fixture Experiment' AND s.systemId=1 AND s.name='Fixture System' AND u.userId=3 AND u.username='owner'")->fetchColumn() !== 1
    || (int) $db->query('SELECT COUNT(*) FROM Evaluation WHERE evaluationId=1001')->fetchColumn() !== 0
    || (int) $db->query('SELECT COUNT(*) FROM Job WHERE jobId BETWEEN 100001 AND 101001')->fetchColumn() !== 0) {
    throw new RuntimeException('Reset the identified disposable project before preparing evaluation performance data.');
}
$configuration = json_encode(['parameters'=>['quantity'=>10,'syntheticPayload'=>str_repeat('x',16384)],'title'=>'Synthetic performance job'], JSON_THROW_ON_ERROR);
$result = json_encode(['metric'=>10,'syntheticPayload'=>str_repeat('r',32768)], JSON_THROW_ON_ERROR);
$started = hrtime(true);
$db->beginTransaction();
try {
    $db->exec("INSERT INTO Evaluation(evaluationId,name,description,systemId,experimentId,internalId,isArchived,isStarred) VALUES(1001,'Large synthetic evaluation','Synthetic large-evaluation performance fixture',1,1,1001,0,0)");
    $insert = $db->prepare('INSERT INTO Job(jobId,userId,description,systemId,environment,phases,currentPhase,configuration,status,progress,result,created,started,finished,evaluationId,internalId,configurationIdentifier) VALUES(?,3,?,1,?,0,?,?,?, ?, ?, ?, ?, ?,1001,?,?)');
    for ($internalId = 1; $internalId <= 1001; ++$internalId) {
        $finished = $internalId % 2 === 1;
        $insert->execute([
            100000 + $internalId, sprintf('Performance job %04d', $internalId), 'system-local',
            $finished ? 5 : 3, $configuration, $finished ? 3 : 2, $finished ? 0 : 50, $result,
            '2026-01-15 12:00:00','2026-01-15 12:01:00',$finished ? '2026-01-15 12:02:00' : null,
            $internalId, sha1('synthetic-performance-' . $internalId),
        ]);
    }
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}
echo json_encode(['project'=>'chronos-v3-ui-regression','database'=>'chronos_test','evaluationId'=>1001,'jobs'=>1001,'firstJobId'=>100001,'lastJobId'=>101001,'configurationBytes'=>strlen($configuration),'resultBytes'=>strlen($result),'seedMilliseconds'=>round((hrtime(true)-$started)/1e6,3)], JSON_THROW_ON_ERROR) . "\n";
// The normal test-stack reset removes these synthetic database rows; no extra storage is created.
