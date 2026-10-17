<?php
declare(strict_types=1);

namespace Chronos\Application\Projects;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Input;
use Chronos\Application\Systems\ConfigurationStore;
use Chronos\Infrastructure\Security\Sessions;

/** Authorize and atomically save project-scoped parameter presets. */
final class PresetService
{
    public function __construct(private readonly Context $context, private readonly Sessions $sessions) {}

    public function listing(int $projectId): array
    {
        [$store, $hash] = $this->store($projectId);
        $listing = $store->listing($hash);
        $listing['items'] = array_map(self::dto(...), $listing['items']);
        return $listing;
    }

    public function save(int $projectId, ?string $presetId, array $body): array
    {
        [$store, $hash] = $this->store($projectId);
        return $this->context->db->transaction(function () use ($projectId, $presetId, $body, $store, $hash): array {
            $this->context->project($projectId, true);
            $this->sessions->requireUser();
            return self::dto($store->save($presetId, $body, $hash, (int) $this->context->actor['id']));
        });
    }

    public function delete(int $projectId, string $presetId, array $body): array
    {
        [$store] = $this->store($projectId);
        return $this->context->db->transaction(function () use ($projectId, $presetId, $body, $store): array {
            $this->context->project($projectId, true);
            $this->sessions->requireUser();
            Input::fields($body, ['revision']);
            return $store->delete($presetId, $body['revision'] ?? null);
        });
    }

    private function store(int $projectId): array
    {
        $project = $this->context->project($projectId);
        return [new ParameterPresets($this->context->root, $projectId), ParameterPresets::schemaHash(
            ConfigurationStore::parameterGroups($this->context->root, (int) $project['systemId']),
        )];
    }

    private static function dto(array $item): array
    {
        $item['values'] = (object) $item['values'];
        return $item;
    }
}
