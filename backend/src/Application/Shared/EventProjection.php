<?php
declare(strict_types=1);
namespace Chronos\Application\Shared;

use Chronos\Http\HttpError;
use Chronos\Application\Security\Policy;

/** Enrich only events already selected within an authorized resource scope. */
final class EventProjection
{
    private array $cache = [];
    public function __construct(private readonly Context $context) {}

    public function event(array $row): array
    {
        $event = Dto::event($row);
        $key = ($row['eventType'] ?? '') . ':' . ($row['relatedId'] ?? '');
        if (!isset($this->cache[$key])) {
            $resources = [];
            $id = (int) ($row['relatedId'] ?? 0);
            $type = $row['eventType'] ?? '';
            try {
                if ($id > 0 && in_array($type, ['project', 'experiment', 'evaluation', 'job', 'node'], true)) {
                    $entity = match ($type) {
                        'project' => $this->context->project($id),
                        'experiment' => $this->context->experiment($id),
                        'evaluation' => $this->context->evaluation($id),
                        default => $this->context->job($id),
                    };
                    $projectId = (int) ($type === 'project' ? $entity['projectId'] : ($entity['projectId'] ?? 0));
                    if ($projectId) $resources[] = ['type' => 'project', 'id' => $projectId, 'label' => Dto::text($type === 'project' ? $entity['name'] : $entity['projectName'])];
                    $experimentId = (int) ($type === 'experiment' ? $entity['experimentId'] : ($entity['experimentId'] ?? 0));
                    if ($experimentId) $resources[] = ['type' => 'experiment', 'id' => $experimentId, 'label' => Dto::text($type === 'experiment' ? $entity['name'] : $entity['experimentName'])];
                    $evaluationId = (int) ($type === 'evaluation' ? $entity['evaluationId'] : ($entity['evaluationId'] ?? 0));
                    if ($evaluationId) $resources[] = ['type' => 'evaluation', 'id' => $evaluationId, 'label' => Dto::text($type === 'evaluation' ? $entity['name'] : $entity['evaluationName'])];
                    if (in_array($type, ['job', 'node'], true)) $resources[] = ['type' => 'job', 'id' => (int) $entity['jobId'], 'label' => 'Job #' . $entity['internalId']];
                }
            } catch (HttpError $error) {
                if (!in_array($error->status, [403, 404], true)) throw $error;
            }
            $this->cache[$key] = $resources;
        }
        $resources = $this->cache[$key];
        if (!empty($row['nodeId']) && Policy::admin($this->context->actor)) $resources[] = ['type' => 'node', 'id' => $row['nodeId'], 'label' => 'Node ' . $row['nodeId']];
        return $event + ['resources' => $resources];
    }
}
