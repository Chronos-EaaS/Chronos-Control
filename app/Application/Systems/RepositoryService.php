<?php
declare(strict_types=1);

namespace Chronos\Application\Systems;

use Chronos\Application\Shared\Context;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Application\Security\Policy;
use Chronos\Infrastructure\VersionControl\Repository;
use Chronos\Infrastructure\VersionControl\{RepositoryInspector, RepositoryProcess};

final class RepositoryService
{
    private readonly RepositoryInspector $inspector;

    public function __construct(private readonly Context $context, private readonly string $root) { $this->inspector = new RepositoryInspector($root); }

    public static function remoteEnabled(): bool { return defined('ENABLE_REMOTE_REPOSITORY') && ENABLE_REMOTE_REPOSITORY; }

    public function push(int $id): void
    {
        $system = $this->context->system($id);
        $path = $this->inspector->directory($id);
        $type = $this->inspector->adapter($path);
        if ($type !== ($system['vcsType'] ?? 'git')) throw new HttpError(409, 'repository_type_mismatch', 'The configured VCS type does not match this system repository.');

        Policy::require($this->remoteEnabled());
        $lock = fopen($path . '/.' . $type . '/chronos-v3-config.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new HttpError(503, 'configuration_busy', 'The system repository is busy.');
        try { $this->synchronize($path, $type, $system); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
        $this->context->event('system', $id, 'System repository synchronized', 'Committed local changes were synchronized with the configured branch.');

    }

    public function update(int $id): void
    {
        $system = $this->context->system($id);
        $path = $this->inspector->directory($id);
        $type = $this->inspector->adapter($path);
        if ($type !== ($system['vcsType'] ?? 'git')) throw new HttpError(409, 'repository_type_mismatch', 'The configured VCS type does not match this system repository.');

        Policy::require($this->remoteEnabled());
        $lock = fopen($path . '/.' . $type . '/chronos-v3-config.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new HttpError(503, 'configuration_busy', 'The system repository is busy.');
        try {
            [$code, $dirty] = RepositoryProcess::run($path, $type, $type === 'git' ? ['status', '--porcelain'] : ['status']);
            if ($code !== 0 || trim($dirty) !== '') throw new HttpError(409, 'repository_dirty', 'Commit or resolve local system changes before updating.');
            $branch = (string) $system['vcsBranch'];
            if ($branch === '' || str_starts_with($branch, '-') || preg_match('/[\x00-\x1f\x7f]/', $branch)) Input::invalid('branch', 'The configured branch is invalid.');
            $url = (string) $system['vcsUrl'];
            if ($url === '' || str_starts_with($url, '-') || preg_match('/[\x00-\x1f\x7f]/', $url)) Input::invalid('repository', 'Configure a repository address before updating.');
            if ($type === 'git') {
                [$valid] = Repository::run($path, ['check-ref-format', '--branch', $branch]);
                if ($valid !== 0) Input::invalid('branch', 'The configured branch is invalid.');
                [$hasOrigin] = Repository::run($path, ['remote', 'get-url', 'origin']);
                [$remote] = Repository::run($path, $hasOrigin === 0 ? ['remote', 'set-url', 'origin', $url] : ['remote', 'add', 'origin', $url]);
                if ($remote !== 0) throw new HttpError(409, 'repository_update_failed', 'The repository remote could not be configured.');
                [$code] = RepositoryProcess::authenticated($path, 'git', ['fetch', '--', 'origin', $branch], $system);
                if ($code !== 0) throw new HttpError(409, 'repository_update_failed', 'The repository could not be fetched. Check its configured access.');
                $this->inspector->assertIncomingTree($path, 'git', 'FETCH_HEAD');
                [$localBranch] = Repository::run($path, ['show-ref', '--verify', '--quiet', 'refs/heads/' . $branch]);
                $commands = [$localBranch === 0 ? ['checkout', $branch] : ['checkout', '-b', $branch, 'FETCH_HEAD'], ['merge', '--ff-only', 'FETCH_HEAD']];
            } else {
                [$code] = RepositoryProcess::authenticated($path, 'hg', ['pull', '--', $url], $system);
                if ($code !== 0) throw new HttpError(409, 'repository_update_failed', 'The Mercurial repository could not be fetched. Check its configured access.');
                $this->inspector->assertIncomingTree($path, 'hg', $branch);
                $commands = [['update', '--check', '--rev', $branch]];
            }
            foreach ($commands as $arguments) {
                [$code] = RepositoryProcess::run($path, $type, $arguments);
                if ($code !== 0) throw new HttpError(409, 'repository_update_failed', 'The repository could not be updated. Check its remote access and resolve branch conflicts.');
            }
            $this->context->event('system', $id, 'System repository updated', 'The configured repository branch was updated.');
        } finally { flock($lock, LOCK_UN); fclose($lock); }

    }

    public function history(int $id, array $query = []): array
    {
        $system = $this->context->system($id);
        $path = $this->inspector->directory($id);
        $type = $this->inspector->adapter($path);
        if ($type !== ($system['vcsType'] ?? 'git')) throw new HttpError(409, 'repository_type_mismatch', 'The configured VCS type does not match this system repository.');
        [$code, $revision] = RepositoryProcess::run($path, $type, $type === 'git' ? ['rev-parse', 'HEAD'] : ['log', '--rev', '.', '--template', '{node}']);
        [, $branches] = RepositoryProcess::run($path, $type, $type === 'git' ? ['for-each-ref', '--format=%(refname:short)', 'refs/heads'] : ['branches', '--template', "{branch}\n"]);
        [$historyCode, $history] = RepositoryProcess::run($path, $type, $type === 'git' ? ['log', '--all', '--format=%H%x1f%P%x1f%aI%x1f%an%x1f%s%x1f%D'] : ['log', '--template', "{node}\x1f{p1node} {p2node}\x1f{date|isodatesec}\x1f{author|person}\x1f{desc|firstline}\x1f{branch}\n"], ['GIT_TERMINAL_PROMPT' => '0'], 16777216);
        if ($historyCode !== 0 && $code === 0) throw new HttpError(409, 'repository_history_unavailable', 'The complete repository history could not be loaded within the configured resource limit.');
        $entries = [];
        foreach (explode("\n", trim($history)) as $line) {
            $parts = explode("\x1f", $line, 6);
            if (count($parts) === 6) $entries[] = ['revision' => $parts[0], 'parents' => array_values(array_filter(explode(' ', $parts[1]), static fn(string $parent): bool => $parent !== '' && !preg_match('/^0+$/D', $parent))), 'at' => (new \DateTimeImmutable($parts[2]))->format(DATE_ATOM), 'author' => $parts[3], 'message' => $parts[4], 'branches' => array_values(array_filter(explode(', ', $parts[5])))];
        }
        Input::fields($query, ['page', 'pageSize', 'search', 'sort']);
        $options = $this->context->options($query, ['date' => 'at', 'author' => 'author', 'message' => 'message', 'revision' => 'revision'], '-date');
        if ($options['search'] !== '') { $needle = mb_strtolower($options['search'], 'UTF-8'); $entries = array_values(array_filter($entries, static fn(array $entry): bool => str_contains(mb_strtolower(implode(' ', [$entry['revision'], $entry['author'], $entry['message'], (new \DateTimeImmutable($entry['at']))->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('d.m.Y H:i:s'), ...$entry['parents'], ...$entry['branches']]), 'UTF-8'), $needle))); }
        $sort = (string) ($query['sort'] ?? '-date'); $key = ['date' => 'at', 'author' => 'author', 'message' => 'message', 'revision' => 'revision'][ltrim($sort, '-')]; $direction = str_starts_with($sort, '-') ? -1 : 1;
        usort($entries, static fn(array $left, array $right): int => $direction * ($key === 'at' ? strtotime($left[$key]) <=> strtotime($right[$key]) : strnatcasecmp($left[$key], $right[$key])) ?: strcmp($left['revision'], $right['revision']));
        $total = count($entries); $page = min($options['page'], max(1, (int) ceil($total / $options['pageSize'])));
        $entries = array_slice($entries, ($page - 1) * $options['pageSize'], $options['pageSize']);
        return ['revision' => $code === 0 ? trim($revision) : null, 'branches' => array_values(array_filter(explode("\n", trim($branches)))), 'history' => $entries, 'remoteEnabled' => $this->remoteEnabled(), 'total' => $total, 'page' => $page, 'pageSize' => $options['pageSize'], 'sort' => $sort];
    }

    public function initializeRemote(int $id, array $repository): void
    {
        Policy::require($this->remoteEnabled() && Policy::admin($this->context->actor));
        $path = $this->inspector->directory($id);
        if (count(scandir($path) ?: []) !== 2) throw new HttpError(409, 'repository_not_empty', 'A remote can only initialize a new empty system.');
        $type = $repository['vcsType'];
        $credentials = sys_get_temp_dir() . '/chronos-v3-clone-' . bin2hex(random_bytes(12));
        if (!mkdir($credentials, 0700)) throw new HttpError(503, 'repository_unavailable', 'Private clone credentials could not be prepared.');
        try {
            $arguments = $type === 'git'
                ? ['clone', '--quiet', '--no-checkout', '--no-recurse-submodules', '--branch', $repository['vcsBranch'], '--', $repository['vcsUrl'], $path]
                : ['clone', '--noupdate', '--branch', $repository['vcsBranch'], '--', $repository['vcsUrl'], $path];
            [$code] = RepositoryProcess::authenticated($path, $type, $arguments, $repository, $credentials);
            if ($code !== 0) throw new HttpError(503, 'repository_clone_failed', 'The repository could not be cloned. Check the address, branch and credentials. No system was created.');
            $this->inspector->adapter($path);
            $this->inspector->assertIncomingTree($path, $type, $type === 'git' ? 'HEAD' : $repository['vcsBranch']);
            [$code] = RepositoryProcess::run($path, $type, $type === 'git' ? ['reset', '--hard', 'HEAD'] : ['update', '--clean', '--rev', $repository['vcsBranch']]);
            if ($code !== 0) throw new HttpError(503, 'repository_clone_failed', 'The selected repository branch could not be checked out. No system was created.');
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                $relative = substr($entry->getPathname(), strlen($path) + 1);
                // Mercurial creates a private wcache/checklink capability probe.
                // Metadata was verified separately; reject links in checked-out files.
                if ($relative === '.' . $type || str_starts_with($relative, '.' . $type . '/')) continue;
                if ($entry->isLink() || in_array(strtolower($entry->getFilename()), ['.htaccess', '.user.ini'], true)) throw new HttpError(422, 'unsafe_repository_file', 'A cloned system must not contain symbolic links or web-server override files. No system was created.');
            }
            foreach (['config.json', 'parameters.json', 'resultConfigurations.json', 'results.json', 'resultsJob.json'] as $file) if (file_exists($path . '/' . $file)) ConfigurationStore::readJson($path . '/' . $file);
            if (file_exists($path . '/parameters.json')) ConfigurationStore::parameterGroups($this->root, $id);
            if (file_exists($path . '/resultConfigurations.json') || file_exists($path . '/results.json') || file_exists($path . '/resultsJob.json')) ConfigurationStore::resultDocument($this->root, $id);
        } finally { rmdir($credentials); }
    }

    public function recordInitialConfiguration(int $id, array $files): void
    {
        Policy::require($this->remoteEnabled() && Policy::admin($this->context->actor));
        if ($files === []) return;
        foreach ($files as $file) if (!in_array($file, ['config.json', 'parameters.json', 'resultConfigurations.json', 'logo.png'], true)) throw new \LogicException('Unexpected initialization file.');
        $path = $this->inspector->directory($id); $type = $this->inspector->adapter($path);
        [$code] = RepositoryProcess::run($path, $type, ['add', '--', ...$files]);
        if ($code === 0) [$code] = RepositoryProcess::run($path, $type, $type === 'git'
            ? ['-c', 'user.name=Chronos', '-c', 'user.email=chronos@localhost', 'commit', '--only', '-m', 'Initialize missing system configuration', '--', ...$files]
            : ['commit', '--user', 'Chronos <chronos@localhost>', '--message', 'Initialize missing system configuration', '--', ...$files]);
        if ($code !== 0) throw new HttpError(503, 'repository_initialization_failed', 'Missing system defaults could not be recorded locally. No system was created.');
    }

    public function autoSync(int $id): void
    {
        if (!$this->remoteEnabled()) return;
        $system = $this->context->db->row('SELECT * FROM `System` WHERE systemId=?', [$id]);
        if ($system === null || trim((string) $system['vcsUrl']) === '') return;
        try {
            $path = $this->inspector->directory($id); $type = $this->inspector->adapter($path);
            if ($type !== $system['vcsType']) throw new \RuntimeException('Configured repository type mismatch.');
            $this->synchronize($path, $type, $system);
        } catch (\Throwable $error) {
            if ($error instanceof HttpError && $error->errorCode === 'repository_sync_failed') throw $error;
            $fields = ['synchronization' => 'Local changes were retained.'];
            if ($error instanceof HttpError && in_array($error->errorCode, ['unsafe_repository_file', 'repository_tree_unavailable'], true)) $fields['repository'] = $error->getMessage();
            throw new HttpError(409, 'repository_sync_failed', 'The configuration is saved locally, but repository synchronization failed. The committed local version is retained. Check the configured repository type and access, then use Push changes to retry.', $fields);
        }
    }

    private function synchronize(string $path, string $type, array $system): void
    {
        $failure = static fn(): HttpError => new HttpError(409, 'repository_sync_failed', 'The configuration is saved locally, but repository synchronization failed. The committed local version is retained. Resolve remote access or conflicts, then use Push changes to retry.', ['synchronization' => 'Local changes were retained.']);
        [$clean, $dirty] = RepositoryProcess::run($path, $type, $type === 'git' ? ['status', '--porcelain'] : ['status']);
        if ($clean !== 0 || trim($dirty) !== '') throw $failure();
        $branch = (string) $system['vcsBranch']; $url = (string) $system['vcsUrl'];
        if ($branch === '' || $url === '' || str_starts_with($branch, '-') || str_starts_with($url, '-') || preg_match('/[\x00-\x1f\x7f]/', $branch . $url)) throw $failure();
        [$current, $name] = RepositoryProcess::run($path, $type, $type === 'git' ? ['branch', '--show-current'] : ['branch']);
        if ($current !== 0 || trim($name) !== $branch) throw $failure();
        if ($type === 'git') {
            [$valid] = Repository::run($path, ['check-ref-format', '--branch', $branch]); if ($valid !== 0) throw $failure();
            [$hasOrigin] = Repository::run($path, ['remote', 'get-url', 'origin']);
            [$remote] = Repository::run($path, $hasOrigin === 0 ? ['remote', 'set-url', 'origin', $url] : ['remote', 'add', 'origin', $url]);
            if ($remote !== 0) throw $failure();
            [$fetched] = RepositoryProcess::authenticated($path, 'git', ['fetch', '--', 'origin', $branch], $system); if ($fetched !== 0) throw $failure();
            $this->inspector->assertIncomingTree($path, 'git', 'FETCH_HEAD');
            [$merged] = RepositoryProcess::run($path, 'git', ['-c', 'user.name=Chronos', '-c', 'user.email=chronos@localhost', 'merge', '--no-edit', '--no-verify', 'FETCH_HEAD']);
            if ($merged !== 0) { RepositoryProcess::run($path, 'git', ['merge', '--abort']); throw $failure(); }
            [$pushed] = RepositoryProcess::authenticated($path, 'git', ['push', '--', 'origin', 'HEAD:refs/heads/' . $branch], $system);
            if ($pushed !== 0) throw $failure();
            return;
        }
        [$revision, $saved] = RepositoryProcess::run($path, 'hg', ['log', '--rev', '.', '--template', '{node}']); if ($revision !== 0) throw $failure();
        [$pulled] = RepositoryProcess::authenticated($path, 'hg', ['pull', '--', $url], $system); if ($pulled !== 0) throw $failure();
        [$status, $heads] = RepositoryProcess::run($path, 'hg', ['heads', '--template', "{node}\t{branch}\n"]); if ($status !== 0) throw $failure();
        $others = [];
        foreach (explode("\n", trim($heads)) as $line) { $parts = explode("\t", $line, 2); if (count($parts) === 2 && $parts[1] === $branch && $parts[0] !== trim($saved)) $others[] = $parts[0]; }
        foreach ($others as $head) $this->inspector->assertIncomingTree($path, 'hg', $head);
        foreach ($others as $head) {
            [$ancestor, $isAncestor] = RepositoryProcess::run($path, 'hg', ['log', '--rev', 'ancestor(' . trim($saved) . ',' . $head . ')', '--template', '{node}']);
            if ($ancestor !== 0) throw $failure();
            if (trim($isAncestor) === trim($saved)) {
                [$updated] = RepositoryProcess::run($path, 'hg', ['update', '--check', '--rev', $head]); if ($updated !== 0) throw $failure();
            } else {
                [$merged] = RepositoryProcess::run($path, 'hg', ['--config', 'ui.origbackuppath=.hg/chronos-merge-backups', 'merge', '--tool', 'internal:merge', '--rev', $head]);
                if ($merged === 0) [$merged] = RepositoryProcess::run($path, 'hg', ['commit', '--user', 'Chronos <chronos@localhost>', '--message', 'Merge remote system configuration']);
                if ($merged !== 0) { RepositoryProcess::run($path, 'hg', ['update', '--clean', '--rev', trim($saved)]); throw $failure(); }
            }
            [, $saved] = RepositoryProcess::run($path, 'hg', ['log', '--rev', '.', '--template', '{node}']);
        }
        [$pushed] = RepositoryProcess::authenticated($path, 'hg', ['push', '--rev', '.', '--', $url], $system);
        if ($pushed !== 0) {
            // Mercurial returns 1 when there is nothing to push. Verify the
            // desired commit is already present rather than treating it as a failure.
            [$outgoing] = RepositoryProcess::authenticated($path, 'hg', ['outgoing', '--quiet', '--rev', '.', '--template', '{node}', '--', $url], $system);
            if ($outgoing !== 1) throw $failure();
        }
    }

    public function pushExperiment(int $id): array
    {
        $experiment = $this->context->experiment($id);
        Policy::require(self::remoteEnabled());
        $systemId = (int) $experiment['systemId'];
        $path = $this->inspector->directory($systemId); $type = $this->inspector->adapter($path);
        $lock = fopen($path . '/.' . $type . '/chronos-v3-config.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new HttpError(503, 'configuration_busy', 'The system repository is busy.');
        try { $this->autoSync($systemId); } finally { flock($lock, LOCK_UN); fclose($lock); }
        return ['synchronized' => true];
    }

}
