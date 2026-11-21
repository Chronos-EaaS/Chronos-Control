<?php
declare(strict_types=1);

use Chronos\Application\Results\{ResultDownloads, ResultPresentation};
use Chronos\Application\Shared\Context;
use Chronos\Infrastructure\Persistence\Migrations\{MigrationException, MigrationRunner, SystemMaintainersMigration};
use Chronos\Infrastructure\Persistence\Store;

require_once __DIR__ . '/../support/application-autoload.php';
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run' || getenv('CHRONOS_TEST_MIGRATIONS') !== '1' || !is_file('/.dockerenv')) {
    throw new RuntimeException('Run only in the explicitly enabled isolated migration fixture.');
}

date_default_timezone_set('Europe/Zurich');
$pdo = new PDO('mysql:host=chronos-migration-db;dbname=chronos_migration_test;charset=utf8mb4', 'chronos_migration', 'chronos_migration_only');
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'chronos_migration_test'
    || !str_starts_with((string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn(), 'chronos_migration@')) {
    throw new RuntimeException('Refusing to change an unexpected database or account.');
}
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) throw new RuntimeException($message);
}
function clearExecutionFixture(PDO $pdo): void
{
    $pdo->exec('DROP TABLE IF EXISTS JobAttempt,JobSpecification,ProjectComparison,BenchmarkBinding,BenchmarkRevision');
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!preg_match('/^[a-z0-9_]+$/iD', $table)) throw new RuntimeException('Unexpected fixture table.');
        $pdo->exec('DROP TABLE `' . $table . '`');
    }
}
function client(string $path, string $method = 'GET', ?array $body = null, array $headers = []): CurlHandle
{
    global $address;
    $curl = curl_init($address . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 12]);
    if ($body !== null) {
        if (str_starts_with($path, '/api/ui/')) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode((object) $body, JSON_THROW_ON_ERROR);
        } elseif (!isset($body['result']) || !$body['result'] instanceof CURLFile) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query($body);
        }
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    return $curl;
}
function response(CurlHandle $curl, int $expectedStatus = 200, ?int $expectedCode = null): mixed
{
    $body = curl_exec($curl);
    verify(is_string($body) && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === $expectedStatus,
        'Unexpected HTTP response: ' . ($body === false ? curl_error($curl) : substr($body, 0, 500)));
    if ($expectedCode === null) return $body;
    return agentEnvelope($body, $expectedCode);
}
function agentEnvelope(string $body, int $expectedCode): mixed
{
    $envelope = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    verify($envelope['status']['code'] === $expectedCode, 'Unexpected agent response: ' . substr($body, 0, 500));
    return $envelope['response'] ?? null;
}
function ui(string $path, string $method = 'GET', ?array $body = null, int $status = 200, array $headers = []): mixed
{
    return json_decode(response(client('/api/ui' . $path, $method, $body, $headers), $status), true, 64, JSON_THROW_ON_ERROR)['data'] ?? null;
}
function agent(string $path, string $method = 'GET', ?array $body = null, ?string $token = null, int $code = 200): mixed
{
    $headers = $token === null ? [] : ['X-Chronos-Attempt-Token: ' . $token];
    return response(client('/api/v1/' . $path, $method, $body, $headers), 200, $code);
}

function testCatalogAndMemberCopy(Store $db, string $root, array $definition): void
{
    // Re-publish the public parameterless contract and read each catalog group page by page.
    $empty = ['key' => 'parameterless', 'version' => '1', 'name' => 'Parameterless',
        'contract' => ['parameters' => new stdClass(), 'measurement' => 'Measure duration.',
            'lifecycle' => ['warmUp' => 'disabled'], 'results' => ['duration' => ['type' => 'integer']]]];
    $record = json_decode(response(client('/api/ui/benchmarks', 'POST', $empty), 201))->data;
    verify($record->contract->parameters instanceof stdClass, 'Publication changed the empty parameter map.');
    $empty['version'] = '2';
    $empty['contract'] = $record->contract;
    $second = json_decode(response(client('/api/ui/benchmarks', 'POST', $empty), 201))->data;
    $emptyBinding = json_decode(response(client('/api/ui/benchmarks/' . $second->id . '/bindings', 'POST', [
        'systemId' => 2, 'version' => '1', 'definition' => array_replace($definition, ['parameterMap' => new stdClass(), 'constants' => new stdClass()]),
    ]), 201))->data;
    verify($emptyBinding->definition->parameterMap instanceof stdClass && $emptyBinding->definition->constants instanceof stdClass,
        'Publication changed the empty binding maps.');
    $seen = [];
    $page = 1;
    do {
        $catalog = json_decode(response(client('/api/ui/benchmarks?page=' . $page . '&pageSize=1')))->data;
        verify($catalog->page === $page && $catalog->pageSize === 1 && count($catalog->bindings) <= 1 && count($catalog->benchmarks) <= 1,
            'The benchmark catalog returned an unbounded or incorrect page.');
        foreach ($catalog->benchmarks as $entry) {
            if ($entry->key === 'parameterless') verify($entry->contract->parameters instanceof stdClass, 'Listing changed the empty contract.');
            $seen[] = $entry->id;
        }
        $page = $catalog->nextPage;
    } while ($page !== null);
    verify(count($seen) === count(array_unique($seen)) && in_array($second->id, $seen, true), 'Catalog pagination lost or repeated a revision.');

    // A member of the project has no system-level rights on its bound secondary system.
    $db->insert('ProjectUser', ['userId' => 2, 'projectId' => 1]);
    $member = ['X-Fixture-Outsider: 1'];
    verify(ui('/benchmarks', headers: $member)['systems'] === [], 'The member unexpectedly has system-maintainer rights.');
    file_put_contents($root . '/webroot/systems/2/extensions.v3.json', '{"format":1,"parameters":[{"type":"trial-count","name":"Count","baseType":"integer"}]}');
    $source = ui('/projects/1/experiments', 'POST', ['name' => 'No inputs', 'bindingId' => $emptyBinding->id, 'planning' => 'generated'], 201);
    $options = ui('/projects/1/experiment-options?copy=' . $source['id'], headers: $member);
    verify($options['systemId'] === 2 && $options['parameterAliases'] === ['trial-count' => 'integer'], 'Secondary-system copy omitted its parameter aliases.');
    $copy = ui('/projects/1/experiments', 'POST', ['name' => 'Member copy', 'sourceExperimentId' => $source['id'],
        'bindingId' => $emptyBinding->id, 'planning' => 'generated'], 201, $member);
    verify($copy['systemId'] === 2 && $copy['bindingId'] === $emptyBinding->id, 'A project member could not copy the authorized secondary-system experiment.');
}

function testReferenceWorker(string $python, Store $db, string $root, array $benchmark, array $definition): void
{
    global $address;
    $cache = $root . '/artifact-cache';
    mkdir($cache, 0700);
    $driver = <<<'DRIVER'
#!/bin/sh
read -r input
case "$input" in
  *'"threads": 2'*) printf '{"duration":0}\n'; printf 'synthetic driver evidence\n' >&2 ;;
  *) printf 'incorrect resolved inputs\n' >&2; exit 1 ;;
esac
DRIVER;
    $artifact = ['role' => 'driver', 'uri' => 'staged:reference-worker-fixture',
        'sha256' => hash('sha256', $driver), 'sizeBytes' => strlen($driver)];
    $driverFile = $cache . '/' . $artifact['sha256'];
    $definition['artifacts']['artifacts'] = [$artifact];
    $binding = ui('/benchmarks/' . $benchmark['id'] . '/bindings', 'POST', ['systemId' => 1, 'version' => 'worker-smoke', 'definition' => $definition], 201);
    $db->insert('Setting', ['systemId' => 1, 'section' => 'environments', 'item' => 'worker-smoke', 'value' => '']);
    $experiment = ui('/projects/1/experiments', 'POST', ['name' => 'Reference worker', 'bindingId' => $binding['id'], 'planning' => 'explicit'], 201);
    $evaluation = ui('/experiments/' . $experiment['id'] . '/evaluations', 'POST', [
        'environment' => 'system-worker-smoke', 'jobs' => [['parameters' => ['workers' => 2]]],
    ], 201);
    $jobId = (int) $db->row('SELECT jobId FROM Job WHERE evaluationId=?', [$evaluation['id']])['jobId'];
    $runWorker = static function (int $expected) use ($python, $address, $cache, $root): void {
        $output = $root . '/worker.log';
        $worker = proc_open([$python, '/opt/chronos/tools/workers/chronos-command.py', $address, '--cache', $cache,
            '--worker', 'reference-fixture', '--environment', 'worker-smoke', '--timeout', '10'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']], $pipes);
        verify(is_resource($worker), 'Unable to start the reference worker.');
        verify(proc_close($worker) === $expected, 'Reference worker exited unexpectedly: ' . file_get_contents($output));
    };
    file_put_contents($driverFile, str_repeat('z', strlen($driver)));
    $runWorker(1);
    $failed = ui('/jobs/' . $jobId . '/execution')['attempts'][0];
    verify($failed['status'] === 'failed' && str_contains($failed['reason'], 'SHA-256 mismatch'), 'A corrupt artifact was run or missing evidence was invented.');
    ui('/jobs/' . $jobId, 'PATCH', ['status' => 0]);
    file_put_contents($driverFile, $driver);
    $runWorker(0);
    $execution = ui('/jobs/' . $jobId . '/execution');
    verify(count($execution['attempts']) === 2 && $execution['attempts'][1]['result'] === ['duration' => 0], 'The reference worker did not run the pinned driver.');
    verify($execution['selectedAttemptId'] === $execution['attempts'][1]['id'] && $execution['attempts'][1]['archiveUrl'] !== null, 'The reference worker did not retain selected result evidence.');
}

$db = new Store($pdo);
$root = '/tmp/chronos-execution-' . bin2hex(random_bytes(8));
mkdir($root . '/webroot/data/log', 0700, true);
mkdir($root . '/webroot/data/evaluation', 0700, true);
mkdir($root . '/logs', 0700, true);
$server = null;
try {
    clearExecutionFixture($pdo);
    $pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/resources/chronos.sql'));
    $pdo->exec('SET SESSION autocommit=1');
    $time = date('Y-m-d H:i:s');
    foreach ([1, 2] as $id) $db->insert('User', ['userId' => $id, 'username' => 'fixture-' . $id, 'password' => 'fixture',
        'email' => 'fixture-' . $id . '@example.test', 'lastname' => 'Synthetic', 'firstname' => 'User',
        'gender' => 0, 'role' => 0, 'alive' => 1, 'activated' => 1, 'created' => $time]);
    foreach ([1, 2] as $id) {
        $db->insert('System', ['systemId' => $id, 'name' => 'Synthetic system ' . $id, 'description' => '',
            'userId' => 1, 'vcsUrl' => '', 'vcsBranch' => 'main', 'vcsType' => 'git', 'vcsUser' => '', 'vcsPassword' => '',
            'created' => $time, 'lastEdit' => $time, 'isArchived' => 0, 'cem' => 1]);
        $directory = $root . '/webroot/systems/' . $id;
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/config.json', json_encode(['identifier' => 'fixture-system-' . $id], JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/parameters.json', '[]');
        $db->insert('Setting', ['systemId' => $id, 'section' => 'environments', 'item' => 'fixture', 'value' => '']);
        $db->insert('Setting', ['systemId' => $id, 'section' => 'general', 'item' => 'fixedSetting', 'value' => 'original']);
        $db->insert('Setting', ['systemId' => $id, 'section' => 'setup', 'item' => 'bootstrapVersion', 'value' => 'setup-1']);
    }
    foreach ([['cem', 'environments', '["cluster"]'], ['cem', 'minVersion', '1'], ['other', 'uploadedDataHostname', 'http://fixture'],
        ['ftp', 'useFtpUploadForLocalClients', '1'], ['ftp', 'localNetworkCIDR', '127.0.0.0/8'], ['ftp', 'ftpServer', 'ftp.example.test'],
        ['ftp', 'ftpPort', '21'], ['ftp', 'ftpUsername', 'fixture'], ['ftp', 'ftpPassword', 'fixture']] as [$section, $item, $value]) {
        $db->insert('Setting', ['systemId' => 0, 'section' => $section, 'item' => $item, 'value' => $value]);
    }
    foreach ([1, 2] as $id) {
        $db->insert('Project', ['projectId' => $id, 'systemId' => 1, 'userId' => $id, 'name' => 'Project ' . $id,
            'description' => '', 'environment' => '', 'isArchived' => 0, 'isFinished' => 0]);
        $db->insert('Experiment', ['experimentId' => $id, 'systemId' => 1, 'userId' => $id, 'projectId' => $id, 'name' => 'Legacy',
            'description' => '', 'postData' => '{}', 'created' => $time, 'phases' => 2, 'status' => 0, 'internalId' => 1,
            'isArchived' => 0, 'resultId' => '', 'defaultEnvironment' => 'system-fixture']);
        $db->insert('Evaluation', ['evaluationId' => $id, 'systemId' => 1, 'experimentId' => $id, 'name' => 'Legacy',
            'description' => '', 'internalId' => 1, 'isArchived' => 0, 'isStarred' => 0]);
    }
    $db->insert('Job', ['jobId' => 1, 'systemId' => 1, 'userId' => 1, 'evaluationId' => 1, 'internalId' => 1, 'description' => 'Existing completed job',
        'environment' => 'system-legacy', 'created' => $time, 'status' => 3, 'progress' => 0, 'phases' => 2,
        'result' => '{"duration":0,"validated":false}', 'configuration' => '{"parameters":{"workers":2}}', 'configurationIdentifier' => 'legacy']);
    $previous = $db->row('SELECT * FROM Job WHERE jobId=1');
    $runner = new MigrationRunner($pdo);
    $runner->migrateTo();
    verify($db->row('SELECT * FROM Job WHERE jobId=1') === $previous, 'Migration changed a historical measurement.');
    verify($db->rows('SELECT * FROM JobAttempt') === [] && $db->rows('SELECT * FROM JobSpecification') === [], 'Migration invented legacy execution evidence.');

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $host = stream_socket_get_name($socket, false);
    fclose($socket);
    $address = 'http://' . $host;
    $server = proc_open([PHP_BINARY, '-S', $host, __DIR__ . '/../support/execution-router.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']],
        $pipes, $root, ['CHRONOS_TEST_MIGRATIONS' => '1', 'CHRONOS_EXECUTION_FIXTURE' => $root, 'PHP_CLI_SERVER_WORKERS' => '4']);
    if (!is_resource($server)) throw new RuntimeException('Unable to start the private HTTP fixture.');
    $ready = false;
    for ($retry = 0; $retry < 100; $retry++) {
        $port = @stream_socket_client('tcp://' . $host);
        if ($port !== false) {
            fclose($port);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    verify($ready, 'Private HTTP fixture failed to start.');

    $legacy = ui('/jobs/1/execution');
    verify($legacy['specification'] === null && $legacy['attempts'] === [] && $legacy['resultSelection'] === 'legacy-job-result', 'Legacy evidence is explicitly unavailable.');
    $oldAgent = agent('job/id=1');
    verify($oldAgent['system'] === 'fixture-system-1' && $oldAgent['status'] === 3 && !isset($oldAgent['attemptId']), 'Legacy agent fields and status changed.');
    verify(agent('job/id=1/action=getUploadTarget', 'POST')['filename'] === '1.zip', 'Legacy FTP target changed.');
    agent('job/id=1/action=appendLog', 'POST', ['log' => "legacy log\n"]);
    verify(ui('/jobs/1/log')['text'] === "legacy log\n", 'Legacy log changed.');

    $schema = ['parameters' => ['workers' => ['type' => 'integer'], 'check' => ['type' => 'boolean', 'default' => false]],
        'measurement' => 'Measure the synthetic driver operation.', 'lifecycle' => ['warmUp' => 'disabled'],
        'results' => ['duration' => ['type' => 'number', 'unit' => 'ms']]];
    $benchmark = ui('/benchmarks', 'POST', ['key' => 'fixture', 'version' => '1', 'name' => 'Synthetic benchmark', 'contract' => $schema], 201);
    $alternative = ui('/benchmarks', 'POST', ['key' => 'alternate', 'version' => '1', 'name' => 'Other benchmark', 'contract' => $schema], 201);
    $driver = ['sha256' => hash('sha256', 'synthetic-driver'), 'sizeBytes' => 16];
    $definition = ['systemVersion' => '1.0', 'adapter' => 'chronos-command@1',
        'parameterMap' => ['workers' => 'threads', 'check' => 'verify'], 'constants' => ['flags' => 'test'],
        'artifacts' => ['schemaVersion' => 1, 'artifacts' => [['role' => 'driver', 'uri' => 'fixture/driver'] + $driver]]];
    $bindingBody = ['systemId' => 1, 'version' => '1', 'definition' => $definition];
    ui('/benchmarks/' . $benchmark['id'] . '/bindings', 'POST', array_replace($bindingBody, ['definition' => array_replace($definition, ['parameterMap' => ['workers' => 'threads', 'check' => 'threads']])]), 422);
    ui('/benchmarks/' . $benchmark['id'] . '/bindings', 'POST', array_replace($bindingBody, ['definition' => array_replace($definition, ['adapter' => 'unsupported@1'])]), 422);
    $bindingA = ui('/benchmarks/' . $benchmark['id'] . '/bindings', 'POST', $bindingBody, 201);
    $bindingB = ui('/benchmarks/' . $benchmark['id'] . '/bindings', 'POST', array_replace($bindingBody, ['systemId' => 2]), 201);
    $bindingC = ui('/benchmarks/' . $alternative['id'] . '/bindings', 'POST', $bindingBody, 201);
    verify(count(ui('/benchmarks')['bindings']) === 3, 'A benchmark did not bind independently to both systems.');
    ui('/projects/1/experiments', 'POST', ['name' => 'Invalid binding', 'bindingId' => 999, 'planning' => 'explicit'], 422);
    $expA = ui('/projects/1/experiments', 'POST', ['name' => 'Explicit system 1', 'bindingId' => $bindingA['id'], 'planning' => 'explicit'], 201);
    $expB = ui('/projects/1/experiments', 'POST', ['name' => 'Explicit system 2', 'bindingId' => $bindingB['id'], 'planning' => 'explicit'], 201);
    $expC = ui('/projects/1/experiments', 'POST', ['name' => 'Other benchmark', 'bindingId' => $bindingC['id'], 'planning' => 'explicit'], 201);
    verify($expA['systemId'] === 1 && $expB['systemId'] === 2 && $expC['bindingId'] === $bindingC['id'], 'The project did not support multiple systems and benchmarks.');
    $jobs = [['parameters' => ['workers' => 2]], ['parameters' => ['workers' => 2]]];
    $evalPath = '/experiments/' . $expA['id'] . '/evaluations';
    $count = count($db->rows('SELECT evaluationId FROM Evaluation'));
    ui($evalPath, 'POST', ['environment' => 'system-fixture', 'jobs' => [$jobs[0], ['parameters' => ['workers' => '2']]]], 422);
    verify(count($db->rows('SELECT evaluationId FROM Evaluation')) === $count && count($db->rows('SELECT jobId FROM Job')) === 1, 'Validation wrote a partial evaluation.');
    $evaluation = ui($evalPath, 'POST', ['environment' => 'system-fixture', 'jobs' => $jobs], 201);
    $jobIds = array_map('intval', array_column($db->rows('SELECT jobId FROM Job WHERE evaluationId=? ORDER BY internalId', [$evaluation['id']]), 'jobId'));
    verify(count($jobIds) === 2 && $jobIds[0] !== $jobIds[1], 'Identical plan entries must remain distinct repetitions.');
    [$repetition, $twin] = $jobIds;
    $evidencePath = '/jobs/' . $repetition . '/execution';
    $frozen = ui($evidencePath)['specification'];
    verify($frozen['sha256'] === ui('/jobs/' . $twin . '/execution')['specification']['sha256'], 'Equivalent inputs should have matching specification hashes.');
    verify($frozen['effectiveParameters'] === ['fixedSetting' => 'original', 'flags' => 'test', 'threads' => 2, 'verify' => false], 'Typed mapping or inherited configuration is incorrect.');
    verify($frozen['artifacts']['artifacts'][0]['sha256'] === $driver['sha256'], 'The manifest identity was omitted.');
    ui('/jobs/' . $repetition, 'PATCH', ['phases' => 0], 409);
    ui('/jobs/' . $repetition, 'PATCH', ['status' => 3], 409);
    ui('/jobs/' . $twin, 'PATCH', ['status' => -1]);
    ui($evidencePath, 'GET', null, 403, ['X-Fixture-Outsider: 1']);
    agent('job/id=next/environment=fixture/supports=demoall', code: 601);

    $pdo->exec("UPDATE Setting SET value='drifted' WHERE section='general'");
    file_put_contents($root . '/webroot/systems/1/config.json', '{"identifier":"drifted-identifier"}');
    verify(ui('/jobs/' . $repetition . '/cdl')['xml'] === $frozen['cdl'], 'Live configuration changed the frozen CDL.');
    $tokens = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
    $poll = '/api/v1/job/id=next/protocol=attempt-v1/adapter=chronos-command%401/environment=fixture/supports=fixture-system-1';
    $multi = curl_multi_init();
    $clients = [];
    foreach ($tokens as $index => $token) {
        $clients[] = client($poll . '/worker=node' . $index, headers: ['X-Chronos-Attempt-Token: ' . $token]);
        curl_multi_add_handle($multi, $clients[$index]);
    }
    do {
        curl_multi_exec($multi, $active);
        if ($active) curl_multi_select($multi, 1);
    } while ($active);
    $winner = null;
    foreach ($clients as $index => $client) {
        $raw = curl_multi_getcontent($client);
        $received = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $code = $received['status']['code'];
        if ($code === 200) {
            verify($winner === null, 'Two concurrent requests acquired one repetition.');
            $winner = $index;
            $attempt = $received['response'];
        } else verify($code === 601, 'Contending poll failed unexpectedly: ' . $raw);
    }
    verify($winner !== null && $attempt['repetitionId'] === $repetition && $attempt['specification'] === $frozen, 'Claim did not retain the frozen inputs.');
    $attemptId = $attempt['attemptId'];
    $token = $tokens[$winner];
    $replay = agent(substr($poll, 8) . '/worker=node' . $winner, token: $token);
    verify($replay['attemptId'] === $attemptId, 'Replaying a claim lost ownership or added an attempt.');
    $attemptPath = 'job/id=' . $repetition . '/protocol=attempt-v1';
    agent($attemptPath, 'PATCH', ['status' => 2, 'currentPhase' => 3], $token);
    agent($attemptPath, 'PATCH', ['status' => 2], $token, 600);
    agent($attemptPath, 'PATCH', ['progress' => 50], $token);
    agent($attemptPath, 'PATCH', ['progress' => 49], $token, 600);
    agent($attemptPath, 'PATCH', ['status' => 3, 'result' => '{"duration":1}'], $token, 600);
    agent($attemptPath . '/action=appendLog', 'POST', ['log' => "FATAL: attempt one\n"], $token);
    agent($attemptPath, 'PATCH', ['status' => -2, 'result' => '{"duration":99}', 'reason' => 'synthetic failure'], $token);
    verify($db->row('SELECT result FROM Job WHERE jobId=?', [$repetition])['result'] === '', 'A failed attempt leaked into regular results.');
    ui('/evaluations/' . $evaluation['id'] . '/actions', 'POST', ['action' => 'reschedule']);
    ui('/jobs/' . $twin, 'PATCH', ['status' => -1]);
    $retryToken = bin2hex(random_bytes(32));
    // Hold the candidate so that both HTTP processes have the same poll in flight.
    $pdo->beginTransaction();
    $db->row('SELECT jobId FROM Job WHERE jobId=? FOR UPDATE', [$repetition]);
    $multi = curl_multi_init();
    $clients = [];
    foreach ([0, 1] as $index) {
        $clients[$index] = client($poll . '/worker=retry', headers: ['X-Chronos-Attempt-Token: ' . $retryToken]);
        curl_multi_add_handle($multi, $clients[$index]);
    }
    try {
        $deadline = microtime(true) + 0.25;
        do {
            curl_multi_exec($multi, $active);
            if ($active) curl_multi_select($multi, 0.02);
        } while ($active && microtime(true) < $deadline);
        verify($active === 2, 'Expected two overlapping retry requests.');
    } finally {
        $pdo->rollBack();
    }
    do {
        curl_multi_exec($multi, $active);
        if ($active) curl_multi_select($multi, 1);
    } while ($active);
    $retry = agentEnvelope(curl_multi_getcontent($clients[0]), 200);
    $concurrentReplay = agentEnvelope(curl_multi_getcontent($clients[1]), 200);
    verify($concurrentReplay['attemptId'] === $retry['attemptId'] && $concurrentReplay['id'] === $retry['id']
        && count($db->rows('SELECT attemptId FROM JobAttempt WHERE tokenHash=?', [hash('sha256', $retryToken)])) === 1,
        'Concurrent same-token claims must return the same single persisted attempt.');
    agent($attemptPath, 'PATCH', ['environment' => '{"limits":{},"labels":[]}'], $retryToken);
    $wireEvidence = json_decode(response(client('/api/ui' . $evidencePath)), false, 64, JSON_THROW_ON_ERROR);
    $observed = $wireEvidence->data->attempts[1]->observedEnvironment;
    verify($observed->limits instanceof stdClass && $observed->labels === [], 'Observed environment changed JSON object or list types.');
    verify($retry['id'] === $repetition && $retry['attemptId'] !== $attemptId, 'Retry did not retain the repetition and create its own attempt.');
    agent($attemptPath, 'PATCH', ['status' => 2], $token, 600);
    agent($attemptPath . '/action=appendLog', 'POST', ['log' => 'stale'], $token, 600);
    agent($attemptPath . '/action=getUploadTarget', 'POST', null, $token, 600);
    $target = agent($attemptPath . '/action=getUploadTarget', 'POST', null, $retryToken);
    verify($target['method'] === 'http' && str_contains($target['path'], 'protocol=attempt-v1'), 'Versioned jobs must use attempt-scoped HTTP uploads.');
    $archivePath = $root . '/fixture.zip';
    $zip = new ZipArchive();
    $zip->open($archivePath, ZipArchive::CREATE);
    $zip->addFromString('raw.txt', 'synthetic evidence');
    $zip->close();
    $archive = ['result' => new CURLFile($archivePath, 'application/zip', 'fixture.zip')];
    agent($attemptPath . '/action=upload', 'POST', $archive, $token, 600);
    agent($attemptPath . '/action=upload', 'POST', $archive, $retryToken);
    agent($attemptPath . '/action=upload', 'POST', $archive, $retryToken);
    agent($attemptPath, 'PATCH', ['status' => 2, 'currentPhase' => 3, 'environment' => '{"os":"Synthetic OS"}',
        'artifacts' => json_encode(['driver' => $driver], JSON_THROW_ON_ERROR)], $retryToken);
    agent($attemptPath . '/action=appendLog', 'POST', ['log' => "attempt two\n"], $retryToken);
    agent($attemptPath, 'PATCH', ['status' => 3, 'result' => '{"duration":"1"}'], $retryToken, 600);
    agent($attemptPath, 'PATCH', ['status' => 3, 'result' => '{"duration":1}'], $retryToken);
    agent($attemptPath, 'PATCH', ['status' => 3, 'result' => '{"duration":111}'], $token, 600);
    agent($attemptPath . '/action=upload', 'POST', $archive, $retryToken, 600);
    ui('/jobs/' . $repetition . '/log', 'DELETE', status: 409);
    ui('/jobs/' . $repetition, 'PATCH', ['status' => 0], 409);
    $history = ui($evidencePath);
    verify(array_column($history['attempts'], 'status') === ['failed','finished'], 'Retry evidence did not retain both attempts.');
    verify(array_column($history['attempts'], 'result') === [['duration' => 99], ['duration' => 1]], 'Attempt results were overwritten.');
    verify($history['selectedAttemptId'] === $retry['attemptId'] && array_column($history['attempts'], 'selected') === [false, true], 'The result selection did not choose only the successful attempt.');
    verify($history['attempts'][0]['observedEnvironment'] === null && $history['attempts'][1]['observedEnvironment'] === ['os' => 'Synthetic OS'], 'Unknown observed evidence was invented or omitted.');
    verify(response(client($history['attempts'][0]['logUrl'])) === "FATAL: attempt one\n" && ui('/jobs/' . $repetition . '/log')['text'] === "attempt two\n", 'Attempt logs were not isolated.');
    $scanPath = '/jobs/' . $repetition . '/log/analysis?attemptId=' . $history['attempts'][0]['id'];
    verify(ui($scanPath)['size'] === strlen("FATAL: attempt one\n"), 'Historical log scan switched to the latest attempt.');
    ui('/jobs/' . $twin . '/log/analysis?attemptId=' . $history['attempts'][0]['id'], status: 404);
    $wire = json_decode(response(client('/api/ui' . $evidencePath)), false, 64, JSON_THROW_ON_ERROR);
    verify($wire->data->specification->setup instanceof stdClass, 'The wire specification changed an empty object into an array.');
    verify(hash('sha256', response(client($history['attempts'][1]['archiveUrl']))) === hash_file('sha256', $archivePath), 'The retained archive differs from the upload.');

    $context = new Context($db, ['id' => 1, 'role' => 0], $root);
    $result = (new ResultPresentation($context, $root))->job($repetition, ['view' => 'raw']);
    verify(count($result['jobs']) === 1 && (array) $result['jobs'][0]['result'] === ['duration' => 1], 'Raw results included an unselected attempt.');
    ob_start();
    $csv = (new ResultDownloads($context))->jobCsv($repetition);
    ($csv->stream)();
    $csvText = ob_get_clean();
    verify(str_contains($csvText, 'selectedAttemptId') && !str_contains($csvText, ';99'), 'CSV selected the failed result or omitted provenance.');
    ob_start();
    $zipDownload = (new ResultDownloads($context))->job($repetition);
    ($zipDownload->stream)();
    verify(ob_get_clean() === file_get_contents($archivePath), 'Regular ZIP did not use the selected attempt.');

    $cemRequest = ['environment' => 'cem-cluster', 'jobs' => $jobs];
    $db->insert('Setting', ['systemId' => 2, 'section' => 'setup', 'item' => 'artifactManifestV1', 'value' => 'broken']);
    $cemPath = '/experiments/' . $expB['id'] . '/evaluations';
    ui($cemPath, 'POST', $cemRequest, 422);
    $setManifest = $pdo->prepare("UPDATE Setting SET value=? WHERE systemId=2 AND section='setup' AND item='artifactManifestV1'");
    $manifest = $definition['artifacts'];
    $manifest['artifacts'][0]['sha256'] = str_repeat('0', 64);
    $setManifest->execute([json_encode($manifest, JSON_THROW_ON_ERROR)]);
    ui($cemPath, 'POST', $cemRequest, 422);
    $setManifest->execute([json_encode($definition['artifacts'], JSON_THROW_ON_ERROR)]);
    $invalidDefinition = $definition;
    $invalidDefinition['artifacts']['artifacts'][] = ['role' => '_data'] + $definition['artifacts']['artifacts'][0];
    $cacheBinding = ui('/benchmarks/' . $benchmark['id'] . '/bindings', 'POST',
        ['systemId' => 2, 'version' => 'cache-only', 'definition' => $invalidDefinition], 201);
    $cacheExperiment = ui('/projects/1/experiments', 'POST', ['name' => 'Cache-only artifacts', 'bindingId' => $cacheBinding['id'], 'planning' => 'explicit'], 201);
    ui('/experiments/' . $cacheExperiment['id'] . '/evaluations', 'POST', $cemRequest, 422);
    // Simulate a frozen CEM specification that was admitted by an older server.
    $obsolete = ui($cemPath, 'POST', ['environment' => 'cem-cluster', 'jobs' => [$jobs[0]]], 201);
    $obsoleteId = (int) $db->row('SELECT jobId FROM Job WHERE evaluationId=?', [$obsolete['id']])['jobId'];
    $oldSpec = json_decode($db->row('SELECT specification FROM JobSpecification WHERE jobId=?', [$obsoleteId])['specification'], false, 64, JSON_THROW_ON_ERROR);
    $oldSpec->artifacts = $invalidDefinition['artifacts'];
    $db->update('JobSpecification', 'jobId', $obsoleteId, ['specification' => json_encode($oldSpec, JSON_THROW_ON_ERROR)]);
    $cem = 'cem/uniqueId=fixture-cem/version=2/environment=cluster/protocol=attempt-v1';
    agent('cem/uniqueId=empty-poll/version=2/environment=unknown/protocol=attempt-v1/adapter=chronos-command%401',
        token: bin2hex(random_bytes(32)), code: 601);
    verify($db->row("SELECT nodeId FROM Node WHERE nodeId='empty-poll'") === null, 'An empty poll registered a node in an unknown environment.');
    agent($cem . '/adapter=chronos-command%401/artifactManifest=1', token: bin2hex(random_bytes(32)), code: 601);
    verify((int) $db->row('SELECT status FROM Job WHERE jobId=?', [$obsoleteId])['status'] === -2,
        'An undeliverable CEM job must stay failed even when the queue is otherwise empty.');
    ui('/jobs/' . $obsoleteId, 'PATCH', ['status' => 0]);
    $cemEval = ui($cemPath, 'POST', $cemRequest, 201);
    $cemJob = (int) $db->row('SELECT jobId FROM Job WHERE evaluationId=? ORDER BY internalId LIMIT 1', [$cemEval['id']])['jobId'];
    $pdo->exec("UPDATE Setting SET value='setup-2' WHERE section='setup'");
    $pdo->exec('UPDATE `System` SET cem=0 WHERE systemId=2');
    $cemToken = bin2hex(random_bytes(32));
    agent('cem/uniqueId=legacy-cem/version=2/environment=cluster/jobId=' . $cemJob, code: 600);
    $pdo->exec("UPDATE Setting SET value='[]' WHERE systemId=0 AND section='cem' AND item='environments'");
    $claim = agent($cem . '/adapter=chronos-command%401/artifactManifest=1', token: $cemToken);
    verify((int) $db->row('SELECT status FROM Job WHERE jobId=?', [$obsoleteId])['status'] === -2,
        'A historical invalid manifest blocked the next deliverable CEM job.');
    $bootstrap = agent($cem . '/jobId=' . $cemJob . '/artifactManifest=1', token: $cemToken);
    verify($bootstrap['artifactManifest'] === $claim['specification']['artifacts'], 'The bootstrap manifest and frozen binding differ.');
    verify($claim['job']['id'] === $cemJob && $claim['setup'] === ['bootstrapVersion' => 'setup-1'], 'CEM setup drifted or required the current system CEM flag.');
    agent($cem . '/adapter=chronos-command%401', token: bin2hex(random_bytes(32)), code: 600);
    agent($cem . '/action=jobStarted', 'POST', ['jobId' => $cemJob], $cemToken);
    agent($cem . '/action=jobStarted', 'POST', ['jobId' => $cemJob], $cemToken, 600);
    agent($cem . '/action=jobLog', 'POST', ['jobId' => $cemJob, 'message' => 'setup complete'], $cemToken);
    agent($cem . '/action=jobTerminated', 'POST', ['jobId' => $cemJob, 'reason' => 'synthetic CEM failure'], $cemToken);
    verify(ui('/jobs/' . $cemJob . '/execution')['attempts'][0]['status'] === 'failed', 'CEM termination did not record failure.');
    $interrupted = agent($cem . '/adapter=chronos-command%401', token: bin2hex(random_bytes(32)));
    ui('/jobs/' . $interrupted['job']['id'], 'PATCH', ['status' => -1]);
    $pdo->exec("UPDATE Setting SET value='[\"cluster\"]' WHERE systemId=0 AND section='cem' AND item='environments'");
    $legacyCemExperiment = ui('/projects/1/experiments', 'POST', ['name' => 'Legacy CEM after abort'], 201);
    $legacyCemEvaluation = ui('/experiments/' . $legacyCemExperiment['id'] . '/evaluations', 'POST', ['environment' => 'cem-cluster'], 201);
    $legacyCemJob = (int) $db->row('SELECT jobId FROM Job WHERE evaluationId=?', [$legacyCemEvaluation['id']])['jobId'];
    $legacyCem = 'cem/uniqueId=fixture-cem/version=2/environment=cluster';
    verify(agent($legacyCem)['job']['id'] === $legacyCemJob, 'The recovered CEM node did not receive its next legacy job.');
    agent($legacyCem . '/action=jobStarted', 'POST', ['jobId' => $legacyCemJob]);
    verify((int) $db->row('SELECT currentJob FROM Node WHERE nodeId=?', ['fixture-cem'])['currentJob'] === $legacyCemJob,
        'A terminal managed assignment prevented the next legacy start.');
    agent($legacyCem . '/action=jobTerminated', 'POST', ['jobId' => $legacyCemJob]);
    $db->execute("UPDATE Node SET currentJob=? WHERE nodeId='fixture-cem'", [$interrupted['job']['id']]);
    agent($legacyCem . '/action=nodeStatus', 'POST', ['jobId' => '', 'cpu' => '0', 'memoryUsed' => '0',
        'memoryTotal' => '1024', 'hostname' => 'fixture', 'os' => 'fixture', 'healthStatus' => '']);
    verify($db->row("SELECT currentJob FROM Node WHERE nodeId='fixture-cem'")['currentJob'] === null,
        'The first idle heartbeat after recovery must release a terminal managed assignment.');
    ui('/projects/1/comparisons', 'POST', ['name' => 'Foreign evaluation', 'evaluationIds' => [$evaluation['id'], 2]], 422);
    $comparison = ui('/projects/1/comparisons', 'POST', ['name' => 'Compare systems', 'evaluationIds' => [$evaluation['id'], $cemEval['id']]], 201);
    verify(array_column($comparison['evaluations'], 'systemName') === ['Synthetic system 1', 'Synthetic system 2'], 'Comparison did not retain both systems.');

    file_put_contents($root . '/webroot/systems/1/parameters.json', json_encode([[
        'id' => 'main', 'title' => 'Synthetic parameters', 'depends' => '', 'dependsValue' => '',
        'elements' => [['id' => 'workers', 'type' => 'integer', 'name' => 'Workers', 'parameter' => 'workers', 'default' => 4]],
    ]], JSON_THROW_ON_ERROR));
    $boundGenerated = ui('/projects/1/experiments', 'POST', ['name' => 'Bound parameter generator', 'runs' => 2, 'bindingId' => $bindingA['id']], 201);
    $boundEval = ui('/experiments/' . $boundGenerated['id'] . '/evaluations', 'POST', ['environment' => 'system-fixture'], 201);
    $boundJobs = $db->rows('SELECT jobId FROM Job WHERE evaluationId=? ORDER BY internalId', [$boundEval['id']]);
    verify(count($boundJobs) === 2 && ui('/jobs/' . $boundJobs[0]['jobId'] . '/execution')['specification']['effectiveParameters']['threads'] === 4, 'The bound parameter generator did not use the same planning and mapping path.');

    $legacyExperiment = ui('/projects/1/experiments', 'POST', ['name' => 'Parameter generator', 'runs' => 2], 201);
    $generated = ui('/experiments/' . $legacyExperiment['id'] . '/evaluations', 'POST', ['environment' => 'system-fixture'], 201);
    $generatedIds = $db->rows('SELECT jobId FROM Job WHERE evaluationId=? ORDER BY internalId', [$generated['id']]);
    verify(count($generatedIds) === 2, 'The parameter generator was not preserved.');
    $generatedJob = (int) $generatedIds[0]['jobId'];
    $generatedSpec = ui('/jobs/' . $generatedJob . '/execution')['specification'];
    verify($generatedSpec['protocol'] === 'legacy' && $generatedSpec['system']['version'] === null, 'Generated jobs misrepresent legacy provenance.');
    $pdo->exec("UPDATE Setting SET value='drifted-again' WHERE section='general'");
    $oldPoll = agent('job/id=next/environment=fixture/supports=demoall');
    verify($oldPoll['status'] === 0 && $oldPoll['id'] === $generatedJob && $oldPoll['cdl'] === $generatedSpec['cdl'], 'The old agent cannot execute a generated frozen job.');
    file_put_contents($root . '/webroot/systems/1/config.json', '{"identifier":"renamed-legacy"}');
    verify(agent('job/id=next/environment=fixture/supports=drifted-identifier')['id'] === $generatedJob, 'Legacy poll lost the frozen system identifier.');
    agent('job/id=next/environment=fixture/supports=renamed-legacy', code: 601);
    $db->update('Job', 'jobId', 1, ['status' => 0, 'environment' => 'system-fixture']);
    verify(agent('job/id=next/environment=fixture/supports=renamed-legacy')['id'] === 1, 'Historical jobs must still use the current system identifier.');
    $db->update('Job', 'jobId', 1, ['status' => $previous['status'], 'environment' => $previous['environment']]);

    $python = getenv('CHRONOS_TEST_PYTHON');
    if (is_string($python) && $python !== '') testReferenceWorker($python, $db, $root, $benchmark, $definition);

    // Cross the bulk action's page boundary without loading or rewriting retained results.
    $bulk = ui($evalPath, 'POST', ['environment' => 'system-fixture', 'jobs' => array_fill(0, 129, $jobs[0])], 201);
    $db->execute("UPDATE Job SET status=-2,result=REPEAT('x',65536) WHERE evaluationId=?", [$bulk['id']]);
    foreach (['reschedule' => 0, 'abort' => -1] as $action => $expected) {
        $response = ui('/evaluations/' . $bulk['id'] . '/actions', 'POST', ['action' => $action]);
        verify($response['changedJobs'] === 129, 'Bulk action skipped or repeated jobs across pages.');
        $matching = $db->row('SELECT COUNT(*) AS total FROM Job WHERE evaluationId=? AND status=? AND LENGTH(result)=65536', [$bulk['id'], $expected]);
        verify((int) $matching['total'] === 129, 'Bulk action lost retained result data or left a job behind.');
    }

    testCatalogAndMemberCopy($db, $root, $definition);

    try {
        $runner->migrateTo(SystemMaintainersMigration::VERSION);
        throw new RuntimeException('Downgrade erased execution evidence.');
    } catch (MigrationException $error) {
        verify(str_contains($error->getMessage(), 'execution-model data'), 'Downgrade was rejected for the wrong reason.');
        verify(count($db->rows('SELECT * FROM JobAttempt WHERE jobId=?', [$repetition])) === 2, 'Rejected downgrade lost attempts.');
        $runner->migrateTo();
    }
    echo $assertions . ' execution-model and HTTP assertions passed on PHP ' . PHP_VERSION . ".\n";
} catch (Throwable $error) {
    if (is_file($root . '/server.log')) fwrite(STDERR, substr(file_get_contents($root . '/server.log'), -6000));
    throw $error;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    clearExecutionFixture($pdo);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($root);
}
