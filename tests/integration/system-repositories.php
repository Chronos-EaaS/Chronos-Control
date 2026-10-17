<?php
declare(strict_types=1);

/**
 * Standalone module integration: synthetic SQLite + local Git/Hg repositories.
 * Run in the disposable PHP 8.3 repository-test image with --network none.
 * Never includes config.php, discovers production paths, or contacts a remote host.
 */
use Chronos\Api\V3\Context;
use Chronos\Api\V3\HttpError;
use Chronos\Api\V3\Request;
use Chronos\Api\V3\Sessions;
use Chronos\Api\V3\Store;
use Chronos\Api\V3\SystemRoutes;

$source = dirname(__DIR__, 2);
foreach (['HttpError', 'Request', 'Input', 'Policy', 'Dto', 'Store', 'Context', 'Sessions', 'Repository', 'Extensions', 'SystemRoutes', 'Kernel'] as $name) require_once $source . '/api/v3/' . $name . '.php';
date_default_timezone_set('Europe/Zurich');
define('ONLY_ACTIVATED_USERS', true);
define('ENABLE_REMOTE_REPOSITORY', !in_array('--remote-disabled', $argv, true));
$checks = 0;
function verify(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }
function command(array $arguments, string $directory): string
{
    $environment = getenv(); $environment = is_array($environment) ? $environment : [];
    $environment['HGRCPATH'] = '/dev/null'; $environment['HGRCSKIPREPO'] = '1'; $environment['HGPLAIN'] = '+strictflags'; $environment['HGENCODING'] = 'utf-8'; $environment['GIT_TERMINAL_PROMPT'] = '0';
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $directory, $environment, ['bypass_shell' => true]);
    if (!$process) throw new RuntimeException('Fixture command unavailable.');
    fclose($pipes[0]); $output = (string) stream_get_contents($pipes[1]); fclose($pipes[1]); $code = proc_close($process);
    if ($code !== 0) throw new RuntimeException('Fixture command failed: ' . implode(' ', $arguments) . "\n" . $output);
    return trim($output);
}
function expectError(callable $action, int $status, string $code): HttpError
{
    try { $action(); throw new RuntimeException('Expected ' . $code); }
    catch (HttpError $error) { verify($error->status === $status && $error->errorCode === $code, 'Expected ' . $code . ', received ' . $error->getMessage()); return $error; }
}
function removeFixture(string $path): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
    }
    rmdir($path);
}
function remotePermissions(string $path, bool $writable): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) chmod($entry->getPathname(), $entry->isDir() ? ($writable ? 0755 : 0555) : ($writable ? 0644 : 0444));
    chmod($path, $writable ? 0755 : 0555);
}
/** Loopback-only HTTP transports work inside --network none; no external service is contacted. */
function startServer(array $arguments, string $directory): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    if (!$socket) throw new RuntimeException('Fixture loopback port unavailable.');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $port = (int) substr($address, strrpos($address, ':') + 1);
    $arguments = array_map(static fn(string $argument): string => str_replace('{port}', (string) $port, $argument), $arguments);
    $environment = getenv(); $environment = is_array($environment) ? $environment : [];
    $environment['HGRCPATH']='/dev/null'; $environment['HGRCSKIPREPO']='1'; $environment['HGPLAIN']='+strictflags';
    $process = proc_open($arguments, [0=>['file','/dev/null','r'],1=>['file',$directory.'/fixture-http.log','a'],2=>['redirect',1]], $pipes, $directory, $environment, ['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Fixture HTTP transport unavailable.');
    for ($attempt=0;$attempt<100;$attempt++) {
        $ready = @stream_socket_client('tcp://127.0.0.1:'.$port,$error,$message,0.1);
        if ($ready) { fclose($ready); return [$process,$port]; }
        usleep(20000);
    }
    proc_terminate($process);proc_close($process);throw new RuntimeException('Fixture HTTP transport did not start.');
}
$servers = [];
$root = sys_get_temp_dir() . '/chronos-v3-repository-fixture-' . bin2hex(random_bytes(8));
mkdir($root . '/webroot/systems', 0700, true);
try {
    $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) : new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $concat = static fn(string $separator, mixed ...$parts): string => implode($separator, array_filter($parts, static fn(mixed $part): bool => $part !== null));
    if ($pdo instanceof \Pdo\Sqlite) $pdo->createFunction('CONCAT_WS', $concat); else $pdo->sqliteCreateFunction('CONCAT_WS', $concat);
    $pdo->exec('CREATE TABLE User (userId INTEGER PRIMARY KEY,firstname TEXT,lastname TEXT,alive INTEGER,activated INTEGER)');
    $pdo->exec("INSERT INTO User VALUES (1,'Synthetic','Owner',1,1),(2,'Synthetic','Member',1,1)");
    $pdo->exec('CREATE TABLE System (systemId INTEGER PRIMARY KEY,userId INTEGER,name TEXT,description TEXT,vcsUrl TEXT,vcsBranch TEXT,vcsType TEXT,vcsUser TEXT,vcsPassword TEXT,created TEXT,lastEdit TEXT,isArchived INTEGER,cem INTEGER)');
    $pdo->exec('CREATE TABLE Event (eventId INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,time TEXT,eventText TEXT,eventType TEXT,relatedId INTEGER,userId INTEGER,nodeId TEXT)');
    $pdo->exec('CREATE TABLE Project (projectId INTEGER PRIMARY KEY,userId INTEGER,systemId INTEGER,name TEXT)');
    $pdo->exec('CREATE TABLE ProjectUser (projectUserId INTEGER PRIMARY KEY,projectId INTEGER,userId INTEGER)');
    $pdo->exec('CREATE TABLE Experiment (experimentId INTEGER PRIMARY KEY,projectId INTEGER,systemId INTEGER,name TEXT)');
    $pdo->exec('CREATE TABLE Setting (settingId INTEGER PRIMARY KEY,systemId INTEGER,section TEXT,item TEXT,value TEXT)');
    $pdo->exec("INSERT INTO Setting VALUES (1,0,'other','rowsPerPage','20')");
    $store = new Store($pdo); $actor = ['id' => 1, 'role' => 0];
    $sessions = (new ReflectionClass(Sessions::class))->newInstanceWithoutConstructor();
    $call = static function (string $method, string $path, array $body = []) use ($store, $actor, $root, $sessions): array {
        $request = new Request($method, '/api/v3' . $path, ['content-type' => 'application/json'], json_encode((object) $body, JSON_THROW_ON_ERROR));
        return (new SystemRoutes($request, $store, $actor, $root, $sessions))->handle(substr($request->path, strlen('/api/v3'))) ?? throw new RuntimeException('Unmatched route.');
    };
    $insert = static function (int $id, string $type, string $url, string $branch) use ($store): void {
        $store->insert('System', ['systemId' => $id, 'userId' => 1, 'name' => 'Synthetic system', 'description' => '', 'vcsUrl' => $url, 'vcsBranch' => $branch, 'vcsType' => $type, 'vcsUser' => '', 'vcsPassword' => '', 'created' => '2026-01-15 12:00:00', 'lastEdit' => '2026-01-15 12:00:00', 'isArchived' => 0, 'cem' => 0]);
    };
    // A real parent checkout proves the missing-child-metadata guard prevents discovery.
    command(['git', 'init', '--quiet', '--initial-branch=main'], $root);
    file_put_contents($root . '/application-marker.txt', 'Application repository must remain unchanged.');
    command(['git', 'add', '--', 'application-marker.txt'], $root);
    command(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost', 'commit', '--quiet', '-m', 'Synthetic application'], $root);
    $parentRevision = command(['git', 'rev-parse', 'HEAD'], $root);

    foreach (['git', 'hg'] as $index => $type) {
        $id = $index + 1; $branch = $type === 'git' ? 'main' : 'default';
        $remote = $root . '/' . $type . '-remote'; $seed = $root . '/' . $type . '-seed'; $path = $root . '/webroot/systems/' . $id; mkdir($seed);
        if ($type === 'git') command(['git', 'init', '--quiet', '--initial-branch=main'], $seed); else command(['hg', 'init'], $seed);
        file_put_contents($seed . '/parameters.json', "[]\n"); file_put_contents($seed . '/payload.txt', 'Initial synthetic data');
        command([$type, 'add', '--', 'parameters.json', 'payload.txt'], $seed);
        command($type === 'git' ? ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost', 'commit', '--quiet', '-m', 'Initial synthetic system'] : ['hg', 'commit', '--user', 'Fixture <fixture@localhost>', '--message', 'Initial synthetic system'], $seed);
        command($type === 'git' ? ['git', 'clone', '--bare', $seed, $remote] : ['hg', 'clone', '--noupdate', $seed, $remote], $root);
        command([$type, 'clone', $remote, $path], $root); $insert($id, $type, '', $branch);
        $metadata = $path . '/.' . $type;
        $sentinel = $root . '/' . $type . '-hook-executed';
        if ($type === 'git') {
            file_put_contents($metadata . '/hooks/pre-commit', "#!/bin/sh\ntouch '" . $sentinel . "'\nexit 1\n"); chmod($metadata . '/hooks/pre-commit', 0700);
        } else {
            file_put_contents($metadata . '/hgrc', "[hooks]\nprecommit = touch '" . $sentinel . "' && false\n[alias]\nlog = !touch '" . $sentinel . "'\n");
        }
        $before = hash_file('sha256', $path . '/parameters.json'); $history = $call('GET', '/systems/' . $id . '/repository')['body']['data'];
        verify(count($history['history']) === 1, $type . ': real history is available.'); verify(in_array($branch, $history['branches'], true), $type . ': branch is exposed.');
        verify(hash_file('sha256', $path . '/parameters.json') === $before, $type . ': history GET does not modify files.'); verify(!file_exists($sentinel), $type . ': hooks/aliases were not executed.');
        $schema = ['groups' => [['id' => 'fixture-group', 'title' => 'Names with spaces & café', 'depends' => '', 'dependsValue' => false, 'elements' => [['id' => 'fixture-boolean', 'type' => 'boolean', 'name' => 'Enabled', 'parameter' => 'enabled', 'default' => false], ['id' => 'fixture-integer', 'type' => 'integer', 'name' => 'Count', 'parameter' => 'count', 'default' => 0]]]]];
        file_put_contents($path . '/operator-draft.txt', 'Do not commit unrelated files.');
        $saved = $call('PUT', '/systems/' . $id . '/parameters', $schema)['body']['data'];
        verify($saved === $schema, $type . ': typed false/zero schema round-trips.'); verify(file_exists($path . '/operator-draft.txt'), $type . ': unrelated draft is preserved.');
        $committed = $type === 'git' ? command(['git', 'show', '--pretty=format:', '--name-only', 'HEAD'], $path) : command(['hg', 'log', '--rev', '.', '--template', '{files}'], $path);
        verify($committed === 'parameters.json', $type . ': only the edited configuration was committed.');
        unlink($path . '/operator-draft.txt');
        verify(!file_exists($sentinel), $type . ': configuration saves do not execute hooks.');
        // Reloading and saving exactly the same schema leaves history unchanged.
        $revision = $call('GET', '/systems/' . $id . '/repository')['body']['data']['revision']; $call('PUT', '/systems/' . $id . '/parameters', $schema);
        verify($call('GET', '/systems/' . $id . '/repository')['body']['data']['revision'] === $revision, $type . ': no-op save produces no commit.');
        // The logo uses the same isolated, locked history boundary.
        $instance = new SystemRoutes(new Request('PUT', '/api/v3/systems/' . $id . '/logo'), $store, $actor, $root, $sessions);
        (new ReflectionMethod(SystemRoutes::class, 'writeBinaryConfiguration'))->invoke($instance, $id, 'logo.png', 'Synthetic logo bytes');
        verify(file_get_contents($path . '/logo.png') === 'Synthetic logo bytes', $type . ': binary configuration is recorded.');
        verify(command($type === 'git' ? ['git', 'status', '--porcelain'] : ['hg', 'status'], $path) === '', $type . ': repository remains clean after saves.');
        $historyPage = $call('GET', '/systems/' . $id . '/repository?pageSize=1&page=2&sort=message')['body']['data'];
        verify($historyPage['total'] === 3 && $historyPage['page'] === 2 && count($historyPage['history']) === 1, $type . ': complete history supports server pagination.');
        $searchedHistory = $call('GET', '/systems/' . $id . '/repository?search=' . rawurlencode('Updated system logo') . '&sort=-date')['body']['data'];
        verify($searchedHistory['total'] === 1 && $searchedHistory['history'][0]['message'] === 'Updated system logo', $type . ': history search does not depend on the current page.');
        verify(is_array($searchedHistory['history'][0]['branches']) && is_array($searchedHistory['history'][0]['parents']), $type . ': history retains branch and parent relationships.');
        $bounded = new SystemRoutes(new Request('GET','/api/v3/systems/'.$id.'/repository'),$store,$actor,$root,$sessions);
        [$boundedCode,$boundedText]=(new ReflectionMethod(SystemRoutes::class,'vcs'))->invoke($bounded,$path,$type,['--version'],['GIT_TERMINAL_PROMPT'=>'0'],8);
        verify($boundedCode===124 && strlen($boundedText)===8,$type.': a fast-exiting command still enforces its complete output limit.');

        if (!ENABLE_REMOTE_REPOSITORY) {
            expectError(fn() => $call('POST', '/systems/' . $id . '/repository/update'), 403, 'forbidden');
            expectError(fn() => $call('POST', '/systems/' . $id . '/repository/push'), 403, 'forbidden');
            $store->update('System', 'systemId', $id, ['vcsUrl' => $remote . '-unavailable']);
            $schema['groups'][0]['elements'][1]['default'] = 9;
            verify($call('PUT', '/systems/' . $id . '/parameters', $schema)['status'] === 200, $type . ': disabled remote operations do not block local saves or attempt synchronization.');
            continue;
        }
        // Publish only inside the disposable local fixture to establish a common history.
        if ($type === 'git') {
            command(['git', 'push', 'origin', 'main'], $path);
            command(['git', 'fetch', $remote, 'main'], $seed); command(['git', 'merge', '--ff-only', 'FETCH_HEAD'], $seed);
            command(['git', 'checkout', '-b', 'feature-with-spaces-label-safe'], $seed);
        } else {
            command(['hg', 'push', $remote], $path); command(['hg', 'pull', $remote], $seed); command(['hg', 'update'], $seed); command(['hg', 'branch', 'feature branch with spaces'], $seed);
        }
        file_put_contents($seed . '/branch-marker.txt', 'Branch selection is independent of UI labels.'); command([$type, 'add', '--', 'branch-marker.txt'], $seed);
        command($type === 'git' ? ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost', 'commit', '--quiet', '-m', 'Remote branch update'] : ['hg', 'commit', '--user', 'Fixture <fixture@localhost>', '--message', 'Remote branch update'], $seed);
        $selected = $type === 'git' ? 'feature-with-spaces-label-safe' : 'feature branch with spaces';
        command($type === 'git' ? ['git', 'push', $remote, $selected] : ['hg', 'push', '--new-branch', $remote], $seed);
        $store->update('System', 'systemId', $id, ['vcsBranch' => $selected, 'vcsUrl' => $remote]);
        // Nonempty credentials exercise private askpass/auth-file creation and cleanup even on local transport.
        $store->update('System', 'systemId', $id, ['vcsUser' => 'fixture-user', 'vcsPassword' => 'fixture-secret']);
        $updated = $call('POST', '/systems/' . $id . '/repository/update')['body']['data'];
        verify(file_get_contents($path . '/branch-marker.txt') === 'Branch selection is independent of UI labels.', $type . ': explicit update fetched the selected branch.');
        verify(in_array($selected, $updated['branches'], true), $type . ': selected branch is listed.');
        verify(!str_contains(json_encode($updated), 'fixture-secret'), $type . ': response contains no credentials.'); verify(glob($metadata . '/chronos-auth-*') === [], $type . ': private authentication files are removed.');
        $schema['groups'][0]['elements'][1]['default'] = 14;
        $call('PUT', '/systems/' . $id . '/parameters', $schema);
        $remoteSchema = static fn(): array => json_decode(command($type === 'git' ? ['git', 'show', 'refs/heads/' . $selected . ':parameters.json'] : ['hg', 'cat', '--rev', $selected, 'parameters.json'], $remote), true, 32, JSON_THROW_ON_ERROR);
        verify($remoteSchema()[0]['elements'][1]['default'] === 14, $type . ': a configuration save automatically pulls and pushes.');
        $call('PUT', '/systems/' . $id . '/parameters', $schema);
        verify($call('POST', '/systems/' . $id . '/repository/push')['status'] === 200, $type . ': repeated synchronization with no new commits succeeds.');
        $schema['groups'][0]['elements'][1]['default'] = 15;
        remotePermissions($remote, false);
        try { expectError(fn() => $call('PUT', '/systems/' . $id . '/parameters', $schema), 409, 'repository_sync_failed'); }
        finally { remotePermissions($remote, true); }
        verify(json_decode((string) file_get_contents($path . '/parameters.json'), true)[0]['elements'][1]['default'] === 15, $type . ': a rejected remote push retains the locally saved configuration.');
        verify($remoteSchema()[0]['elements'][1]['default'] === 14, $type . ': a rejected push does not change the remote.');
        verify(command($type === 'git' ? ['git', 'status', '--porcelain'] : ['hg', 'status'], $path) === '', $type . ': push failure retains a clean committed local version.');
        $call('POST', '/systems/' . $id . '/repository/push');
        verify($remoteSchema()[0]['elements'][1]['default'] === 15, $type . ': manual Push changes retries and publishes the retained commit.');
        verify(glob($metadata . '/chronos-auth-*') === [], $type . ': push failure/retry removes private authentication files.');
        $advanceRemote = static function (string $filename, string $contents, bool $link = false) use ($type, $seed, $remote, $selected): void {
            if ($type === 'git') { command(['git', 'fetch', $remote, $selected], $seed); command(['git', 'merge', '--ff-only', 'FETCH_HEAD'], $seed); }
            else { command(['hg', 'pull', $remote], $seed); command(['hg', 'update', '--rev', $selected], $seed); }
            if (!is_dir(dirname($seed . '/' . $filename))) mkdir(dirname($seed . '/' . $filename), 0700, true);
            if ($link) symlink($contents, $seed . '/' . $filename); else file_put_contents($seed . '/' . $filename, $contents); command([$type, 'add', '--', $filename], $seed);
            command($type === 'git' ? ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost', 'commit', '--quiet', '-m', 'Concurrent remote configuration'] : ['hg', 'commit', '--user', 'Fixture <fixture@localhost>', '--message', 'Concurrent remote configuration'], $seed);
            command($type === 'git' ? ['git', 'push', $remote, $selected] : ['hg', 'push', $remote], $seed);
        };
        foreach (['.htaccess','.user.ini','incoming-link',($type==='git'?'.hg':'.git').'/foreign-metadata'] as $securityIndex => $filename) {
            $beforeUpdate=command($type==='git'?['git','rev-parse','HEAD']:['hg','log','--rev','.','--template','{node}'],$path);
            $advanceRemote($filename,$filename==='incoming-link'?'../../application-marker.txt':'Unsafe incoming override',$filename==='incoming-link');
            expectError(fn()=>$call('POST','/systems/'.$id.'/repository/update'),422,'unsafe_repository_file');
            verify(command($type==='git'?['git','rev-parse','HEAD']:['hg','log','--rev','.','--template','{node}'],$path)===$beforeUpdate,$type.': unsafe incoming update does not change the checked-out revision.');
            verify(!file_exists($path.'/'.$filename) && !is_link($path.'/'.$filename),$type.': unsafe incoming files are rejected before any checkout.');
            $schema['groups'][0]['elements'][1]['default']=20+$securityIndex;
            $blockedSync=expectError(fn()=>$call('PUT','/systems/'.$id.'/parameters',$schema),409,'repository_sync_failed');
            verify(($blockedSync->fields['synchronization']??'')==='Local changes were retained.' && str_contains($blockedSync->fields['repository']??'','No incoming files were applied.'),$type.': synchronization failure reports the safe incoming-file rejection and local retention.');
            verify(json_decode(file_get_contents($path.'/parameters.json'),true)[0]['elements'][1]['default']===20+$securityIndex,$type.': unsafe incoming save synchronization retains its committed local change.');
            verify(!file_exists($path.'/'.$filename) && !is_link($path.'/'.$filename),$type.': automatic synchronization never materializes an unsafe incoming file.');
            command($type==='git'?['git','rm','--',$filename]:['hg','remove','--',$filename],$seed);
            command($type==='git'?['git','-c','user.name=Fixture','-c','user.email=fixture@localhost','commit','--quiet','-m','Remove unsafe incoming fixture']:['hg','commit','--user','Fixture <fixture@localhost>','--message','Remove unsafe incoming fixture'],$seed);
            command($type==='git'?['git','push',$remote,$selected]:['hg','push',$remote],$seed);
            $call('POST','/systems/'.$id.'/repository/push');
            verify($remoteSchema()[0]['elements'][1]['default']===20+$securityIndex,$type.': synchronization succeeds after the unsafe remote tree is repaired.');
        }
        $advanceRemote('concurrent-marker.txt', 'Independent upstream change.');
        $schema['groups'][0]['elements'][1]['default'] = 16; $call('PUT', '/systems/' . $id . '/parameters', $schema);
        verify(file_get_contents($path . '/concurrent-marker.txt') === 'Independent upstream change.', $type . ': automatic pull merges an independent upstream change.');
        verify($remoteSchema()[0]['elements'][1]['default'] === 16, $type . ': automatic merge preserves and pushes the local configuration.');
        $conflict = $remoteSchema(); $conflict[0]['elements'][1]['default'] = 17;
        $advanceRemote('parameters.json', json_encode($conflict, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $schema['groups'][0]['elements'][1]['default'] = 18;
        expectError(fn() => $call('PUT', '/systems/' . $id . '/parameters', $schema), 409, 'repository_sync_failed');
        verify(json_decode((string) file_get_contents($path . '/parameters.json'), true)[0]['elements'][1]['default'] === 18, $type . ': a merge conflict restores the committed local configuration.');
        verify($remoteSchema()[0]['elements'][1]['default'] === 17, $type . ': a merge conflict preserves the upstream configuration.');
        verify(command($type === 'git' ? ['git', 'status', '--porcelain'] : ['hg', 'status'], $path) === '', $type . ': conflict rollback leaves no uncommitted or backup files.');
        if ($type === 'git') command(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost', 'merge', '--strategy=ours', '--no-edit', 'FETCH_HEAD'], $path);
        else {
            $remoteRevision = command(['hg', 'log', '--rev', $selected, '--template', '{node}'], $remote);
            command(['hg', 'merge', '--tool', 'internal:local', '--rev', $remoteRevision], $path);
            command(['hg', 'commit', '--user', 'Fixture <fixture@localhost>', '--message', 'Fixture resolves the conflict'], $path);
        }
        $call('POST', '/systems/' . $id . '/repository/push');
        verify($remoteSchema()[0]['elements'][1]['default'] === 18, $type . ': manual retry succeeds after an explicit conflict resolution.');
        file_put_contents($path . '/payload.txt', 'Uncommitted local work'); expectError(fn() => $call('POST', '/systems/' . $id . '/repository/update'), 409, 'repository_dirty');
        verify(file_get_contents($path . '/payload.txt') === 'Uncommitted local work', $type . ': dirty update preserves local data.');
        verify(!file_exists($sentinel), $type . ': reads/writes/updates did not run configured hooks.');
        command($type === 'git' ? ['git', 'checkout', '--', 'payload.txt'] : ['hg', 'revert', '--no-backup', '--', 'payload.txt'], $path);
        $store->insert('Project', ['projectId' => $id, 'userId' => 99, 'systemId' => $id, 'name' => 'Synthetic member project']);
        $store->insert('Experiment', ['experimentId' => $id, 'projectId' => $id, 'systemId' => $id, 'name' => 'Synthetic experiment']);
        $member = ['id' => 3, 'role' => 0];
        $scoped = static function () use ($id, $store, $member, $root, $sessions): array { return (new SystemRoutes(new Request('POST', '/api/v3/experiments/' . $id . '/result-configurations/push', ['content-type' => 'application/json'], '{}'), $store, $member, $root, $sessions))->handle('/experiments/' . $id . '/result-configurations/push'); };
        expectError($scoped, 403, 'forbidden');
        $store->insert('ProjectUser', ['projectUserId' => $id, 'projectId' => $id, 'userId' => 3]);
        verify($scoped()['body']['data'] === ['synchronized' => true], $type . ': project members can retry their saved configuration without system-owner access or secret DTOs.');
    }
    foreach ([3, 4, 5, 6] as $id) { mkdir($root . '/webroot/systems/' . $id); $insert($id, $id === 5 ? 'hg' : 'git', '', 'main'); }
    symlink($root . '/webroot/systems/1/.git', $root . '/webroot/systems/4/.git');
    mkdir($root . '/webroot/systems/5/.hg'); file_put_contents($root . '/webroot/systems/5/.hg/sharedpath', $root . '/webroot/systems/2/.hg');
    file_put_contents($root . '/webroot/systems/6/.git', 'gitdir: ' . $root . '/webroot/systems/1/.git');
    foreach ([3, 4, 5, 6] as $id) expectError(fn() => $call('GET', '/systems/' . $id . '/repository'), 409, 'repository_unavailable');
    mkdir($root . '/webroot/systems/8'); command(['git', 'init', '--quiet', '--initial-branch=main'], $root . '/webroot/systems/8'); $insert(8, 'git', '', 'main');
    $empty = $call('GET', '/systems/8/repository')['body']['data']; verify($empty['revision'] === null && $empty['history'] === [] && $empty['total'] === 0, 'A newly initialized empty repository has a usable empty history.');
    // Exercise the real creation transaction, validation, HTTP Git/Hg clone and rollback.
    mkdir($root . '/webroot/images'); file_put_contents($root . '/webroot/images/active.png', 'Synthetic fallback logo');
    $create = static function (array $body, array $creatingActor = ['id'=>1,'role'=>1]) use ($store,$root,$sessions): array {
        $request = new Request('POST','/api/v3/systems',['content-type'=>'application/json'],json_encode((object)$body,JSON_THROW_ON_ERROR));
        $kernel = new \Chronos\Api\V3\Kernel($request,$sessions,$root);
        (new ReflectionProperty($kernel,'store'))->setValue($kernel,$store); (new ReflectionProperty($kernel,'actor'))->setValue($kernel,$creatingActor);
        return (new ReflectionMethod($kernel,'createSystem'))->invoke($kernel);
    };
    $local = $create(['name'=>'New local system','ownerId'=>1]);
    verify(is_dir($root.'/webroot/systems/'.$local['id'].'/.git') && is_file($root.'/webroot/systems/'.$local['id'].'/parameters.json'),'Local system creation retains isolated Git/default configuration.');
    expectError(fn()=>$create(['name'=>'Forbidden clone','ownerId'=>1,'repository'=>['type'=>'git','url'=>'https://example.invalid/repository','branch'=>'main']],['id'=>1,'role'=>0]),403,'forbidden');
    foreach (['/private/path','file:///private/path','ext::danger','https://user:secret@example.invalid/repository','https://example.invalid/repository?token=secret'] as $unsafeUrl) expectError(fn()=>SystemRoutes::repositoryInput(['type'=>'git','url'=>$unsafeUrl,'branch'=>'main']),422,'validation_failed');
    expectError(fn()=>SystemRoutes::repositoryInput(['type'=>'git','url'=>'https://example.invalid/repository','branch'=>'--upload-pack=danger']),422,'validation_failed');
    foreach (['git','hg'] as $type) {
        if (!ENABLE_REMOTE_REPOSITORY) {
            expectError(fn()=>$create(['name'=>'Disabled remote','ownerId'=>1,'repository'=>['type'=>$type,'url'=>'https://example.invalid/repository','branch'=>'main']]),403,'forbidden');
            continue;
        }
        $remote=$root.'/'.$type.'-remote'; $branch=$type==='git'?'feature-with-spaces-label-safe':'feature branch with spaces'; $seed=$root.'/'.$type.'-seed';
        if($type==='git'){command(['git','fetch',$remote,$branch],$seed);command(['git','reset','--hard','FETCH_HEAD'],$seed);}else{command(['hg','pull',$remote],$seed);command(['hg','update','--clean','--rev',$branch],$seed);}
        file_put_contents($seed.'/config.json',json_encode(['name'=>'Retained repository name','identifier'=>'retained-'.$type]));
        $legacyResults=json_encode([['id'=>'legacy-bar','type'=>'bar-plot','name'=>'Retained legacy chart','parameter'=>'latency']]);
        file_put_contents($seed.'/results.json',$legacyResults);file_put_contents($seed.'/resultsJob.json','[]');file_put_contents($seed.'/logo.png','Retained repository logo');
        command([$type,'add','--','config.json','results.json','resultsJob.json','logo.png'],$seed);
        command($type==='git'?['git','-c','user.name=Fixture','-c','user.email=fixture@localhost','commit','--quiet','-m','Retained legacy configuration']:['hg','commit','--user','Fixture <fixture@localhost>','--message','Retained legacy configuration'],$seed);
        command($type==='git'?['git','push',$remote,$branch]:['hg','push',$remote],$seed);
        if ($type==='git') { command(['git','update-server-info'], $remote); [$server,$port]=startServer([PHP_BINARY,'-S','127.0.0.1:{port}','-t',$root],$root); $url='http://127.0.0.1:'.$port.'/git-remote'; }
        else { [$server,$port]=startServer(['hg','--repository',$remote,'serve','--address','127.0.0.1','--port','{port}'],$root); $url='http://127.0.0.1:'.$port; }
        $servers[]=$server;
        $repository=['type'=>$type,'url'=>$url,'branch'=>$branch,'username'=>'fixture-user','password'=>'fixture-secret'];
        $clone=$create(['name'=>'Cloned '.$type,'ownerId'=>1,'repository'=>$repository]); $clonedPath=$root.'/webroot/systems/'.$clone['id'];
        verify(is_dir($clonedPath.'/.'.$type),$type.': remote system creation uses the requested VCS.');
        verify(file_get_contents($clonedPath.'/branch-marker.txt')==='Branch selection is independent of UI labels.',$type.': creation checks out the selected remote branch.');
        $clonedSchema=json_decode(file_get_contents($clonedPath.'/parameters.json'),true);
        verify($clonedSchema[0]['elements'][1]['default']===18 && $clonedSchema[0]['elements'][0]['default']===false,$type.': clone preserves existing parameter schemas and typed false metadata.');
        verify($clone['identifier']==='retained-'.$type && file_get_contents($clonedPath.'/logo.png')==='Retained repository logo',$type.': clone retains configured system identity and logo bytes.');
        verify(!file_exists($clonedPath.'/resultConfigurations.json') && file_get_contents($clonedPath.'/results.json')===$legacyResults,$type.': cloning retains legacy result files without automatic migration.');
        verify(!str_contains(json_encode($clone),'fixture-secret'),$type.': creation DTO excludes remote credentials.');
        verify(glob(sys_get_temp_dir().'/chronos-v3-clone-*')===[],$type.': private clone credential directory is removed.');
        $before=(int)$pdo->query('SELECT COUNT(*) FROM System')->fetchColumn();
        $repository['branch']='does-not-exist';
        expectError(fn()=>$create(['name'=>'Invalid branch','ownerId'=>1,'repository'=>$repository]),503,'repository_clone_failed');
        verify((int)$pdo->query('SELECT COUNT(*) FROM System')->fetchColumn()===$before,$type.': clone failure rolls back the database row.');
        verify(count(glob($root.'/webroot/systems/[0-9]*'))===$before,$type.': clone failure removes only its new owned directory.');
        // A tracked server override must never bypass v3 routing/trusted extension policy.
        $seed=$root.'/'.$type.'-seed';
        if($type==='git'){command(['git','fetch',$remote,$branch],$seed);command(['git','reset','--hard','FETCH_HEAD'],$seed);}else{command(['hg','pull',$remote],$seed);command(['hg','update','--clean','--rev',$branch],$seed);}
        file_put_contents($seed.'/.htaccess','Require all granted');command([$type,'add','--','.htaccess'],$seed);
        command($type==='git'?['git','-c','user.name=Fixture','-c','user.email=fixture@localhost','commit','--quiet','-m','Unsafe override fixture']:['hg','commit','--user','Fixture <fixture@localhost>','--message','Unsafe override fixture'],$seed);
        command($type==='git'?['git','push',$remote,$branch]:['hg','push',$remote],$seed);
        if($type==='git')command(['git','update-server-info'],$remote);
        $repository['branch']=$branch;
        expectError(fn()=>$create(['name'=>'Unsafe clone','ownerId'=>1,'repository'=>$repository]),422,'unsafe_repository_file');
        verify((int)$pdo->query('SELECT COUNT(*) FROM System')->fetchColumn()===$before,$type.': unsafe clone rolls back completely.');
        command($type==='git'?['git','rm','--','.htaccess']:['hg','remove','--','.htaccess'],$seed);
        symlink('../../application-marker.txt',$seed.'/unsafe-link');command([$type,'add','--','unsafe-link'],$seed);
        command($type==='git'?['git','-c','user.name=Fixture','-c','user.email=fixture@localhost','commit','--quiet','-m','Unsafe link fixture']:['hg','commit','--user','Fixture <fixture@localhost>','--message','Unsafe link fixture'],$seed);
        command($type==='git'?['git','push',$remote,$branch]:['hg','push',$remote],$seed);if($type==='git')command(['git','update-server-info'],$remote);
        expectError(fn()=>$create(['name'=>'Unsafe symlink clone','ownerId'=>1,'repository'=>$repository]),422,'unsafe_repository_file');
        verify((int)$pdo->query('SELECT COUNT(*) FROM System')->fetchColumn()===$before,$type.': tracked symlink clone rolls back completely.');
        $remove=['unsafe-link','config.json','results.json','resultsJob.json','logo.png'];command($type==='git'?['git','rm','--',...$remove]:['hg','remove','--',...$remove],$seed);
        command($type==='git'?['git','-c','user.name=Fixture','-c','user.email=fixture@localhost','commit','--quiet','-m','Minimal system fixture']:['hg','commit','--user','Fixture <fixture@localhost>','--message','Minimal system fixture'],$seed);
        command($type==='git'?['git','push',$remote,$branch]:['hg','push',$remote],$seed);if($type==='git')command(['git','update-server-info'],$remote);
        $remoteRevision=command($type==='git'?['git','rev-parse','refs/heads/'.$branch]:['hg','log','--rev',$branch,'--template','{node}'],$remote);
        $minimal=$create(['name'=>'Minimal cloned '.$type,'ownerId'=>1,'repository'=>$repository]);$minimalPath=$root.'/webroot/systems/'.$minimal['id'];
        verify(is_file($minimalPath.'/config.json') && is_file($minimalPath.'/resultConfigurations.json') && is_file($minimalPath.'/logo.png'),$type.': missing defaults are initialized after a minimal clone.');
        verify(command($type==='git'?['git','status','--porcelain']:['hg','status'],$minimalPath)==='',$type.': generated defaults are committed locally so future synchronization remains usable.');
        verify(command($type==='git'?['git','rev-parse','refs/heads/'.$branch]:['hg','log','--rev',$branch,'--template','{node}'],$remote)===$remoteRevision,$type.': creating missing defaults never publishes remote changes inside a database transaction.');


    }
    verify(command(['git', 'rev-parse', 'HEAD'], $root) === $parentRevision, 'Application parent repository is unchanged.');
    verify(!file_exists($root . '/.git/chronos-v3-config.lock'), 'No configuration lock escaped into the application parent.');
    echo $checks . ' assertions passed on PHP ' . PHP_VERSION . ' with real Git and Mercurial; remote flag ' . (ENABLE_REMOTE_REPOSITORY ? 'enabled' : 'disabled') . ".\n";
} finally { foreach($servers as $server) { proc_terminate($server);proc_close($server); } removeFixture($root); }
