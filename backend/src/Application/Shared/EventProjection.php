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

    /** Resolve a timeline's links in bounded queries, without loading job payloads. */
    public function events(array $rows): array
    {
        $requested = [];
        foreach ($rows as $row) {
            $type = $row['eventType'] ?? '';
            $id = (int) ($row['relatedId'] ?? 0);
            $key = $type . ':' . ($row['relatedId'] ?? '');
            if (isset($this->cache[$key]) || $id <= 0 || !in_array($type, ['project', 'experiment', 'evaluation', 'job', 'node'], true)) continue;
            $this->cache[$key] = [];
            // Node events refer to a job in relatedId and to a node in nodeId.
            $requested[$type === 'node' ? 'job' : $type][$id][] = $key;
        }
        [$scope, $scopeArgs] = Policy::admin($this->context->actor) ? ['1=1', []] : $this->context->scope('p');
        foreach ($requested as $type => $ids) {
            [$select, $from, $column] = match ($type) {
                'project' => ['p.projectId,p.name', 'FROM `Project` p', 'p.projectId'],
                'experiment' => ['x.experimentId,x.name,p.projectId,p.name projectName', 'FROM `Experiment` x JOIN `System` s ON s.systemId=x.systemId JOIN `Project` p ON p.projectId=x.projectId', 'x.experimentId'],
                'evaluation' => ['e.evaluationId,e.name,x.experimentId,x.name experimentName,p.projectId,p.name projectName', 'FROM `Evaluation` e JOIN `System` s ON s.systemId=e.systemId JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId', 'e.evaluationId'],
                'job' => ['j.jobId,j.internalId,e.evaluationId,e.name evaluationName,x.experimentId,x.name experimentName,p.projectId,p.name projectName', 'FROM `Job` j JOIN `System` s ON s.systemId=j.systemId JOIN `Evaluation` e ON e.evaluationId=j.evaluationId JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId', 'j.jobId'],
            };
            // Match Context's existence and project access checks, including the
            // project's system, even when called for an administrator's node log.
            $from .= ' JOIN `System` ps ON ps.systemId=p.systemId';
            $entities = $this->context->db->rows('SELECT ' . $select . ' ' . $from . ' WHERE ' . $column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND ' . $scope, [...array_keys($ids), ...$scopeArgs]);
            $idField = $type . 'Id';
            foreach ($entities as $entity) {
                $resources = $this->resources($type, $entity);
                foreach ($ids[(int) $entity[$idField]] as $key) $this->cache[$key] = $resources;
            }
        }
        return array_map($this->event(...), $rows);
    }

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
                    $resources = $this->resources($type, $entity);
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

    private function resources(string $type, array $entity): array
    {
        $resources = [];
        $projectId = (int) ($type === 'project' ? $entity['projectId'] : ($entity['projectId'] ?? 0));
        if ($projectId) $resources[] = ['type' => 'project', 'id' => $projectId, 'label' => Dto::text($type === 'project' ? $entity['name'] : $entity['projectName'])];
        $experimentId = (int) ($type === 'experiment' ? $entity['experimentId'] : ($entity['experimentId'] ?? 0));
        if ($experimentId) $resources[] = ['type' => 'experiment', 'id' => $experimentId, 'label' => Dto::text($type === 'experiment' ? $entity['name'] : $entity['experimentName'])];
        $evaluationId = (int) ($type === 'evaluation' ? $entity['evaluationId'] : ($entity['evaluationId'] ?? 0));
        if ($evaluationId) $resources[] = ['type' => 'evaluation', 'id' => $evaluationId, 'label' => Dto::text($type === 'evaluation' ? $entity['name'] : $entity['evaluationName'])];
        if (in_array($type, ['job', 'node'], true)) $resources[] = ['type' => 'job', 'id' => (int) $entity['jobId'], 'label' => 'Job #' . $entity['internalId']];
        return $resources;
    }
}
