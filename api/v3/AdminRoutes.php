<?php
declare(strict_types=1);

namespace Chronos\Api\V3;

/** Administration/CEM resources. Kernel authenticates and checks mutation CSRF. */
final class AdminRoutes
{
    private readonly AdminOperationExecutor $operations;

    public function __construct(
        private readonly Request $request,
        private readonly Store $db,
        private readonly array $actor,
        private readonly string $root,
        private readonly Sessions $sessions,
        ?AdminOperationExecutor $operations = null,
    ) {
        $this->operations = $operations ?? new NativeAdminOperations($db, $root);
    }

    public function handle(string $path): ?array
    {
        if (!str_starts_with($path, '/admin/') && !str_starts_with($path, '/cem/')) {
            return null;
        }
        Policy::require(Policy::admin($this->actor));
        if ($path === '/admin/settings') {
            $this->request->assertMethod(['GET']);
            return $this->success(['sections' => $this->sections()]);
        }
        if (preg_match('#^/admin/settings/([^/]+)$#D', $path, $match)) {
            $this->request->assertMethod(['PATCH']);
            return $this->success($this->saveSection($match[1]));
        }
        if ($path === '/admin/environments') {
            $this->request->assertMethod(['GET', 'POST']);
            if ($this->request->method === 'POST') {
                return $this->success($this->addEnvironment(), 201);
            }
            return $this->success(['items' => $this->environments()]);
        }
        if (preg_match('#^/admin/environments/([^/]+)$#D', $path, $match)) {
            $this->request->assertMethod(['DELETE']);
            return $this->success($this->deleteEnvironment($match[1]));
        }
        if ($path === '/admin/storage') {
            $this->request->assertMethod(['GET']);
            return $this->success($this->storage());
        }
        if ($path === '/admin/maintenance') {
            $this->request->assertMethod(['GET', 'PATCH']);
            Policy::require(Policy::superAdmin($this->actor));
            if ($this->request->method === 'PATCH') {
                $body = $this->request->json();
                Input::fields($body, ['branch']);
                $branch = $this->branch($body['branch'] ?? null);
                $this->db->transaction(function () use ($branch): void {
                    $this->putSetting('vcs', 'repoBranch', $branch);
                    $this->event('Update branch selected', 'The application update branch was changed.');
                });
            }
            return $this->success($this->maintenance());
        }
        if ($path === '/admin/operations') {
            $this->request->assertMethod(['POST']);
            Policy::require(Policy::superAdmin($this->actor));
            return $this->success($this->planOperation(), 201);
        }
        if (preg_match('#^/admin/operations/([a-f0-9]{32})/(simulate|execute)$#D', $path, $match)) {
            $this->request->assertMethod(['POST']);
            Policy::require(Policy::superAdmin($this->actor));
            return $this->success($this->runPlan($match[1], $match[2]));
        }
        if ($path === '/cem/nodes') {
            $this->request->assertMethod(['GET']);
            return $this->success($this->nodes());
        }
        if (preg_match('#^/cem/nodes/([^/]+)$#D', $path, $match)) {
            $this->request->assertMethod(['GET']);
            return $this->success($this->nodeDetail($match[1]));
        }
        return null;
    }

    private function success(mixed $data, int $status = 200): array
    {
        return ['status' => $status, 'body' => ['data' => $data]];
    }

    private function setting(string $section, string $key, string $fallback = ''): string
    {
        $row = $this->db->row('SELECT value FROM `Setting` WHERE systemId=0 AND section=? AND item=? ORDER BY settingId LIMIT 1', [$section, $key]);
        return $row === null ? $fallback : (string) $row['value'];
    }

    private function putSetting(string $section, string $key, string $value): void
    {
        $existing = $this->db->row('SELECT settingId FROM `Setting` WHERE systemId=0 AND section=? AND item=? FOR UPDATE', [$section, $key]);
        if ($existing !== null) {
            $this->db->update('Setting', 'settingId', (int) $existing['settingId'], ['value' => $value]);
        } else {
            $this->db->insert('Setting', ['systemId' => 0, 'section' => $section, 'item' => $key, 'value' => $value]);
        }
    }

    private function secret(string $section, string $key): bool
    {
        return (bool) preg_match('/password|secret|token|private.?key|api.?key/i', $key)
            || ($section === 'maas' && in_array($key, ['key', 'consumer_key'], true));
    }

    private function revision(array $rows): string
    {
        return hash('sha256', serialize(array_map(static fn(array $row): array => [
            (int) $row['settingId'], (string) $row['item'], (string) $row['value'],
        ], $rows)));
    }

    private function sectionRows(string $section, bool $lock = false): array
    {
        return $this->db->rows('SELECT settingId,section,item,value FROM `Setting` WHERE systemId=0 AND section=? AND NOT(section=\'cem\' AND item=\'environments\') ORDER BY item,settingId' . ($lock ? ' FOR UPDATE' : ''), [$section]);
    }

    private function sectionDto(string $section, array $rows): array
    {
        return ['name' => $section, 'revision' => $this->revision($rows), 'settings' => array_map(function (array $row) use ($section): array {
            $secret = $this->secret($section, $row['item']);
            return ['key' => $row['item'], 'value' => $secret ? null : (string) $row['value'], 'secret' => $secret, 'configured' => $row['value'] !== ''];
        }, $rows)];
    }

    private function sections(): array
    {
        $names = $this->db->rows('SELECT DISTINCT section FROM `Setting` WHERE systemId=0 AND section<>\'vcs\' ORDER BY section');
        $sections = [];
        foreach ($names as $row) {
            $rows = $this->sectionRows($row['section']);
            if ($rows !== []) {
                $sections[] = $this->sectionDto($row['section'], $rows);
            }
        }
        return $sections;
    }

    private function saveSection(string $section): array
    {
        $section = Input::text($section, 'section', 50);
        if ($section === 'vcs') {
            throw new HttpError(403, 'dedicated_resource', 'Application update settings are managed through maintenance.');
        }
        $body = $this->request->json();
        Input::fields($body, ['revision', 'values']);
        $revision = Input::text($body['revision'] ?? null, 'revision', 64);
        if (!is_array($body['values'] ?? null) || array_is_list($body['values'])) {
            Input::invalid('values', 'Submit an object of setting names and values.');
        }
        $values = $body['values'];
        if ($values === []) {
            Input::invalid('values', 'Change at least one setting.');
        }
        return $this->db->transaction(function () use ($section, $revision, $values): array {
            $rows = $this->sectionRows($section, true);
            if ($rows === []) {
                throw new HttpError(404, 'not_found', 'This settings section does not exist.');
            }
            if (!hash_equals($this->revision($rows), $revision)) {
                throw new HttpError(409, 'settings_changed', 'These settings changed in another session. Reload them before saving.');
            }
            $keys = array_column($rows, 'item');
            foreach ($values as $key => $value) {
                if (!in_array($key, $keys, true)) {
                    Input::invalid('values.' . $key, 'This setting is not available in this section.');
                }
                $value = Input::text($value, 'values.' . $key, 4096, false);
                $this->validateSetting($section, $key, $value);
                $this->putSetting($section, $key, $value);
            }
            $this->event('Global settings updated', 'Settings in section ' . $section . ' were updated.');
            return $this->sectionDto($section, $this->sectionRows($section));
        });
    }

    private function validateSetting(string $section, string $key, string $value): void
    {
        $field = 'values.' . $key;
        if ($section === 'other' && $key === 'rowsPerPage') {
            Input::integer($value, $field, 1, 100);
        } elseif ($section === 'other' && $key === 'maxJobsPerEvaluation') {
            Input::integer($value, $field, 1, 1000000);
        } elseif ($section === 'cem' && $key === 'nodeMissingTimeout') {
            Input::integer($value, $field, 1, 31536000);
        } elseif ($section === 'ftp' && $key === 'ftpPort') {
            Input::integer($value, $field, 1, 65535);
        } elseif ($section === 'ftp' && $key === 'useFtpUploadForLocalClients' && !in_array($value, ['0', '1'], true)) {
            Input::invalid($field, 'Use 0 or 1.');
        }
    }

    private function environmentNames(bool $lock = false): array
    {
        $row = $this->db->row('SELECT value FROM `Setting` WHERE systemId=0 AND section=\'cem\' AND item=\'environments\'' . ($lock ? ' FOR UPDATE' : ''));
        if ($row === null || $row['value'] === '') {
            return [];
        }
        try {
            $names = json_decode((string) $row['value'], true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(409, 'invalid_configuration', 'The saved CEM environment list needs repair.');
        }
        if (!is_array($names) || !array_is_list($names) || count(array_filter($names, 'is_string')) !== count($names)) {
            throw new HttpError(409, 'invalid_configuration', 'The saved CEM environment list needs repair.');
        }
        return array_values(array_unique($names));
    }

    private function missingAfter(): int
    {
        $value = $this->setting('cem', 'nodeMissingTimeout', '86400');
        return ctype_digit($value) ? max(1, min((int) $value, 31536000)) : 86400;
    }

    private function cutoff(): string
    {
        return date('Y-m-d H:i:s', time() - $this->missingAfter());
    }

    private function environments(): array
    {
        $items = [];
        foreach ($this->environmentNames() as $name) {
            $row = $this->db->row('SELECT COUNT(*) AS count FROM `Node` WHERE environment=? AND lastUpdate>?', [$name, $this->cutoff()]);
            $items[] = ['name' => $name, 'nodeCount' => (int) $row['count']];
        }
        return $items;
    }

    private function addEnvironment(): array
    {
        $body = $this->request->json();
        Input::fields($body, ['name']);
        $name = Input::text($body['name'] ?? null, 'name', 64);
        if (strpbrk($name, "/\\?#%") !== false) {
            Input::invalid('name', 'Use an environment name without URL separators.');
        }
        $this->db->transaction(function () use ($name): void {
            $names = $this->environmentNames(true);
            if (in_array($name, $names, true)) {
                throw new HttpError(409, 'environment_exists', 'An environment with this name already exists.', ['name' => 'Choose another name.']);
            }
            $names[] = $name;
            $this->putSetting('cem', 'environments', json_encode($names, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->event('CEM environment added', 'A CEM environment was added.');
        });
        return ['items' => $this->environments()];
    }

    private function deleteEnvironment(string $name): array
    {
        $name = Input::text($name, 'name', 64);
        $this->db->transaction(function () use ($name): void {
            $names = $this->environmentNames(true);
            if (!in_array($name, $names, true)) {
                throw new HttpError(404, 'not_found', 'This environment does not exist.');
            }
            $jobs = $this->db->row('SELECT COUNT(*) AS count FROM `Job` WHERE environment=? AND status IN (-2,0,1,2)', ['cem-' . $name]);
            $nodes = $this->db->row('SELECT COUNT(*) AS count FROM `Node` WHERE environment=? AND lastUpdate>?', [$name, $this->cutoff()]);
            if ((int) $jobs['count'] > 0 || (int) $nodes['count'] > 0) {
                throw new HttpError(409, 'environment_in_use', 'This environment still has reporting nodes or active jobs.');
            }
            $names = array_values(array_filter($names, static fn(string $item): bool => $item !== $name));
            $this->putSetting('cem', 'environments', json_encode($names, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->event('CEM environment removed', 'An unused CEM environment was removed.');
        });
        return ['items' => $this->environments()];
    }

    private function storage(): array
    {
        $path = defined('UPLOADED_DATA_PATH') ? rtrim(UPLOADED_DATA_PATH, '/') : $this->root . '/webroot/data';
        $exists = is_dir($path);
        $stat = $exists ? @stat($path) : false;
        $parent = $exists ? @stat(dirname($path)) : false;
        $free = $exists ? @disk_free_space($path) : false;
        $total = $exists ? @disk_total_space($path) : false;
        return [
            'path' => $path, 'exists' => $exists, 'readable' => $exists && is_readable($path),
            'writable' => $exists && is_writable($path),
            'mounted' => $stat !== false && $parent !== false ? $stat['dev'] !== $parent['dev'] : null,
            'freeBytes' => $free === false ? null : (int) $free, 'totalBytes' => $total === false ? null : (int) $total,
            'checkedAt' => date(DATE_ATOM),
        ];
    }

    private function branches(): array
    {
        [$code, $output] = Repository::run($this->root, ['for-each-ref', '--format=%(refname:short)', 'refs/heads', 'refs/remotes/origin']);
        $branches = $code === 0 ? preg_split('/\R/', trim($output)) : [];
        $branches = array_map(static fn(string $branch): string => preg_replace('#^origin/#', '', $branch), $branches ?: []);
        $valid = static fn(string $branch): bool => $branch !== 'HEAD' && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$#D', $branch) === 1
            && !str_contains($branch, '..') && !str_contains($branch, '//') && !str_ends_with($branch, '/') && !str_ends_with($branch, '.lock');
        $branches = array_values(array_unique(array_filter($branches, $valid)));
        $selected = $this->setting('vcs', 'repoBranch', 'master');
        if ($valid($selected) && !in_array($selected, $branches, true)) {
            $branches[] = $selected;
        }
        sort($branches, SORT_NATURAL | SORT_FLAG_CASE);
        return $branches;
    }

    private function branch(mixed $value): string
    {
        $branch = Input::text($value, 'branch', 200);
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$#D', $branch) || $branch === 'HEAD'
            || str_contains($branch, '..') || str_contains($branch, '//') || str_ends_with($branch, '/') || str_ends_with($branch, '.lock')) {
            Input::invalid('branch', 'Enter a valid repository branch. The update plan verifies its published build.');
        }
        return $branch;
    }

    private function simulationsAvailable(): bool
    {
        return getenv('CHRONOS_TEST_STACK') === '1' && is_file('/.dockerenv');
    }

    private function maintenance(): array
    {
        $capabilities = $this->operations->capabilities();
        return [
            'branches' => $this->branches(), 'selectedBranch' => $this->setting('vcs', 'repoBranch', 'master'),
            'installedVersion' => defined('APP_VERSION') ? APP_VERSION : 'unknown',
            'simulationAvailable' => $this->simulationsAvailable(), 'executionAvailable' => in_array(true, $capabilities, true),
            'executionMessage' => 'Operation availability depends on the configured deployment, storage and demo services.',
            'capabilities' => $capabilities,
            'actions' => ['update', 'rollback', 'mount', 'reset-demo', 'reset-local-systems'],
        ];
    }

    private function planOperation(): array
    {
        $body = $this->request->json();
        Input::fields($body, ['action', 'branch']);
        $action = Input::text($body['action'] ?? null, 'action', 30);
        $definitions = [
            'update' => ['Review application update', 'UPDATE CHRONOS', ['Verify the required PHP runtime, selected CI package and build identity.', 'Create a recoverable deployment snapshot.', 'Install the verified files, record the selected branch and reopen the application.']],
            'rollback' => ['Review application rollback', 'ROLL BACK CHRONOS', ['Verify the selected deployment snapshot and backup file hashes.', 'Restore the previous package files and release metadata.', 'Restore the previous entrypoint last, then reopen the application.']],
            'mount' => ['Review storage mount', 'MOUNT STORAGE', ['Verify the configured data directory and mount configuration.', 'Mount the configured storage using the approved host executor.', 'Verify the resulting mount and file access.']],
            'reset-demo' => ['Review demo reset', 'RESET DEMO', ['Replace demo application data with its initial dataset.', 'Reset demo system definitions in the demo environment.', 'Invalidate affected user sessions.']],
            'reset-local-systems' => ['Review local system reset', 'RESET LOCAL SYSTEMS', ['Identify systems without a remote repository.', 'Back up their current definitions.', 'Reinitialize only the selected local system definitions.']],
        ];
        if (!isset($definitions[$action])) {
            Input::invalid('action', 'Choose a supported maintenance operation.');
        }
        $branch = $action === 'update' ? $this->branch($body['branch'] ?? $this->setting('vcs', 'repoBranch', 'master')) : null;
        [$title, $confirmation, $steps] = $definitions[$action];
        $execution = $this->operations->plan($action, ['branch' => $branch]);
        $id = bin2hex(random_bytes(16));
        $plan = [
            'id' => $id, 'action' => $action, 'title' => $title, 'branch' => $branch,
            'steps' => $steps, 'confirmation' => $confirmation, 'createdAt' => date(DATE_ATOM),
            'expiresAt' => date(DATE_ATOM, time() + 600), 'state' => 'planned',
            'impact' => $execution['impact'], 'resources' => $execution['resources'] ?? [],
            'unavailableReason' => $execution['unavailableReason'] ?? null,
            'canSimulate' => $this->simulationsAvailable(), 'canExecute' => (bool) ($execution['canExecute'] ?? false),
        ];
        $saved = $_SESSION['v3AdminPlans'] ?? [];
        $saved = is_array($saved) ? array_filter($saved, static fn(array $item): bool => $item['expires'] > time()) : [];
        if (count($saved) >= 10) {
            $saved = array_slice($saved, -9, null, true);
        }
        $saved[$id] = ['actorId' => $this->actor['id'], 'expires' => time() + 600, 'plan' => $plan, 'execution' => $execution];
        $_SESSION['v3AdminPlans'] = $saved;
        return $plan;
    }

    private function runPlan(string $id, string $mode): array
    {
        $stored = $_SESSION['v3AdminPlans'][$id] ?? null;
        if (!is_array($stored) || ($stored['actorId'] ?? null) !== $this->actor['id'] || ($stored['expires'] ?? 0) <= time()) {
            throw new HttpError(404, 'plan_expired', 'This operation plan expired. Review a new plan before continuing.');
        }
        $body = $this->request->json();
        Input::fields($body, ['confirmation']);
        if (!is_string($body['confirmation'] ?? null) || !hash_equals($stored['plan']['confirmation'], $body['confirmation'])) {
            Input::invalid('confirmation', 'Enter the confirmation phrase shown in the operation plan.');
        }
        if ($stored['plan']['state'] !== 'planned') {
            throw new HttpError(409, 'plan_finished', 'This operation has already been submitted. Review a new plan before continuing.');
        }
        $plan = $stored['plan'];
        if ($mode === 'execute') {
            if (!$plan['canExecute']) {
                throw new HttpError(503, 'executor_unavailable', $plan['unavailableReason'] ?? 'This operation is not configured. No changes were applied.');
            }
            $_SESSION['v3AdminPlans'][$id]['plan']['state'] = 'running';
            try {
                $result = $this->operations->execute($plan['action'], $stored['execution']);
                $plan['state'] = 'completed';
                $plan['completedAt'] = date(DATE_ATOM);
                $plan['output'] = $result['output'];
                $plan['signedOut'] = (bool) ($result['signedOut'] ?? false);
                $_SESSION['v3AdminPlans'][$id]['plan'] = $plan;
                if ($plan['signedOut']) {
                    $this->sessions->logout();
                } else {
                    $this->event('Maintenance operation completed', $plan['title'] . ' completed.');
                }
                return $plan;
            } catch (\Throwable $error) {
                $_SESSION['v3AdminPlans'][$id]['plan']['state'] = 'failed';
                throw $error;
            }
        }
        Policy::require($this->simulationsAvailable());
        // Simulation validates the complete review/confirmation lifecycle but
        // deliberately does not invoke git, mount, demo reset or a shell runner.
        $plan['state'] = 'simulated';
        $plan['completedAt'] = date(DATE_ATOM);
        $plan['output'] = 'Simulation completed. No application, storage or system data was changed.';
        $_SESSION['v3AdminPlans'][$id]['plan'] = $plan;
        return $plan;
    }

    private function nodes(): array
    {
        $query = $this->request->query;
        $includeMissing = Input::queryBoolean($query['includeMissing'] ?? 'false', 'includeMissing');
        unset($query['includeMissing']);
        $options = Input::listOptions($query, ['id' => 'n.nodeId', 'hostname' => 'n.hostname', 'environment' => 'n.environment', 'updated' => 'n.lastUpdate'], 'hostname');
        $where = ['1=1'];
        $args = [];
        if (!$includeMissing) {
            $where[] = 'n.lastUpdate>?';
            $args[] = $this->cutoff();
        }
        if ($options['search'] !== '') {
            $pattern = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $options['search']) . '%';
            $where[] = '(n.nodeId LIKE ? ESCAPE \'=\' OR n.hostname LIKE ? ESCAPE \'=\' OR n.environment LIKE ? ESCAPE \'=\')';
            array_push($args, $pattern, $pattern, $pattern);
        }
        $sql = implode(' AND ', $where);
        $count = $this->db->row('SELECT COUNT(*) AS count FROM `Node` n WHERE ' . $sql, $args);
        $offset = ($options['page'] - 1) * $options['pageSize'];
        $rows = $this->db->rows('SELECT n.* FROM `Node` n WHERE ' . $sql . ' ORDER BY ' . $options['order'] . ',n.nodeId ASC LIMIT ' . $options['pageSize'] . ' OFFSET ' . $offset, $args);
        return ['items' => array_map(fn(array $row): array => $this->nodeDto($row), $rows), 'total' => (int) $count['count'], 'page' => $options['page'], 'pageSize' => $options['pageSize'], 'missingAfterSeconds' => $this->missingAfter()];
    }

    private function nodeDto(array $row): array
    {
        $cpu = is_numeric($row['cpu']) ? max(0.0, min(100.0, (float) $row['cpu'])) : null;
        $used = is_numeric($row['memoryUsed']) && (float) $row['memoryUsed'] >= 0 ? (int) $row['memoryUsed'] : null;
        $total = is_numeric($row['memoryTotal']) && (float) $row['memoryTotal'] > 0 ? (int) $row['memoryTotal'] : null;
        $memory = $used !== null && $total !== null ? max(0.0, min(100.0, 100 * $used / $total)) : null;
        return [
            'id' => $row['nodeId'], 'hostname' => $row['hostname'] ?: $row['nodeId'], 'environment' => $row['environment'],
            'version' => $row['version'], 'os' => $row['os'] ?? '', 'ip' => $row['ip'] ?? '',
            'currentJobId' => $row['currentJob'] === null ? null : (int) $row['currentJob'],
            'cpuPercent' => $cpu, 'memoryUsedBytes' => $used, 'memoryTotalBytes' => $total, 'memoryPercent' => $memory,
            'healthStatus' => Dto::text($row['healthStatus'] ?? ''), 'warning' => trim($row['healthStatus'] ?? '') !== '',
            'lastSeenAt' => Dto::instant($row['lastUpdate']), 'missing' => $row['lastUpdate'] === null || $row['lastUpdate'] <= $this->cutoff(),
        ];
    }

    private function nodeDetail(string $id): array
    {
        $id = Input::text($id, 'nodeId', 64);
        $row = $this->db->row('SELECT * FROM `Node` WHERE nodeId=?', [$id])
            ?? throw new HttpError(404, 'not_found', 'This node does not exist.');
        $events = $this->db->rows('SELECT e.*,u.firstname AS actorFirstName,u.lastname AS actorLastName FROM `Event` e LEFT JOIN `User` u ON u.userId=e.userId WHERE e.nodeId=? ORDER BY e.time DESC,e.eventId DESC LIMIT 50', [$id]);
        $projection = new EventProjection(new Context($this->db, $this->actor, $this->root));
        return ['node' => $this->nodeDto($row), 'events' => array_map(static function (array $event) use ($projection): array {
            return $projection->event($event) + ['jobId' => $event['relatedId'] === null ? null : (int) $event['relatedId']];
        }, $events)];
    }

    private function event(string $title, string $text): void
    {
        $this->db->insert('Event', [
            'title' => $title, 'eventText' => $text, 'eventType' => 'user', 'relatedId' => $this->actor['id'],
            'time' => date('Y-m-d H:i:s'), 'userId' => $this->actor['id'], 'nodeId' => null,
        ]);
    }
}

/** Injectable boundary for host operations; an API request cannot supply a command or path. */
interface AdminOperationExecutor
{
    public function capabilities(): array;
    public function plan(string $action, array $parameters): array;
    public function execute(string $action, array $plan): array;
}

final class NativeAdminOperations implements AdminOperationExecutor
{
    private const TABLES = ['Evaluation', 'Event', 'Experiment', 'Job', 'Project', 'ProjectUser', 'Session', 'Setting', 'System', 'User', 'Node'];
    private readonly \Closure $command;
    private readonly \Closure $repository;

    public function __construct(private readonly Store $db, private readonly string $root, ?callable $command = null, ?callable $repository = null)
    {
        $this->command = $command === null ? self::runCommand(...) : \Closure::fromCallable($command);
        $this->repository = $repository === null ? Repository::run(...) : \Closure::fromCallable($repository);
    }

    private function enabled(string $flag): bool
    {
        return defined($flag) && constant($flag) === true;
    }

    private function releases(): bool
    {
        if (!class_exists('\Release_Library', false) && is_file($this->root . '/libraries/release.php')) {
            require_once $this->root . '/libraries/release.php';
        }
        return class_exists('\Release_Library', false);
    }

    public function capabilities(): array
    {
        $release = $this->releases() ? \Release_Library::inspect($this->root) : [];
        return [
            'update' => (bool) ($release['capabilities']['update'] ?? false),
            'rollback' => (bool) ($release['capabilities']['rollback'] ?? false),
            'mount' => $this->enabled('CHRONOS_ENABLE_STORAGE_MOUNT'),
            'reset-demo' => $this->enabled('CHRONOS_ENABLE_DEMO_RESET') && defined('CHRONOS_DEMO_SNAPSHOT'),
            'reset-local-systems' => $this->enabled('CHRONOS_ENABLE_LOCAL_SYSTEM_RESET'),
        ];
    }

    public function plan(string $action, array $parameters): array
    {
        $enabled = $this->capabilities()[$action] ?? false;
        $plan = ['canExecute' => $enabled, 'resources' => [], 'impact' => 'Review the selected operation before continuing.'];
        if (!$enabled) {
            $plan['unavailableReason'] = 'This operation is disabled or its service has not been configured.';
        }
        if (in_array($action, ['update', 'rollback'], true)) {
            if ($enabled) {
                try {
                    $plan['release'] = \Release_Library::plan($this->root, $action, $parameters);
                } catch (\RuntimeException $error) {
                    throw new HttpError(409, 'deployment_plan_unavailable', $error->getMessage());
                }
                $release = $plan['release'];
                $plan['resources'] = [
                    'Current installation: ' . ($release['currentCommit'] ?? 'unversioned'),
                    'Target commit: ' . ($release['commit'] ?? $release['targetCommit'] ?? 'previous unversioned installation'),
                ];
                if (isset($release['manifest']['archive']['sha256'])) {
                    $plan['resources'][] = 'CI package SHA-256: ' . $release['manifest']['archive']['sha256'];
                }
            }
            $plan['impact'] = $action === 'update' ? 'Install the verified CI package for the selected branch. A recoverable backup is retained.' : 'Restore the previous application package from the saved deployment backup.';
        } elseif ($action === 'mount') {
            $path = defined('UPLOADED_DATA_PATH') ? rtrim(UPLOADED_DATA_PATH, '/') : $this->root . '/webroot/data';
            $plan['path'] = $path;
            $plan['resources'] = [$path];
            $plan['impact'] = 'Mount the configured data-storage directory. Existing application data is not reset.';
        } elseif ($action === 'reset-local-systems') {
            $systems = $this->localSystems();
            $plan['fingerprint'] = $this->fingerprint($systems);
            $plan['resources'] = array_map(static fn(array $row): string => '#' . $row['systemId'] . ' ' . Dto::text($row['name']), $systems);
            $plan['impact'] = 'Reset definitions for ' . count($systems) . ' local systems. Existing definitions are backed up. Systems with remote repositories are excluded.';
        } elseif ($action === 'reset-demo') {
            $plan['impact'] = 'Replace the entire application dataset and system definitions with the configured initial demo snapshot. Existing data is backed up and all sessions end.';
            if ($enabled) {
                $snapshot = $this->snapshot();
                $plan['fingerprint'] = $snapshot['fingerprint'];
                $plan['resources'] = ['Application database', 'All system definitions', 'All project parameter presets', 'All signed-in sessions'];
            }
        }
        return $plan;
    }

    public function execute(string $action, array $plan): array
    {
        if (!($this->capabilities()[$action] ?? false)) {
            throw new HttpError(503, 'operation_disabled', 'This maintenance operation is disabled. No changes were applied.');
        }
        if (in_array($action, ['update', 'rollback'], true)) {
            $result = \Release_Library::execute($this->root, $plan['release']);
            return ['output' => (string) ($result['output'] ?? $result['message'] ?? 'The deployment operation completed.')];
        }
        $backup = $this->backupRoot();
        $lock = fopen($backup . '/operation.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new HttpError(409, 'operation_busy', 'Another host-maintenance operation is running.');
        }
        try {
            if ($action === 'mount') {
                return $this->mount($plan);
            }
            return $this->reset($action, $plan, $backup);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function localSystems(bool $lock = false): array
    {
        return $this->db->rows('SELECT systemId,name,vcsUrl FROM `System` WHERE vcsUrl=\'\' OR vcsUrl IS NULL ORDER BY systemId' . ($lock ? ' FOR UPDATE' : ''));
    }

    private function fingerprint(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function backupRoot(): string
    {
        $path = defined('CHRONOS_MAINTENANCE_BACKUP_PATH') ? CHRONOS_MAINTENANCE_BACKUP_PATH : dirname($this->root) . '/.chronos-maintenance/' . substr(hash('sha256', $this->root), 0, 16);
        if (!is_string($path) || !str_starts_with($path, '/') || is_link($path)) {
            throw new HttpError(503, 'backup_unavailable', 'Configure a private absolute maintenance backup directory.');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true)) {
            throw new HttpError(503, 'backup_unavailable', 'The maintenance backup directory could not be created.');
        }
        $resolved = realpath($path);
        $root = realpath($this->root);
        if ($resolved === false || $root === false || $resolved === $root || str_starts_with($resolved . '/', $root . '/') || !is_writable($resolved)) {
            throw new HttpError(503, 'backup_unavailable', 'Maintenance backups must be writable and outside the application directory.');
        }
        chmod($resolved, 0700);
        return $resolved;
    }

    private function mount(array $plan): array
    {
        $configured = defined('UPLOADED_DATA_PATH') ? rtrim(UPLOADED_DATA_PATH, '/') : $this->root . '/webroot/data';
        if ($configured !== $plan['path'] || is_link($configured) || !is_dir($configured)) {
            throw new HttpError(409, 'resource_changed', 'The configured data directory changed or is not available. Review a new plan.');
        }
        $path = realpath($configured);
        if ($path === false || $path === '/') {
            throw new HttpError(409, 'invalid_storage', 'The configured data directory is not a valid mount target.');
        }
        $stat = stat($path); $parent = stat(dirname($path));
        if ($stat !== false && $parent !== false && $stat['dev'] !== $parent['dev']) {
            return ['output' => 'The configured data directory is already mounted.'];
        }
        [$code, $output] = ($this->command)(['/usr/bin/sudo', '-n', '/bin/mount', '--', $path]);
        if ($code !== 0) {
            throw new HttpError(502, 'mount_failed', 'The storage mount failed. Check the configured mount and non-interactive host permissions.');
        }
        return ['output' => trim($output) === '' ? 'The host mount command completed successfully.' : trim($output)];
    }

    private function snapshot(): array
    {
        $configured = defined('CHRONOS_DEMO_SNAPSHOT') ? CHRONOS_DEMO_SNAPSHOT : '';
        $path = is_string($configured) ? realpath($configured) : false;
        $root = realpath($this->root);
        if ($path === false || $root === false || is_link($configured) || !is_dir($path) || $path === $root || str_starts_with($path . '/', $root . '/')) {
            throw new HttpError(503, 'snapshot_unavailable', 'Configure a private initial demo snapshot outside the application directory.');
        }
        $files = $this->tree($path);
        if (!isset($files['dataset.json']) || $files['dataset.json']['size'] > 64 * 1024 * 1024) {
            throw new HttpError(503, 'invalid_snapshot', 'The demo snapshot needs a current-schema dataset.json file.');
        }
        try {
            $data = json_decode(file_get_contents($path . '/dataset.json'), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(503, 'invalid_snapshot', 'The initial demo dataset is not valid JSON.');
        }
        if (!is_array($data) || ($data['format'] ?? null) !== 1 || !is_array($data['tables'] ?? null)) {
            throw new HttpError(503, 'invalid_snapshot', 'The initial demo dataset has an unsupported format.');
        }
        $tables = $data['tables'];
        if (array_diff(array_keys($tables), self::TABLES) !== [] || array_diff(self::TABLES, array_keys($tables)) !== []) {
            throw new HttpError(503, 'invalid_snapshot', 'The demo snapshot must contain each supported application table exactly once.');
        }
        foreach ($tables as $table => $rows) {
            if (!is_array($rows) || !array_is_list($rows)) throw new HttpError(503, 'invalid_snapshot', 'Demo table rows must be lists.');
            $columns = array_column($this->db->rows('SHOW COLUMNS FROM `' . $table . '`'), 'Field');
            foreach ($rows as $row) {
                if (!is_array($row) || $row === [] || array_diff(array_keys($row), $columns) !== []) throw new HttpError(503, 'invalid_snapshot', 'Demo data does not match the current database schema.');
                foreach ($row as $value) {
                    if (!is_scalar($value) && $value !== null) throw new HttpError(503, 'invalid_snapshot', 'Demo fields must be scalar values.');
                }
            }
        }
        if ($tables['Session'] !== [] || !array_filter($tables['User'], static fn(array $row): bool => (int) ($row['role'] ?? 0) === 2 && (int) ($row['alive'] ?? 0) === 1 && (int) ($row['activated'] ?? 0) === 1 && (password_get_info((string) ($row['password'] ?? ''))['algoName'] ?? 'unknown') !== 'unknown')) {
            throw new HttpError(503, 'invalid_snapshot', 'A demo snapshot must have no sessions and must include an active superadministrator with a password hash.');
        }
        $projectIds = array_map(static fn(array $row): int => (int) ($row['projectId'] ?? 0), $tables['Project']);
        foreach ($files as $name => $metadata) {
            if (str_starts_with($name, 'presets/') && (!preg_match('#^presets/([1-9][0-9]*)\.json$#D', $name, $match) || !in_array((int) $match[1], $projectIds, true) || $metadata['size'] > 4194304)) {
                throw new HttpError(503, 'invalid_snapshot', 'Demo presets must be bounded JSON files belonging to projects in the snapshot.');
            }
            if (str_starts_with($name, 'presets/')) ParameterPresets::validateDocument((string) file_get_contents($path . '/' . $name));
        }
        foreach ($tables['System'] as $system) {
            $id = (string) ($system['systemId'] ?? '');
            if (!preg_match('/^[1-9][0-9]*$/D', $id) || !isset($files['systems/' . $id . '/config.json'])) throw new HttpError(503, 'invalid_snapshot', 'Each demo system needs its initial system definition directory.');
        }
        return ['path' => $path, 'tables' => $tables, 'fingerprint' => $this->fingerprint($files)];
    }

    /** Inspect bounded local files without following links. */
    private function tree(string $path): array
    {
        $files = []; $size = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink() || !$entry->isFile()) throw new HttpError(503, 'unsafe_files', 'Maintenance snapshots and system definitions cannot contain links or special files.');
            $relative = substr($entry->getPathname(), strlen($path) + 1);
            $size += $entry->getSize();
            if (count($files) >= 10000 || $size > 512 * 1024 * 1024) throw new HttpError(503, 'snapshot_too_large', 'The maintenance file set exceeds the supported snapshot size.');
            $hash = hash_file('sha256', $entry->getPathname());
            if ($hash === false) throw new HttpError(503, 'unreadable_files', 'A maintenance file cannot be read.');
            $files[$relative] = ['size' => $entry->getSize(), 'sha256' => $hash, 'mode' => $entry->getPerms() & 0777];
        }
        ksort($files);
        return $files;
    }

    private function copyTree(string $source, string $target): void
    {
        $files = $this->tree($source);
        if (!mkdir($target, 0700, true)) throw new HttpError(503, 'storage_unavailable', 'A maintenance staging directory could not be created.');
        foreach ($files as $relative => $metadata) {
            $destination = $target . '/' . $relative;
            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true)) throw new HttpError(503, 'storage_unavailable', 'A maintenance staging directory could not be created.');
            if (!copy($source . '/' . $relative, $destination) || hash_file('sha256', $destination) !== $metadata['sha256']) throw new HttpError(503, 'storage_unavailable', 'A maintenance file could not be copied and verified.');
            chmod($destination, 0600 | ($metadata['mode'] & 0111));
        }
    }

    private function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) throw new HttpError(503, 'storage_unavailable', 'A maintenance backup or definition could not be written.');
        chmod($path, 0600);
    }

    private function reset(string $action, array $plan, string $backup): array
    {
        $systemsPath = $this->root . '/webroot/systems';
        if (is_link($systemsPath) || !is_dir($systemsPath) || !is_writable($systemsPath) || stat($systemsPath)['dev'] !== stat($backup)['dev']) throw new HttpError(503, 'storage_unavailable', 'Maintenance requires a writable system directory and a private backup on the same filesystem.');
        $operation = $backup . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(8));
        if (!mkdir($operation, 0700)) throw new HttpError(503, 'backup_unavailable', 'A recoverable operation backup could not be created.');
        $swaps = [];
        try {
            $this->db->transaction(function () use ($action, $plan, $operation, $systemsPath, &$swaps): void {
                if ($action === 'reset-demo') {
                    $snapshot = $this->snapshot();
                    if (!hash_equals($plan['fingerprint'], $snapshot['fingerprint'])) throw new HttpError(409, 'resource_changed', 'The demo snapshot changed. Review a new operation plan.');
                    $tables = [];
                    foreach (self::TABLES as $table) $tables[$table] = $this->db->rows('SELECT * FROM `' . $table . '` FOR UPDATE');
                    $this->write($operation . '/database.json', json_encode(['format' => 1, 'tables' => $tables], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    $this->copyTree($snapshot['path'] . '/systems', $operation . '/new-systems');
                    $swaps[] = [$systemsPath, $operation . '/previous-systems', $operation . '/new-systems', false];
                    $this->swap($swaps[count($swaps) - 1]);
                    $presetPath = ParameterPresets::storageDirectory($this->root, true);
                    if (stat($presetPath)['dev'] !== stat($operation)['dev']) throw new HttpError(503, 'storage_unavailable', 'Demo preset backups must be on the same filesystem.');
                    if (is_dir($snapshot['path'] . '/presets')) $this->copyTree($snapshot['path'] . '/presets', $operation . '/new-presets');
                    elseif (!mkdir($operation . '/new-presets', 0700)) throw new HttpError(503, 'storage_unavailable', 'Demo parameter presets could not be staged.');
                    $swaps[] = [$presetPath, $operation . '/previous-presets', $operation . '/new-presets', false];
                    $this->swap($swaps[count($swaps) - 1]);
                    foreach (self::TABLES as $table) $this->db->execute('DELETE FROM `' . $table . '`');
                    foreach ($snapshot['tables'] as $table => $rows) foreach ($rows as $row) $this->db->insert($table, $row);
                } else {
                    $systems = $this->localSystems(true);
                    if (!hash_equals($plan['fingerprint'], $this->fingerprint($systems))) throw new HttpError(409, 'resource_changed', 'The local-system list changed. Review a new operation plan.');
                    foreach ($systems as $system) {
                        $id = (int) $system['systemId']; $current = $systemsPath . '/' . $id;
                        if ($id < 1 || is_link($current) || (file_exists($current) && !is_dir($current))) throw new HttpError(409, 'unsafe_system', 'A local system has an unsafe definition directory.');
                        if (is_dir($current)) $this->tree($current);
                        $stage = $operation . '/new-' . $id;
                        if (!mkdir($stage, 0700)) throw new HttpError(503, 'storage_unavailable', 'A local system could not be staged.');
                        $this->write($stage . '/config.json', json_encode(['name' => Dto::text($system['name']), 'identifier' => 'system-' . $id . '-' . bin2hex(random_bytes(6))], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                        $logo = $this->root . '/webroot/images/active.png';
                        if (is_file($logo) && !is_link($logo)) copy($logo, $stage . '/logo.png');
                        [$code] = ($this->repository)($stage, ['init']);
                        if ($code !== 0) throw new HttpError(503, 'repository_unavailable', 'A local system repository could not be initialized.');
                        $swaps[] = [$current, $operation . '/previous-' . $id, $stage, false];
                        $this->swap($swaps[count($swaps) - 1]);
                    }
                }
                $this->write($operation . '/operation.json', json_encode(['action' => $action, 'completedAt' => date(DATE_ATOM), 'fingerprint' => $plan['fingerprint']], JSON_THROW_ON_ERROR));
                if ($action === 'reset-demo') SessionEpoch::rotate($this->root);
            });
        } catch (\Throwable $error) {
            foreach (array_reverse($swaps) as [$current, $previous, $stage, $installed]) {
                if ($installed && is_dir($current) && !rename($current, $stage)) throw new HttpError(500, 'recovery_required', 'Maintenance recovery requires administrator attention. Preserve the private operation backup.');
                if (is_dir($previous) && !rename($previous, $current)) throw new HttpError(500, 'recovery_required', 'Maintenance recovery requires administrator attention. Preserve the private operation backup.');
            }
            throw $error;
        }
        return ['output' => 'The ' . ($action === 'reset-demo' ? 'initial demo dataset and system definitions were restored.' : 'selected local-system definitions were reset.') . ' A recoverable private backup was retained.', 'signedOut' => $action === 'reset-demo'];
    }

    private function swap(array &$swap): void
    {
        [$current, $previous, $stage] = $swap;
        if (file_exists($current) && !rename($current, $previous)) throw new HttpError(503, 'storage_unavailable', 'The existing definitions could not be moved into the private backup.');
        if (!rename($stage, $current)) throw new HttpError(503, 'storage_unavailable', 'The staged definitions could not be installed.');
        $swap[3] = true;
    }

    private static function runCommand(array $command): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C']);
        if (!is_resource($process)) return [1, 'The host command could not be started.'];
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $deadline = microtime(true) + 20; $exit = 1;
        do {
            $output = substr($output . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]), -65536);
            $status = proc_get_status($process);
            if (!$status['running']) { $exit = $status['exitcode']; break; }
            if (microtime(true) >= $deadline) { proc_terminate($process, 9); break; }
            usleep(20000);
        } while (true);
        $output = substr($output . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]), -65536);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        return [$exit, $output];
    }
}
