<?php
declare(strict_types=1);

namespace Chronos\Api\V3;

/** System-owned configuration and authorized, read-only result presentation. */
final class SystemRoutes
{
    private readonly Context $context;
    private const TYPES = ['boolean', 'checkbox-selection', 'dropdown', 'integer', 'interval', 'percentage', 'percentage-interval', 'percentage-dep', 'ratio', 'software-versions-list', 'string-list', 'string'];
    private const PLOTS = ['bar-plot' => 'bar-plot', 'line-plot' => 'line-plot', 'line-plot-multi' => 'line-plot-multi', 'pie-plot' => 'pie-plot', 'box-plot' => 'boxplot'];

    public function __construct(private readonly Request $request, private readonly Store $db, private readonly array $actor, private readonly string $root, private readonly Sessions $sessions)
    {
        $this->context = new Context($db, $actor, $root);
    }

    public function handle(string $path): ?array
    {
        if (preg_match('#^/experiments/([1-9][0-9]*)/result-configurations$#D', $path, $match)) {
            return $this->experimentConfigurations(Input::id($match[1], 'experimentId'));
        }
        if (preg_match('#^/systems/([1-9][0-9]*)(?:/(parameters|result-configurations|settings|environments|logo|export|import|history|repository|repository/update|result-configurations/migrate))?$#D', $path, $match)) {
            $id = Input::id($match[1], 'systemId');
            $system = $this->context->system($id);
            $action = $match[2] ?? '';
            return match ($action) {
                '' => $this->detail($id, $system),
                'parameters' => $this->parameters($id),
                'result-configurations' => $this->resultConfigurations($id),
                'result-configurations/migrate' => $this->migrateResults($id),
                'settings' => $this->settings($id),
                'environments' => $this->environments($id),
                'logo' => $this->logo($id),
                'export' => $this->export($id),
                'import' => $this->import($id),
                'history', 'repository', 'repository/update' => $this->repository($id, $system, $action),
            };
        }
        if (preg_match('#^/(evaluations|jobs)/([1-9][0-9]*)/(results|download|results.csv)$#D', $path, $match)) {
            $this->request->assertMethod(['GET']);
            $id = Input::id($match[2], 'id');
            $job = $match[1] === 'jobs';
            $resource = $job ? $this->context->job($id) : $this->context->evaluation($id);
            if ($match[3] === 'download') return $this->download($resource, $job);
            $rows = $job ? [$resource] : $this->db->rows('SELECT * FROM `Job` WHERE evaluationId=? ORDER BY internalId,jobId', [$id]);
            if ($match[3] === 'results.csv') return $this->csv($rows, ($job ? 'job' : 'evaluation') . $id . '.csv');
            return Context::success($this->results($resource, $rows, $job));
        }
        return null;
    }

    private function directory(int $id): string
    {
        $base = realpath($this->root . '/webroot/systems');
        $path = $this->root . '/webroot/systems/' . $id;
        $real = realpath($path);
        if ($base === false || $real === false || is_link($path) || dirname($real) !== $base) {
            throw new HttpError(409, 'system_files_unavailable', 'The isolated system directory is unavailable.');
        }
        return $real;
    }

    private static function readJson(string $path, mixed $fallback = []): mixed
    {
        if (!file_exists($path)) return $fallback;
        if (is_link($path) || !is_file($path) || filesize($path) > Request::MAX_BODY_BYTES) {
            throw new HttpError(409, 'invalid_configuration_file', 'The configuration file cannot be read safely.');
        }
        try { return json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpError(409, 'invalid_configuration_file', 'The configuration file contains invalid JSON. Export and repair the file before editing it.'); }
    }

    /** No legacy System constructor: reading a schema must never commit or migrate. */
    public static function parameterGroups(string $root, int $id): array
    {
        $path = $root . '/webroot/systems/' . $id;
        $base = realpath($root . '/webroot/systems');
        if ($base === false || is_link($path) || dirname(realpath($path) ?: '') !== $base) {
            throw new HttpError(409, 'system_files_unavailable', 'The isolated system directory is unavailable.');
        }
        $groups = self::readJson($path . '/parameters.json');
        if (!is_array($groups) || ($groups !== [] && !array_is_list($groups))) {
            throw new HttpError(409, 'invalid_parameter_schema', 'The parameter schema must contain a group array.');
        }
        return $groups;
    }

    private static function arrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) $value = get_object_vars($value);
        return is_array($value) ? array_map(self::arrays(...), $value) : $value;
    }

    private static function identifier(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D', $value)) Input::invalid($field, 'Use a stable ID containing letters, numbers, underscores or hyphens.');
        return $value;
    }

    public static function validateParameterGroups(mixed $value): array
    {
        $groups = self::arrays($value);
        if (!is_array($groups) || !array_is_list($groups) || count($groups) > 1000) Input::invalid('groups', 'Submit a group array of at most 1000 groups.');
        $ids = $parameters = $dependencies = [];
        foreach ($groups as $gi => &$group) {
            $field = 'groups.' . $gi;
            if (!is_array($group)) Input::invalid($field, 'Submit a group object.');
            $id = self::identifier($group['id'] ?? null, $field . '.id');
            if (isset($ids[$id])) Input::invalid($field . '.id', 'Each group and input needs a unique ID.');
            $ids[$id] = true;
            Input::text($group['title'] ?? null, $field . '.title', 256);
            $group['depends'] ??= '';
            $group['dependsValue'] ??= '';
            Input::text($group['depends'], $field . '.depends', 256, false);
            if (!is_scalar($group['dependsValue']) && $group['dependsValue'] !== null) Input::invalid($field . '.dependsValue', 'Use a scalar condition value.');
            if (!is_array($group['elements'] ?? null) || !array_is_list($group['elements']) || count($group['elements']) > 1000) Input::invalid($field . '.elements', 'Submit an input array.');
            foreach ($group['elements'] as $ei => $element) {
                $ef = $field . '.elements.' . $ei;
                if (!is_array($element)) Input::invalid($ef, 'Submit an input object.');
                $eid = self::identifier($element['id'] ?? null, $ef . '.id');
                if (isset($ids[$eid])) Input::invalid($ef . '.id', 'Each group and input needs a unique ID.');
                $ids[$eid] = true;
                $type = $element['type'] ?? null;
                if (!in_array($type, self::TYPES, true)) Input::invalid($ef . '.type', 'This custom input requires a registered v3 editor and renderer before it can be saved.');
                Input::text($element['name'] ?? null, $ef . '.name', 256);
                $names = explode(',', Input::text($element['parameter'] ?? null, $ef . '.parameter', 256));
                if (count($names) !== ($type === 'ratio' ? 2 : 1)) Input::invalid($ef . '.parameter', 'Use one parameter name, or two comma-separated names for a ratio.');
                foreach ($names as $name) {
                    if (!preg_match('/^[\p{L}_][\p{L}\p{N}_.-]*$/uD', $name) || in_array($name, ['run', 'elements'], true) || isset($parameters[$name])) Input::invalid($ef . '.parameter', 'Use distinct valid parameter names. The names run and elements are reserved.');
                    $parameters[$name] = $id;
                }
                if (in_array($type, ['dropdown', 'checkbox-selection'], true)) {
                    $options = $element['options'] ?? '';
                    $options = is_array($options) ? $options : (is_string($options) ? explode(',', $options) : []);
                    if ($options === [] || count($options) !== count(array_unique($options, SORT_REGULAR))) Input::invalid($ef . '.options', 'Supply distinct choices.');
                    foreach ($options as $option) Input::text($option, $ef . '.options', 256);
                    if ($type === 'dropdown' && isset($element['names']) && $element['names'] !== '' && (!is_string($element['names']) || count(explode(',', $element['names'])) !== count($options))) Input::invalid($ef . '.names', 'Supply one display label for each choice.');
                    if ($type === 'checkbox-selection') foreach ($options as $option) $parameters[$names[0] . '-' . $option] = $id;
                }
                if (str_starts_with($type, 'percentage')) {
                    Input::text($element['dependency'] ?? null, $ef . '.dependency', 256);
                    $dependencies[$names[0]] = $element['dependency'];
                    if (isset($element['parameter-type']) && !in_array($element['parameter-type'], ['int', 'float'], true)) Input::invalid($ef . '.parameter-type', 'Use int or float.');
                }
            }
        }
        unset($group);
        foreach ($dependencies as $name => $dependency) {
            if (!isset($parameters[$dependency])) Input::invalid('groups', 'A percentage dependency does not exist.');
            $seen = [$name => true];
            while (isset($dependencies[$dependency])) {
                if (isset($seen[$dependency])) Input::invalid('groups', 'Percentage dependencies must not contain a cycle.');
                $seen[$dependency] = true;
                $dependency = $dependencies[$dependency];
            }
        }
        $groupDependencies = [];
        foreach ($groups as $group) if ($group['depends'] !== '' && isset($parameters[$group['depends']])) $groupDependencies[$group['id']] = $parameters[$group['depends']];
        foreach ($groupDependencies as $id => $dependency) {
            $seen = [$id => true];
            while (true) {
                if (isset($seen[$dependency])) Input::invalid('groups', 'Group visibility must not depend on itself or contain a cycle.');
                $seen[$dependency] = true;
                if (!isset($groupDependencies[$dependency])) break;
                $dependency = $groupDependencies[$dependency];
            }
        }
        return $groups;
    }

    private function parameters(int $id): array
    {
        $this->request->assertMethod(['GET', 'PUT']);
        if ($this->request->method === 'PUT') {
            $body = $this->request->json(); Input::fields($body, ['groups']);
            $groups = self::validateParameterGroups($body['groups'] ?? null);
            $this->writeConfiguration($id, 'parameters.json', $groups, 'Updated experiment parameter schema');
        }
        return Context::success(['groups' => self::parameterGroups($this->root, $id)]);
    }

    public static function resultDocument(string $root, int $id): array
    {
        $path = $root . '/webroot/systems/' . $id;
        $base = realpath($root . '/webroot/systems');
        if ($base === false || is_link($path) || dirname(realpath($path) ?: '') !== $base) throw new HttpError(409, 'system_files_unavailable', 'The isolated system directory is unavailable.');
        if (file_exists($path . '/resultConfigurations.json')) {
            $document = self::readJson($path . '/resultConfigurations.json');
            if (!is_array($document) || !is_array($document['elements'] ?? null)) throw new HttpError(409, 'invalid_result_schema', 'The result configuration must contain an elements object.');
            return $document;
        }
        $all = self::readJson($path . '/results.json'); $job = self::readJson($path . '/resultsJob.json');
        if (!is_array($all) || !is_array($job)) throw new HttpError(409, 'invalid_result_schema', 'The legacy result configuration must contain plot arrays.');
        // Compatibility projection only. The GET path never writes or deletes files.
        return ['version' => '1.0', 'elements' => ($all !== [] || $job !== []) ? ['system-1' => ['name' => 'Default', 'all' => $all, 'job' => $job]] : []];
    }

    public static function validateResultDocument(mixed $value): array
    {
        $document = self::arrays($value);
        if (!is_array($document) || ($document['version'] ?? null) !== '1.0' || !is_array($document['elements'] ?? null) || count($document['elements']) > 1000) Input::invalid('elements', 'Submit a version 1.0 result configuration document.');
        foreach ($document['elements'] as $id => $configuration) {
            self::identifier($id, 'elements.id');
            if (strlen($id) > 50) Input::invalid('elements.id', 'Configuration IDs must fit the 50-character experiment field.');
            if (!is_array($configuration)) Input::invalid('elements.' . $id, 'Submit a configuration object.');
            Input::text($configuration['name'] ?? null, 'elements.' . $id . '.name', 256);
            foreach (['all', 'job'] as $scope) {
                $plots = $configuration[$scope] ?? null;
                if (!is_array($plots) || !array_is_list($plots) || count($plots) > 1000) Input::invalid('elements.' . $id . '.' . $scope, 'Submit a plot array.');
                $seen = [];
                foreach ($plots as $plot) {
                    if (!is_array($plot)) Input::invalid('elements.' . $id, 'Submit plot objects.');
                    $pid = self::identifier($plot['id'] ?? null, 'plot.id');
                    if (isset($seen[$pid])) Input::invalid('plot.id', 'Each plot needs a unique ID within its scope.');
                    $seen[$pid] = true;
                    if (!is_string($plot['type'] ?? null) || !isset(self::PLOTS[$plot['type']])) Input::invalid('plot.type', 'This custom plot requires a registered v3 editor and renderer before it can be saved.');
                    Input::text($plot['name'] ?? null, 'plot.name', 256);
                    Input::text($plot['parameter'] ?? null, 'plot.parameter', 1024);
                    if (in_array($plot['type'], ['line-plot-multi', 'box-plot'], true)) Input::text($plot['plotting'] ?? null, 'plot.plotting', 256);
                    if ($plot['type'] === 'box-plot' && !in_array($plot['orientation'] ?? 'vertical', ['vertical', 'horizontal'], true)) Input::invalid('plot.orientation', 'Choose vertical or horizontal.');
                }
            }
        }
        return $document;
    }

    private function resultConfigurations(int $id): array
    {
        $this->request->assertMethod(['GET', 'PUT']);
        if ($this->request->method === 'PUT') {
            $document = self::validateResultDocument($this->request->json());
            $old = self::resultDocument($this->root, $id);
            foreach (array_diff(array_keys($old['elements']), array_keys($document['elements'])) as $removed) {
                if ($this->db->row('SELECT experimentId FROM `Experiment` WHERE systemId=? AND resultId=? LIMIT 1', [$id, $removed])) throw new HttpError(409, 'configuration_in_use', 'A result configuration used by an experiment cannot be deleted.');
            }
            $this->writeConfiguration($id, 'resultConfigurations.json', $document, 'Updated result configuration');
        }
        $document = self::resultDocument($this->root, $id);
        $document['elements'] = (object) $document['elements'];
        return Context::success($document);
    }

    private function migrateResults(int $id): array
    {
        $this->request->assertMethod(['POST']);
        Input::fields($this->request->json(), []);
        if (!file_exists($this->directory($id) . '/resultConfigurations.json')) {
            $document = self::validateResultDocument(self::resultDocument($this->root, $id));
            $this->writeConfiguration($id, 'resultConfigurations.json', $document, 'Migrated legacy result configuration');
        }
        return Context::success(['migrated' => true, 'legacyFilesRetained' => true]);
    }

    public static function visibleResultConfigurations(array $document, int $experimentId, string $selectedId = ''): array
    {
        $document['elements'] = array_filter($document['elements'], static fn(string $id): bool => str_starts_with($id, 'system-') || str_starts_with($id, 'experiment-' . $experimentId . '-'), ARRAY_FILTER_USE_KEY);
        return $document;
    }

    private function experimentConfigurations(int $experimentId): array
    {
        $this->request->assertMethod(['GET', 'PUT']);
        $experiment = $this->context->experiment($experimentId);
        $systemId = (int) $experiment['systemId'];
        if ($this->request->method === 'PUT') {
            $submitted = self::arrays($this->request->json());
            if (!is_array($submitted['elements'] ?? null)) Input::invalid('elements', 'Submit a configuration document.');
            $prefix = 'experiment-' . $experimentId . '-';
            // Merge inside the file lock so parallel experiments cannot overwrite one another.
            $this->writeConfiguration($systemId, 'resultConfigurations.json', function () use ($submitted, $prefix, $experimentId, $systemId): array {
                $this->context->experiment($experimentId);
                $current = self::resultDocument($this->root, $systemId);
                $owned = ['version' => '1.0', 'elements' => []];
                foreach ($submitted['elements'] as $id => $configuration) {
                    if (str_starts_with((string) $id, $prefix)) $owned['elements'][$id] = $configuration;
                    elseif (!str_starts_with((string) $id, 'system-') || !isset($current['elements'][$id]) || $configuration !== $current['elements'][$id]) throw new HttpError(403, 'configuration_scope', 'Only this experiment’s own result configurations can be modified.');
                }
                $owned = self::validateResultDocument($owned);
                foreach (array_keys($current['elements']) as $id) if (str_starts_with($id, $prefix)) {
                    if (!isset($owned['elements'][$id]) && $this->db->row('SELECT experimentId FROM `Experiment` WHERE systemId=? AND resultId=? LIMIT 1', [$systemId, $id])) throw new HttpError(409, 'configuration_in_use', 'Select a different result configuration before deleting one that is in use.');
                    unset($current['elements'][$id]);
                }
                $current['elements'] += $owned['elements'];
                return $current;
            }, 'Updated experiment result configurations');
        }
        $document = self::visibleResultConfigurations(self::resultDocument($this->root, $systemId), $experimentId);
        $document['elements'] = (object) $document['elements'];
        return Context::success($document);
    }

    private function writeConfiguration(int $id, string $filename, mixed $document, string $message): void
    {
        $path = $this->directory($id);
        Repository::assertSystem($path, $this->root);
        $target = $path . '/' . $filename;
        if (is_link($target)) throw new HttpError(409, 'invalid_configuration_file', 'A configuration must be a regular file.');
        $lock = fopen($path . '/.git/chronos-v3-config.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new HttpError(503, 'configuration_busy', 'The system configuration is busy.');
        $temporary = null;
        try {
            if (is_callable($document)) $document = $document();
            if (is_array($document) && isset($document['elements']) && $filename === 'resultConfigurations.json') $document['elements'] = (object) $document['elements'];
            $data = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (strlen($data) > Request::MAX_BODY_BYTES) Input::invalid('body', 'The configuration is too large.');
            $old = file_exists($target) ? file_get_contents($target) : null;
            if ($old === $data) return;
            $temporary = tempnam($path, '.v3-');
            if ($temporary === false || file_put_contents($temporary, $data) !== strlen($data) || !rename($temporary, $target)) throw new HttpError(503, 'configuration_write_failed', 'The configuration could not be saved.');
            $temporary = null;
            [$code] = Repository::run($path, ['-c', 'user.name=Chronos', '-c', 'user.email=chronos@localhost', 'add', '--', $filename]);
            if ($code === 0) [$code] = Repository::run($path, ['-c', 'user.name=Chronos', '-c', 'user.email=chronos@localhost', 'commit', '--only', '-m', $message, '--', $filename]);
            if ($code !== 0) {
                if ($old === null) unlink($target); else file_put_contents($target, $old, LOCK_EX);
                Repository::run($path, ['reset', '--', $filename]);
                throw new HttpError(503, 'configuration_commit_failed', 'The change could not be recorded in the system history. The previous file was restored.');
            }
            $this->db->transaction(function () use ($id, $message): void {
                $this->db->update('System', 'systemId', $id, ['lastEdit' => date('Y-m-d H:i:s')]);
                $this->context->event('system', $id, $message, $message . '.');
            });
        } finally {
            if ($temporary !== null && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private function detail(int $id, array $system): array
    {
        $this->request->assertMethod(['GET', 'PATCH']);
        if ($this->request->method === 'PATCH') {
            $body = $this->request->json(); Input::fields($body, ['name', 'description', 'ownerId', 'archived', 'cem', 'repository']);
            $changes = [];
            foreach (['name' => 256, 'description' => 65535] as $field => $max) if (array_key_exists($field, $body)) $changes[$field] = Dto::storedText(Input::text($body[$field], $field, $max, $field === 'name'), $field, $max);
            foreach (['archived' => 'isArchived', 'cem' => 'cem'] as $field => $column) if (array_key_exists($field, $body)) $changes[$column] = Input::boolean($body[$field], $field) ? 1 : 0;
            if (array_key_exists('ownerId', $body)) {
                Policy::require(Policy::admin($this->actor));
                $owner = $this->context->user(Input::id($body['ownerId'], 'ownerId'));
                if (!(bool) $owner['alive']) Input::invalid('ownerId', 'Choose an active owner.');
                $changes['userId'] = (int) $owner['userId'];
            }
            if (array_key_exists('repository', $body)) {
                Policy::require($this->remoteEnabled() && Policy::admin($this->actor));
                $repository = self::arrays($body['repository']);
                if (!is_array($repository)) Input::invalid('repository', 'Submit repository settings.');
                Input::fields($repository, ['url', 'branch', 'type', 'username', 'password']);
                foreach (['url' => ['vcsUrl', 256], 'branch' => ['vcsBranch', 50], 'username' => ['vcsUser', 50], 'password' => ['vcsPassword', 256]] as $key => [$column, $max]) if (array_key_exists($key, $repository)) {
                    $changes[$column] = Input::text($repository[$key], 'repository.' . $key, $max, $key === 'branch');
                }
                if (isset($changes['vcsUrl']) && $changes['vcsUrl'] !== '' && (!preg_match('#^(?:https?://|ssh://|git@)[^\s]+$#D', $changes['vcsUrl']) || preg_match('#^https?://[^/]*@#i', $changes['vcsUrl']))) Input::invalid('repository.url', 'Use an HTTP(S) or SSH repository address without embedded credentials.');
                if (isset($repository['type'])) {
                    if (!in_array($repository['type'], ['git', 'hg'], true)) Input::invalid('repository.type', 'Choose Git or Mercurial.');
                    $changes['vcsType'] = $repository['type'];
                }
            }
            if ($changes === []) Input::invalid('body', 'Submit at least one field.');
            $this->db->transaction(function () use ($id, $changes): void {
                $this->context->system($id, true);
                if (($changes['isArchived'] ?? 0) === 1 && $this->db->row('SELECT projectId FROM `Project` WHERE systemId=? AND isArchived=0 LIMIT 1 FOR UPDATE', [$id])) throw new HttpError(409, 'system_in_use', 'Archive current projects before archiving this system.');
                $this->db->update('System', 'systemId', $id, $changes + ['lastEdit' => date('Y-m-d H:i:s')]);
                $this->context->event('system', $id, 'System updated', 'System settings were updated.');
            });
            $system = $this->context->system($id);
        }
        $result = Dto::system($system, $this->actor, $this->root);
        $result['settings'] = $this->settingDocument($id);
        $result['environments'] = array_column($this->db->rows("SELECT item FROM `Setting` WHERE systemId=? AND section='environments' ORDER BY item", [$id]), 'item');
        $result['repository'] = ['url' => preg_replace('#(https?://)[^/]*@#i', '$1[redacted]@', $system['vcsUrl']), 'branch' => $system['vcsBranch'], 'type' => $system['vcsType'], 'username' => $system['vcsUser'], 'hasPassword' => $system['vcsPassword'] !== '', 'remoteEnabled' => $this->remoteEnabled(), 'canEdit' => $this->remoteEnabled() && Policy::admin($this->actor)];
        $result['logoUrl'] = '/api/v3/systems/' . $id . '/logo';
        return Context::success($result);
    }

    private function settingDocument(int $id): array
    {
        $out = ['general' => [], 'setup' => [], 'defaultValues' => []];
        foreach ($this->db->rows("SELECT section,item,value FROM `Setting` WHERE systemId=? AND section IN ('general','setup','defaultValues') ORDER BY settingId", [$id]) as $row) $out[$row['section']][$row['item']] = (string) $row['value'];
        return array_map(static fn(array $values): object => (object) $values, $out);
    }

    private function settings(int $id): array
    {
        $this->request->assertMethod(['GET', 'PUT']);
        if ($this->request->method === 'PUT') {
            $body = self::arrays($this->request->json()); Input::fields($body, ['general', 'setup', 'defaultValues']);
            $values = [];
            foreach ($body as $section => $entries) {
                if (!is_array($entries) || count($entries) > 1000) Input::invalid($section, 'Submit a settings object.');
                foreach ($entries as $key => $value) {
                    Input::text((string) $key, $section, 50);
                    if (!is_string($value) && !is_int($value) && !is_bool($value) && !is_float($value)) Input::invalid($section . '.' . $key, 'Use text, a number or a boolean.');
                    $values[$section][$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                    if (strlen($values[$section][$key]) > 65535 || !mb_check_encoding($values[$section][$key], 'UTF-8')) Input::invalid($section . '.' . $key, 'This setting is too long or invalid.');
                }
            }
            $this->db->transaction(function () use ($id, $values): void {
                $this->context->system($id, true);
                foreach ($values as $section => $entries) {
                    $this->db->execute('DELETE FROM `Setting` WHERE systemId=? AND section=?', [$id, $section]);
                    foreach ($entries as $key => $value) $this->db->insert('Setting', ['systemId' => $id, 'section' => $section, 'item' => $key, 'value' => $value]);
                }
                $this->context->event('system', $id, 'System settings updated', 'Evaluation, setup or default settings were updated.');
            });
        }
        return Context::success($this->settingDocument($id));
    }

    private function environments(int $id): array
    {
        $this->request->assertMethod(['GET', 'PUT']);
        if ($this->request->method === 'PUT') {
            $body = $this->request->json(); Input::fields($body, ['environments']);
            if (!is_array($body['environments'] ?? null) || !array_is_list($body['environments']) || count($body['environments']) > 1000) Input::invalid('environments', 'Submit an environment array.');
            $environments = array_map(static fn(mixed $value): string => Input::text($value, 'environments', 50), $body['environments']);
            if (count($environments) !== count(array_unique($environments))) Input::invalid('environments', 'Environment names must be unique.');
            $this->db->transaction(function () use ($id, $environments): void {
                $this->context->system($id, true);
                $this->db->execute("DELETE FROM `Setting` WHERE systemId=? AND section='environments'", [$id]);
                foreach ($environments as $name) $this->db->insert('Setting', ['systemId' => $id, 'section' => 'environments', 'item' => $name, 'value' => 'unused value']);
                $this->context->event('system', $id, 'Environments updated', 'Available system environments were updated.');
            });
        }
        return Context::success(['environments' => array_column($this->db->rows("SELECT item FROM `Setting` WHERE systemId=? AND section='environments' ORDER BY settingId", [$id]), 'item')]);
    }

    private function logo(int $id): array
    {
        $this->request->assertMethod(['GET', 'PUT']);
        $path = $this->directory($id) . '/logo.png';
        if ($this->request->method === 'PUT') {
            $body = $this->request->json(); Input::fields($body, ['dataUrl']);
            if (!is_string($body['dataUrl'] ?? null) || !preg_match('#^data:image/(png|jpeg|gif);base64,([A-Za-z0-9+/=]+)$#D', $body['dataUrl'], $match)) Input::invalid('dataUrl', 'Choose a PNG, JPEG or GIF image.');
            $bytes = base64_decode($match[2], true);
            $size = $bytes === false ? false : @getimagesizefromstring($bytes);
            if (!$size || strlen($bytes) > 524288 || $size[0] > 4096 || $size[1] > 4096 || !in_array($size['mime'], ['image/png', 'image/jpeg', 'image/gif'], true)) Input::invalid('dataUrl', 'Choose an image of at most 512 KiB and 4096 × 4096 pixels.');
            // Decode and encode to discard active/polyglot payloads and normalize logo.png.
            if (!function_exists('imagecreatefromstring')) throw new HttpError(503, 'image_support_unavailable', 'The PHP GD extension is required to safely update system logos.');
            $image = @imagecreatefromstring($bytes);
            if ($image === false) Input::invalid('dataUrl', 'The image cannot be decoded.');
            ob_start(); imagepng($image); $png = (string) ob_get_clean(); imagedestroy($image);
            $this->writeBinaryConfiguration($id, 'logo.png', $png);
            return Context::success(['logoUrl' => '/api/v3/systems/' . $id . '/logo']);
        }
        if (!is_file($path) || is_link($path) || filesize($path) > 2097152) throw new HttpError(404, 'logo_not_found', 'This system has no logo.');
        $mime = @getimagesize($path)['mime'] ?? 'image/png';
        return $this->binary($mime, null, static function () use ($path): void { readfile($path); });
    }

    private function writeBinaryConfiguration(int $id, string $filename, string $bytes): void
    {
        $path = Repository::assertSystem($this->directory($id), $this->root);
        $target = $path . '/' . $filename;
        if (is_link($target)) throw new HttpError(409, 'invalid_configuration_file', 'A system file must be a regular file.');
        $old = is_file($target) ? file_get_contents($target) : null;
        if ($old === $bytes) return;
        $temp = tempnam($path, '.v3-');
        if (!$temp || file_put_contents($temp, $bytes) !== strlen($bytes) || !rename($temp, $target)) throw new HttpError(503, 'logo_write_failed', 'The image could not be saved.');
        [$code] = Repository::run($path, ['add', '--', $filename]);
        if ($code === 0) [$code] = Repository::run($path, ['-c', 'user.name=Chronos', '-c', 'user.email=chronos@localhost', 'commit', '--only', '-m', 'Updated system logo', '--', $filename]);
        if ($code !== 0) {
            if ($old === null) unlink($target); else file_put_contents($target, $old, LOCK_EX);
            Repository::run($path, ['reset', '--', $filename]);
            throw new HttpError(503, 'configuration_commit_failed', 'The image could not be recorded. The previous file was restored.');
        }
        $this->context->event('system', $id, 'System logo updated', 'The system logo was updated.');
    }

    private function import(int $id): array
    {
        $this->request->assertMethod(['POST']);
        $body = $this->request->json(); Input::fields($body, ['filename', 'content']);
        if (is_string($body['content'] ?? null)) {
            try { $content = json_decode($body['content'], true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { Input::invalid('content', 'Import a valid JSON document.'); }
        } else $content = self::arrays($body['content'] ?? null);
        $filename = $body['filename'] ?? null;
        if ($filename === 'parameters.json') $content = self::validateParameterGroups($content);
        elseif ($filename === 'resultConfigurations.json') {
            $content = self::validateResultDocument($content);
            $old = self::resultDocument($this->root, $id);
            foreach (array_diff(array_keys($old['elements']), array_keys($content['elements'])) as $removed) if ($this->db->row('SELECT experimentId FROM `Experiment` WHERE systemId=? AND resultId=? LIMIT 1', [$id, $removed])) throw new HttpError(409, 'configuration_in_use', 'An imported document cannot remove a configuration used by an experiment.');
        } else Input::invalid('filename', 'Import parameters.json or resultConfigurations.json.');
        $this->writeConfiguration($id, $filename, $content, 'Imported ' . $filename);
        return Context::success(['imported' => $filename]);
    }

    private function export(int $id): array
    {
        $this->request->assertMethod(['GET']);
        $path = $this->directory($id);
        $files = [];
        foreach (['parameters.json', 'resultConfigurations.json', 'results.json', 'resultsJob.json'] as $name) if (is_file($path . '/' . $name) && !is_link($path . '/' . $name) && filesize($path . '/' . $name) <= Request::MAX_BODY_BYTES) $files[$name] = (string) file_get_contents($path . '/' . $name);
        return $this->zip('system' . $id . '.zip', $files);
    }

    private function remoteEnabled(): bool { return defined('ENABLE_REMOTE_REPOSITORY') && ENABLE_REMOTE_REPOSITORY; }

    private function repository(int $id, array $system, string $action): array
    {
        $this->request->assertMethod($action === 'repository/update' ? ['POST'] : ['GET']);
        $path = $this->directory($id);
        if (($system['vcsType'] ?? 'git') !== 'git') throw new HttpError(409, 'repository_adapter_required', 'This Mercurial system requires a v3 repository adapter. Its files and configuration remain available.');
        Repository::assertSystem($path, $this->root);
        if ($action === 'repository/update') {
            Policy::require($this->remoteEnabled());
            $body = $this->request->json(); Input::fields($body, []);
            [$code, $dirty] = Repository::run($path, ['status', '--porcelain']);
            if ($code !== 0 || trim($dirty) !== '') throw new HttpError(409, 'repository_dirty', 'Commit or resolve local system changes before updating.');
            $branch = (string) $system['vcsBranch'];
            [$valid] = Repository::run($path, ['check-ref-format', '--branch', $branch]);
            if ($valid !== 0) Input::invalid('branch', 'The configured branch is invalid.');
            foreach ([['fetch', '--', 'origin', $branch], ['checkout', $branch], ['merge', '--ff-only', 'FETCH_HEAD']] as $arguments) {
                [$code] = Repository::run($path, $arguments);
                if ($code !== 0) throw new HttpError(409, 'repository_update_failed', 'The repository could not be updated. Check its remote access and resolve branch conflicts.');
            }
            $this->context->event('system', $id, 'System repository updated', 'The configured repository branch was updated.');
        }
        [$code, $revision] = Repository::run($path, ['rev-parse', 'HEAD']);
        [, $branches] = Repository::run($path, ['for-each-ref', '--format=%(refname:short)', 'refs/heads']);
        [, $history] = Repository::run($path, ['log', '-100', '--format=%H%x1f%P%x1f%aI%x1f%an%x1f%s']);
        $entries = [];
        foreach (explode("\n", trim($history)) as $line) {
            $parts = explode("\x1f", $line, 5);
            if (count($parts) === 5) $entries[] = ['revision' => $parts[0], 'parents' => array_values(array_filter(explode(' ', $parts[1]))), 'at' => $parts[2], 'author' => $parts[3], 'message' => $parts[4]];
        }
        return Context::success(['revision' => $code === 0 ? trim($revision) : null, 'branches' => array_values(array_filter(explode("\n", trim($branches)))), 'history' => $entries, 'remoteEnabled' => $this->remoteEnabled()]);
    }

    private static function jsonObject(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || $value === '') return [];
        try { $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR); return is_array($decoded) ? $decoded : []; }
        catch (\JsonException) { return []; }
    }

    private function results(array $resource, array $rows, bool $single): array
    {
        Input::fields($this->request->query, ['configurationId']);
        $systemId = (int) $resource['systemId'];
        $document = self::resultDocument($this->root, $systemId);
        if ($single) $resource['resultId'] = $this->context->experiment((int) $resource['experimentId'])['resultId'];
        $document = self::visibleResultConfigurations($document, (int) $resource['experimentId']);
        $configurationId = $this->request->query['configurationId'] ?? $resource['resultId'] ?? '';
        if (!is_string($configurationId)) Input::invalid('configurationId', 'Choose a result configuration.');
        if ($configurationId === '' && count($document['elements']) === 1) $configurationId = (string) array_key_first($document['elements']);
        $configuration = $document['elements'][$configurationId] ?? null;
        if ($configuration === null && $configurationId !== '') throw new HttpError(404, 'configuration_not_found', 'The selected result configuration does not exist.');
        $groups = $raw = []; $finished = 0;
        foreach ($rows as $row) {
            $config = self::jsonObject($row['configuration']);
            $result = self::jsonObject($row['result']);
            $raw[] = ['id' => (int) $row['jobId'], 'internalId' => (int) $row['internalId'], 'label' => Dto::text($row['description']), 'parameters' => (object) ($config['parameters'] ?? []), 'result' => (object) $result, 'status' => (int) $row['status'], 'finishedAt' => Dto::instant($row['finished'] ?? null), 'downloadUrl' => '/api/v3/jobs/' . (int) $row['jobId'] . '/download'];
            if ((int) $row['status'] !== 3) continue;
            ++$finished;
            $job = new \DBA\Job($row['jobId'], $row['userId'], $row['description'], $row['systemId'], $row['environment'], $row['phases'], $row['currentPhase'], $config, $row['status'], $row['progress'], $row['result'], $row['created'], $row['started'], $row['finished'], $row['evaluationId'], $row['internalId'], $row['configurationIdentifier']);
            $parameters = $config['parameters'] ?? []; unset($parameters['run']); ksort($parameters);
            $key = json_encode($parameters, JSON_THROW_ON_ERROR);
            $groups[$key][] = $job;
        }
        $groups = array_values($groups); $charts = []; $warnings = [];
        if (!file_exists($this->directory($systemId) . '/resultConfigurations.json') && $document['elements'] !== []) $warnings[] = 'Legacy result configuration is displayed without modifying system files. A system owner can migrate it explicitly.';
        if ($configuration !== null) {
            if (!$single) foreach ($configuration['all'] ?? [] as $definition) $charts[] = $this->chart($definition, $groups, 'all');
            foreach ($groups as $group) foreach ($configuration['job'] ?? [] as $definition) $charts[] = $this->chart($definition, [$group], 'job', (string) ($group[0]->getConfiguration()['title'] ?? 'Job configuration'));
        }
        return ['configurationId' => $configurationId, 'configurations' => array_map(static fn($id, $entry): array => ['id' => (string) $id, 'name' => Dto::text((string) $entry['name'])], array_keys($document['elements']), array_values($document['elements'])), 'finishedJobs' => $finished, 'totalJobs' => count($rows), 'charts' => $charts, 'jobs' => $raw, 'warnings' => $warnings];
    }

    private function chart(array $definition, array $groups, string $scope, string $label = ''): array
    {
        $ids = []; foreach ($groups as $group) foreach ($group as $job) $ids[] = (int) $job->getId();
        $chart = ['id' => (string) ($definition['id'] ?? 'missing') . ($scope === 'job' ? '-' . ($ids[0] ?? 'empty') : ''), 'title' => Dto::text((string) ($definition['name'] ?? 'Untitled plot')), 'definition' => $definition, 'scope' => $scope, 'groupLabel' => $label, 'jobIds' => $ids, 'data' => ['labels' => [], 'datasets' => []]];
        if (!isset(self::PLOTS[$definition['type'] ?? ''])) { $chart['error'] = 'This custom plot needs a registered v3 renderer. Its definition has been preserved.'; return $chart; }
        if ($groups === []) return $chart;
        try {
            $plot = new \Plot($this->root . '/libraries/graphs/' . self::PLOTS[$definition['type']]);
            $chart['data'] = json_decode($plot->process($groups, $definition), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) { $chart['error'] = 'The plot data could not be calculated. Check the configured metric names and available results.'; }
        return $chart;
    }

    private function binary(string $type, ?string $filename, callable $stream): array
    {
        $headers = ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'];
        if ($filename !== null) $headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"';
        return ['status' => 200, 'headers' => $headers, 'stream' => $stream];
    }

    private function zip(string $filename, array $files): array
    {
        return $this->binary('application/zip', $filename, static function () use ($filename, $files): void {
            $zip = new \Zip_Library($filename);
            foreach ($files as $name => $contents) $zip->addFile($contents, $name);
            $zip->done();
        });
    }

    private function download(array $resource, bool $single): array
    {
        $rows = $single ? [$resource] : $this->db->rows('SELECT jobId,status FROM `Job` WHERE evaluationId=? ORDER BY internalId,jobId', [(int) $resource['evaluationId']]);
        $paths = [];
        foreach ($rows as $row) {
            if ((int) $row['status'] !== 3) throw new HttpError(409, 'results_incomplete', 'All jobs must be finished before downloading their result archives.');
            $base = realpath(UPLOADED_DATA_PATH . 'evaluation');
            $file = UPLOADED_DATA_PATH . 'evaluation/' . (int) $row['jobId'] . '.zip';
            if ($base === false || !is_file($file) || is_link($file) || dirname(realpath($file) ?: '') !== $base) throw new HttpError(404, 'archive_unavailable', 'A result archive is unavailable.');
            $paths[(int) $row['jobId'] . '.zip'] = $file;
        }
        if ($single) return $this->binary('application/zip', 'job' . (int) $resource['jobId'] . '.zip', static function () use ($paths): void { readfile(reset($paths)); });
        return $this->binary('application/zip', 'evaluation' . (int) $resource['evaluationId'] . '.zip', static function () use ($paths, $resource): void {
            $zip = new \Zip_Library('evaluation' . (int) $resource['evaluationId'] . '.zip');
            foreach ($paths as $name => $path) $zip->addFile((string) file_get_contents($path), $name);
            $zip->done();
        });
    }

    private function csv(array $rows, string $filename): array
    {
        $records = []; $columns = ['jobId', 'internalId', 'status', 'finishedAt'];
        foreach ($rows as $row) {
            $record = ['jobId' => (int) $row['jobId'], 'internalId' => (int) $row['internalId'], 'status' => (int) $row['status'], 'finishedAt' => Dto::instant($row['finished'] ?? null)];
            foreach (self::jsonObject($row['configuration'])['parameters'] ?? [] as $key => $value) $record['parameter.' . $key] = $value;
            foreach (self::jsonObject($row['result']) as $key => $value) $record['result.' . $key] = $value;
            $columns = array_values(array_unique([...$columns, ...array_keys($record)])); $records[] = $record;
        }
        return $this->binary('text/csv; charset=utf-8', $filename, static function () use ($records, $columns): void {
            $out = fopen('php://output', 'wb'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ';', '"', '', "\r\n");
            foreach ($records as $record) {
                $cells = [];
                foreach ($columns as $column) {
                    $value = $record[$column] ?? '';
                    if ($column === 'finishedAt' && $value !== '') $value = (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('d.m.Y H:i:s');
                    if (is_array($value) || is_object($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                    elseif (is_bool($value)) $value = $value ? 'true' : 'false';
                    elseif (is_float($value)) $value = str_replace('.', ',', (string) $value);
                    if (is_string($value) && preg_match('/^[\s]*[=+@-]/u', $value)) $value = "'" . $value;
                    $cells[] = $value;
                }
                fputcsv($out, $cells, ';', '"', '', "\r\n");
            }
            fclose($out);
        });
    }
}
