<?php
declare(strict_types=1);

namespace Chronos\Application\Systems;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Application\Shared\Input;
use Chronos\Application\Security\Policy;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\VersionControl\Repository;

final class SystemCreation
{
    private readonly Store $db;
    private readonly array $actor;
    private readonly string $root;

    public function __construct(private readonly Context $context)
    {
        $this->db = $context->db;
        $this->actor = $context->actor;
        $this->root = $context->root;
    }

    public function createSystem(array $body): array
    {
        Policy::require(Policy::admin($this->actor));
        Input::fields($body, ['name', 'description', 'ownerId', 'cem', 'repository']);
        $name = Dto::storedText(Input::text($body['name'] ?? null, 'name', 250), 'name', 256);
        $description = Dto::storedText(Input::text($body['description'] ?? '', 'description', 65535, false), 'description', 65535);
        $owner = Input::id($body['ownerId'] ?? null, 'ownerId');
        $this->context->eligibleUser($owner, 'ownerId');
        $cem = isset($body['cem']) ? (int) Input::boolean($body['cem'], 'cem') : 0;
        $repository = null;
        if (array_key_exists('repository', $body)) {
            Policy::require(defined('ENABLE_REMOTE_REPOSITORY') && ENABLE_REMOTE_REPOSITORY);
            $repository = RepositorySettings::creation($body['repository']);
        }
        $createdPath = null;
        try {
            $id = $this->db->transaction(function () use ($name, $description, $owner, $cem, $repository, &$createdPath): int {
                $now = date('Y-m-d H:i:s');
                $id = $this->db->insert('System', ['name' => $name, 'description' => $description, 'userId' => $owner, ...($repository ?? ['vcsUrl' => '', 'vcsBranch' => 'master', 'vcsType' => 'git', 'vcsUser' => '', 'vcsPassword' => '']), 'created' => $now, 'lastEdit' => $now, 'isArchived' => 0, 'cem' => $cem]);
                $base = $this->root . '/webroot/systems';
                if (!is_dir($base) && !mkdir($base, 0770, true)) {
                    throw new \RuntimeException('System storage is unavailable.');
                }
                $path = $base . '/' . $id;
                if (file_exists($path) || is_link($path) || !mkdir($path, 0770)) {
                    throw new \RuntimeException('System storage cannot be created.');
                }
                $createdPath = $path;
                if ($repository !== null) (new RepositoryService($this->context, $this->root))->initializeRemote($id, $repository);
                $initializedFiles = [];
                foreach ([
                    'config.json' => ['name' => Dto::text($name), 'identifier' => 'system-' . bin2hex(random_bytes(12))],
                    'parameters.json' => [], 'resultConfigurations.json' => ['version' => '1.0', 'elements' => new \stdClass()],
                ] as $file => $data) {
                    if ($repository !== null && (is_file($path . '/' . $file) || $file === 'resultConfigurations.json' && (is_file($path . '/results.json') || is_file($path . '/resultsJob.json')))) continue;
                    if (file_put_contents($path . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                        throw new \RuntimeException('System configuration cannot be written.');
                    }
                    $initializedFiles[] = $file;
                }
                if (!is_file($path . '/logo.png')) {
                    if (!copy($this->root . '/webroot/images/active.png', $path . '/logo.png')) throw new \RuntimeException('System logo cannot be initialized.');
                    $initializedFiles[] = 'logo.png';
                }
                if ($repository === null) Repository::initialize($path, $this->root);
                // Local defaults need a clean first revision too: a repository
                // can be connected later without untracked files blocking sync.
                (new RepositoryService($this->context, $this->root))->recordInitialConfiguration($id, $initializedFiles);
                foreach ([['environments', 'default', 'unused value'], ['defaultValues', 'environment', 'system-default'], ['defaultValues', 'phase_warmUp', '1']] as [$section, $item, $value]) {
                    $this->db->insert('Setting', ['section' => $section, 'item' => $item, 'value' => $value, 'systemId' => $id]);
                }
                return $id;
            });
        } catch (\Throwable $error) {
            if ($createdPath !== null) {
                foreach (['config.json', 'parameters.json', 'resultConfigurations.json', 'logo.png'] as $file) {
                    if (is_file($createdPath . '/' . $file)) {
                        unlink($createdPath . '/' . $file);
                    }
                }
                // Only the newly created system directory belongs to this
                // failed unit of work, including its newly initialized .git.
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($createdPath, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $entry) {
                    $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
                rmdir($createdPath);
            }
            throw $error;
        }
        return Dto::system($this->context->system($id), $this->actor, $this->root);
    }
}
