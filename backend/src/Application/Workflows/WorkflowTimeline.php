<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Security\Policy;
use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\EventProjection;
use Chronos\Application\Shared\TextSearch;

/** Select resource activity before loading event bodies, actors and navigation. */
final class WorkflowTimeline
{
    private const RESOURCES = [
        'project' => ['p.projectId', 'FROM `Project` p'],
        'experiment' => ['x.experimentId', 'FROM `Experiment` x JOIN `Project` p ON p.projectId=x.projectId'],
        'evaluation' => ['e.evaluationId', 'FROM `Evaluation` e JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId'],
        'job' => ['j.jobId', 'FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId'],
    ];
    private const ALIASES = ['project' => 'p', 'experiment' => 'x', 'evaluation' => 'e', 'job' => 'j'];

    public function __construct(private readonly Context $context) {}

    public function events(string $type, int $id, array $options): array
    {
        $types = array_keys(self::RESOURCES);
        $start = array_search($type, $types, true);
        if ($start === false) throw new \InvalidArgumentException('Unsupported workflow timeline resource.');
        [$scope, $scopeArgs] = Policy::admin($this->context->actor) ? ['1=1', []] : $this->context->scope('p');
        $search = '';
        $searchArgs = [];
        if ($options['search'] !== '') {
            [$condition, $searchArgs] = TextSearch::condition($options['search'], ['v.title', 'v.eventText']);
            $search = ' AND ' . $condition;
        }
        $counts = $selections = $args = [];
        foreach (array_slice($types, $start) as $eventType) {
            [$column, $from] = self::RESOURCES[$eventType];
            $from .= ' JOIN `System` ps ON ps.systemId=p.systemId';
            if ($type !== 'project') $from .= ' JOIN `System` rs ON rs.systemId=' . self::ALIASES[$type] . '.systemId';
            $from .= ' JOIN `Event` v ON v.relatedId=' . $column . " AND v.eventType='" . $eventType . "'";
            $where = self::RESOURCES[$type][0] . '=? AND ' . $scope . $search;
            $counts[] = 'SELECT COUNT(*) total ' . $from . ' WHERE ' . $where;
            // Event types are disjoint. Joining each hierarchy independently
            // avoids an OR across unrelated resource subqueries and counts each
            // event exactly once, including duplicate project memberships.
            $selections[] = 'SELECT v.eventId,v.time ' . $from . ' WHERE ' . $where;
            array_push($args, $id, ...$scopeArgs, ...$searchArgs);
        }
        $total = (int) $this->context->db->row('SELECT SUM(total) total FROM (' . implode(' UNION ALL ', $counts) . ') event_counts', $args)['total'];
        $page = min($options['page'], max(1, (int) ceil($total / $options['pageSize'])));
        $offset = ($page - 1) * $options['pageSize'];
        // Sort only IDs and timestamps, then hydrate the selected page. The
        // materialized LIMIT keeps actors and potentially large event bodies
        // out of the history scan and preserves the API's ascending ID tie.
        $selected = 'SELECT v.eventId FROM (' . implode(' UNION ALL ', $selections) . ') v ORDER BY ' . $options['order'] . ',v.eventId ASC LIMIT ' . $options['pageSize'] . ' OFFSET ' . $offset;
        $rows = $total === 0 ? [] : $this->context->db->rows('SELECT v.*,u.firstname actorFirstName,u.lastname actorLastName FROM (' . $selected . ') chosen JOIN `Event` v ON v.eventId=chosen.eventId LEFT JOIN `User` u ON u.userId=v.userId ORDER BY ' . $options['order'] . ',v.eventId ASC', $args);
        return ['items' => (new EventProjection($this->context))->events($rows), 'total' => $total, 'page' => $page, 'pageSize' => $options['pageSize']];
    }
}
