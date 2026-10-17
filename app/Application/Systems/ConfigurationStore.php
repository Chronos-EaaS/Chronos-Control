<?php
declare(strict_types=1);

namespace Chronos\Application\Systems;

use Chronos\Application\Extensions\Extensions;

use Chronos\Application\Shared\Context;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Infrastructure\VersionControl\Repository;
use Chronos\Infrastructure\VersionControl\{RepositoryInspector, RepositoryProcess};

final class ConfigurationStore
{
    public const MAX_BYTES = 1048576;
    private readonly RepositoryInspector $inspector;
    private readonly RepositoryService $repositories;

    public function __construct(private readonly Context $context, private readonly string $root)
    {
        $this->inspector = new RepositoryInspector($root);
        $this->repositories = new RepositoryService($context, $root);
    }

    public static function readJson(string $path, mixed $fallback = []): mixed
    {
        if (!file_exists($path)) return $fallback;
        if (is_link($path) || !is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new HttpError(409, 'invalid_configuration_file', 'The configuration file cannot be read safely.');
        }
        try { return json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpError(409, 'invalid_configuration_file', 'The configuration file contains invalid JSON. Export and repair the file before editing it.'); }
    }

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

    public function writeJson(int $id, string $filename, mixed $document, string $message): void
    {
        $path = $this->inspector->directory($id);
        $type = $this->inspector->adapter($path);
        $target = $path . '/' . $filename;
        if (is_link($target)) throw new HttpError(409, 'invalid_configuration_file', 'A configuration must be a regular file.');
        $lock = fopen($path . '/.' . $type . '/chronos-v3-config.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new HttpError(503, 'configuration_busy', 'The system configuration is busy.');
        $temporary = null;
        try {
            if (is_callable($document)) $document = $document();
            if (is_array($document) && isset($document['elements']) && $filename === 'resultConfigurations.json') $document['elements'] = (object) $document['elements'];
            $data = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (strlen($data) > self::MAX_BYTES) Input::invalid('body', 'The configuration is too large.');
            $old = file_exists($target) ? file_get_contents($target) : null;
            if ($old === $data) { $this->repositories->autoSync($id); return; }
            $this->assertUnstaged($path, $type, $filename);
            $temporary = tempnam($path, '.v3-');
            if ($temporary === false || file_put_contents($temporary, $data) !== strlen($data) || !rename($temporary, $target)) throw new HttpError(503, 'configuration_write_failed', 'The configuration could not be saved.');
            $temporary = null;
            if (!$this->commitFile($path, $type, $filename, $message)) {
                if ($old === null) unlink($target); else file_put_contents($target, $old, LOCK_EX);
                $this->restoreIndex($path, $type, $filename, $old === null);
                throw new HttpError(503, 'configuration_commit_failed', 'The change could not be recorded in the system history. The previous file was restored.');
            }
            $this->context->db->transaction(function () use ($id, $message): void {
                $this->context->db->update('System', 'systemId', $id, ['lastEdit' => date('Y-m-d H:i:s')]);
                $this->context->event('system', $id, $message, $message . '.');
            });
            $this->repositories->autoSync($id);
        } finally {
            if ($temporary !== null && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function writeBinary(int $id, string $filename, string $bytes): void
    {
        $path = $this->inspector->directory($id);
        $type = $this->inspector->adapter($path);
        $target = $path . '/' . $filename;
        if (is_link($target)) throw new HttpError(409, 'invalid_configuration_file', 'A system file must be a regular file.');
        $lock = fopen($path . '/.' . $type . '/chronos-v3-config.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new HttpError(503, 'configuration_busy', 'The system configuration is busy.');
        $temp = null;
        try {
            $old = is_file($target) ? file_get_contents($target) : null;
            if ($old === $bytes) { $this->repositories->autoSync($id); return; }
            $this->assertUnstaged($path, $type, $filename);
            $temp = tempnam($path, '.v3-');
            if (!$temp || file_put_contents($temp, $bytes) !== strlen($bytes) || !rename($temp, $target)) throw new HttpError(503, 'logo_write_failed', 'The image could not be saved.');
            $temp = null;
            if (!$this->commitFile($path, $type, $filename, 'Updated system logo')) {
                if ($old === null) unlink($target); else file_put_contents($target, $old, LOCK_EX);
                $this->restoreIndex($path, $type, $filename, $old === null);
                throw new HttpError(503, 'configuration_commit_failed', 'The image could not be recorded. The previous file was restored.');
            }
            $this->context->event('system', $id, 'System logo updated', 'The system logo was updated.');
            $this->repositories->autoSync($id);
        } finally {
            if ($temp !== null && is_file($temp)) unlink($temp);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private function assertUnstaged(string $path, string $type, string $filename): void
    {
        if ($type !== 'git') return;
        [$code, $staged] = Repository::run($path, ['diff', '--cached', '--name-only', '--', $filename]);
        if ($code !== 0 || trim($staged) !== '') throw new HttpError(409, 'configuration_staged', 'Resolve staged edits to this configuration before saving it through Chronos.');
    }

    private function commitFile(string $path, string $type, string $filename, string $message): bool
    {
        [$code] = RepositoryProcess::run($path, $type, ['add', '--', $filename]);
        if ($code === 0) [$code] = RepositoryProcess::run($path, $type, $type === 'git'
            ? ['-c', 'user.name=Chronos', '-c', 'user.email=chronos@localhost', 'commit', '--only', '-m', $message, '--', $filename]
            : ['commit', '--user', 'Chronos <chronos@localhost>', '--message', $message, '--', $filename]);
        if ($code === 0) return true;
        // Restoring a draft to the already committed bytes needs no new commit.
        [$status, $changes] = RepositoryProcess::run($path, $type, $type === 'git' ? ['status', '--porcelain', '--', $filename] : ['status', '--', $filename]);
        return $status === 0 && trim($changes) === '';
    }

    private function restoreIndex(string $path, string $type, string $filename, bool $created): void
    {
        if ($type === 'git') Repository::run($path, ['reset', '--', $filename]);
        elseif ($created) RepositoryProcess::run($path, 'hg', ['forget', '--', $filename]);
    }

    public function parameters(int $id): array
    {
        $this->context->system($id);
        return ['groups' => self::parameterGroups($this->root, $id)];
    }

    public function saveParameters(int $id, array $body): array
    {
        $this->context->system($id);
        Input::fields($body, ['groups']);
        $groups = ParameterSchema::validate($body['groups'] ?? null, new \Chronos\Application\Extensions\Extensions($this->root, $id));
        $this->writeJson($id, 'parameters.json', $groups, 'Updated experiment parameter schema');
        return $this->parameters($id);
    }

}
