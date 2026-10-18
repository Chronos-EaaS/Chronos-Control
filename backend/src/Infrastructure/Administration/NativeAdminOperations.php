<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Administration;

use Chronos\Infrastructure\Deployment\ReleaseManager;
use Closure;
use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

use Chronos\Application\Shared\Dto;
use Chronos\Http\HttpError;
use Chronos\Application\Projects\ParameterPresets;
use Chronos\Infrastructure\VersionControl\Repository;
use Chronos\Infrastructure\Security\SessionEpoch;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Application\Maintenance\AdminOperationExecutor;

final class NativeAdminOperations implements AdminOperationExecutor
{
    private const TABLES = ['Evaluation', 'Event', 'Experiment', 'Job', 'Project', 'ProjectUser', 'Session', 'Setting', 'System', 'User', 'Node'];
    private readonly Closure $command;
    private readonly Closure $repository;

    public function __construct(private readonly Store $db, private readonly string $root, ?callable $command = null, ?callable $repository = null)
    {
        $this->command = $command === null ? self::runCommand(...) : Closure::fromCallable($command);
        $this->repository = $repository === null ? Repository::run(...) : Closure::fromCallable($repository);
    }

    private function enabled(string $flag): bool
    {
        return defined($flag) && constant($flag) === true;
    }

    private function releases(): bool
    {
        return class_exists(ReleaseManager::class);
    }

    public function capabilities(): array
    {
        $release = $this->releases() ? ReleaseManager::inspect($this->root) : [];
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
                    $plan['release'] = ReleaseManager::plan($this->root, $action, $parameters);
                } catch (RuntimeException $error) {
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
            $result = ReleaseManager::execute($this->root, $plan['release']);
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
        } catch (JsonException) {
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
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
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
        } catch (Throwable $error) {
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
