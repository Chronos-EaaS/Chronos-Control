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
foreach (['HttpError', 'Request', 'Input', 'Policy', 'Dto', 'Store', 'Context', 'Sessions', 'Repository', 'SystemRoutes'] as $name) require_once $source . '/api/v3/' . $name . '.php';
date_default_timezone_set('Europe/Zurich');
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
function expectError(callable $action, int $status, string $code): void
{
    try { $action(); throw new RuntimeException('Expected ' . $code); }
    catch (HttpError $error) { verify($error->status === $status && $error->errorCode === $code, 'Expected ' . $code . ', received ' . $error->getMessage()); }
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
$root = sys_get_temp_dir() . '/chronos-v3-repository-fixture-' . bin2hex(random_bytes(8));
mkdir($root . '/webroot/systems', 0700, true);
try {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE System (systemId INTEGER PRIMARY KEY,userId INTEGER,name TEXT,description TEXT,vcsUrl TEXT,vcsBranch TEXT,vcsType TEXT,vcsUser TEXT,vcsPassword TEXT,created TEXT,lastEdit TEXT,isArchived INTEGER,cem INTEGER)');
    $pdo->exec('CREATE TABLE Event (eventId INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,time TEXT,eventText TEXT,eventType TEXT,relatedId INTEGER,userId INTEGER,nodeId TEXT)');
    $pdo->exec('CREATE TABLE Project (projectId INTEGER PRIMARY KEY,userId INTEGER,systemId INTEGER,name TEXT)');
    $pdo->exec('CREATE TABLE ProjectUser (projectUserId INTEGER PRIMARY KEY,projectId INTEGER,userId INTEGER)');
    $pdo->exec('CREATE TABLE Experiment (experimentId INTEGER PRIMARY KEY,projectId INTEGER,systemId INTEGER,name TEXT)');
    $store = new Store($pdo); $actor = ['id' => 1, 'role' => 0];
    $sessions = (new ReflectionClass(Sessions::class))->newInstanceWithoutConstructor();
    $call = static function (string $method, string $path, array $body = []) use ($store, $actor, $root, $sessions): array {
        $request = new Request($method, '/api/v3' . $path, ['content-type' => 'application/json'], json_encode((object) $body, JSON_THROW_ON_ERROR));
        return (new SystemRoutes($request, $store, $actor, $root, $sessions))->handle($path) ?? throw new RuntimeException('Unmatched route.');
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
        $advanceRemote = static function (string $filename, string $contents) use ($type, $seed, $remote, $selected): void {
            if ($type === 'git') { command(['git', 'fetch', $remote, $selected], $seed); command(['git', 'merge', '--ff-only', 'FETCH_HEAD'], $seed); }
            else { command(['hg', 'pull', $remote], $seed); command(['hg', 'update', '--rev', $selected], $seed); }
            file_put_contents($seed . '/' . $filename, $contents); command([$type, 'add', '--', $filename], $seed);
            command($type === 'git' ? ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost', 'commit', '--quiet', '-m', 'Concurrent remote configuration'] : ['hg', 'commit', '--user', 'Fixture <fixture@localhost>', '--message', 'Concurrent remote configuration'], $seed);
            command($type === 'git' ? ['git', 'push', $remote, $selected] : ['hg', 'push', $remote], $seed);
        };
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
    verify(command(['git', 'rev-parse', 'HEAD'], $root) === $parentRevision, 'Application parent repository is unchanged.');
    verify(!file_exists($root . '/.git/chronos-v3-config.lock'), 'No configuration lock escaped into the application parent.');
    echo $checks . ' assertions passed on PHP ' . PHP_VERSION . ' with real Git and Mercurial; remote flag ' . (ENABLE_REMOTE_REPOSITORY ? 'enabled' : 'disabled') . ".\n";
} finally { removeFixture($root); }
