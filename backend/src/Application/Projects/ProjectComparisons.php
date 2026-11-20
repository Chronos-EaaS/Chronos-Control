<?php
declare(strict_types=1);

namespace Chronos\Application\Projects;

use Chronos\Application\Shared\{Context, Dto, Input};
use Chronos\Infrastructure\Persistence\DatabaseJson;

final class ProjectComparisons
{
    public function __construct(private readonly Context $context)
    {
    }

    public function list(int $projectId): array
    {
        $this->context->project($projectId);
        return array_map(fn(array $row): array => $this->projection($projectId, $row),
            $this->context->db->rows('SELECT * FROM ProjectComparison WHERE projectId=? ORDER BY comparisonId', [$projectId]));
    }

    public function create(int $projectId, array $body): array
    {
        Input::fields($body, ['name', 'evaluationIds']);
        $name = Input::text($body['name'] ?? null, 'name', 250);
        $values = $body['evaluationIds'] ?? null;
        if (!is_array($values) || !array_is_list($values) || count($values) < 2 || count($values) > 50) {
            Input::invalid('evaluationIds', 'Choose two to fifty evaluations in this project.');
        }
        $ids = array_map(static fn(mixed $id): int => Input::id($id, 'evaluationIds'), $values);
        if (count($ids) !== count(array_unique($ids))) Input::invalid('evaluationIds', 'Choose distinct evaluations.');
        return $this->context->db->transaction(function () use ($projectId, $name, $ids): array {
            $project = $this->context->project($projectId, true);
            if ((bool) $project['isArchived']) Input::invalid('projectId', 'Restore the project before adding comparisons.');
            $evaluations = $this->evaluations($projectId, $ids);
            if (count($evaluations) !== count($ids)) Input::invalid('evaluationIds', 'Choose evaluations in this project.');
            $id = $this->context->db->insert('ProjectComparison', [
                'projectId' => $projectId, 'name' => $name, 'evaluations' => DatabaseJson::encode($ids, 'evaluationIds'),
            ]);
            $this->context->event('project', $projectId, 'Comparison created', 'An evaluation comparison was added.');
            return ['id' => $id, 'name' => $name, 'evaluations' => array_values($evaluations)];
        });
    }

    private function projection(int $projectId, array $row): array
    {
        $ids = json_decode($row['evaluations'], true, 32, JSON_THROW_ON_ERROR);
        return ['id' => (int) $row['comparisonId'], 'name' => $row['name'],
            'evaluations' => array_values($this->evaluations($projectId, $ids))];
    }

    private function evaluations(int $projectId, array $ids): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->context->db->rows('SELECT e.evaluationId,e.name,s.name systemName,x.name experimentName,
            (SELECT COUNT(*) FROM Job j WHERE j.evaluationId=e.evaluationId) planned,
            (SELECT COUNT(*) FROM Job j WHERE j.evaluationId=e.evaluationId AND j.status=3) finished
            FROM Evaluation e JOIN Experiment x ON x.experimentId=e.experimentId JOIN `System` s ON s.systemId=e.systemId
            WHERE x.projectId=? AND e.evaluationId IN (' . $marks . ')', [$projectId, ...$ids]);
        $indexed = array_column($rows, null, 'evaluationId');
        $out = [];
        foreach ($ids as $id) {
            $row = $indexed[$id] ?? null;
            if ($row !== null) $out[$id] = ['id' => $id, 'name' => Dto::text($row['name']),
                'systemName' => Dto::text($row['systemName']), 'experimentName' => Dto::text($row['experimentName']),
                'planned' => (int) $row['planned'], 'finished' => (int) $row['finished']];
        }
        return $out;
    }
}
