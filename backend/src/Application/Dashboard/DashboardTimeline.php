<?php
declare(strict_types=1);

namespace Chronos\Application\Dashboard;

use Chronos\Application\Security\Policy;
use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\EventProjection;

/** Keep recent activity fast for both broad dashboards and small, older scopes. */
final class DashboardTimeline
{
    private const TARGETED_RESOURCE_LIMIT = 1024;
    private const RELATED_IDS_PER_BRANCH = 64;
    private const RELATIONS = [
        'project' => ['p.projectId', 'FROM `Project` p'],
        'experiment' => ['x.experimentId', 'FROM `Experiment` x JOIN `Project` p ON p.projectId=x.projectId'],
        'evaluation' => ['e.evaluationId', 'FROM `Evaluation` e JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId'],
        'job' => ['j.jobId', 'FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId'],
    ];

    public function __construct(private readonly Context $context) {}

    public function latest(array $visible, bool $all): array
    {
        [$scope, $args] = $this->context->scope('p', $all);
        $rows = array_sum($visible) <= self::TARGETED_RESOURCE_LIMIT
            ? $this->targeted($visible, $scope, $args, $all)
            : null;
        $rows ??= $this->chronological($visible, $scope, $args, $all);
        return (new EventProjection($this->context))->events($rows);
    }

    private function chronological(array $visible, string $scope, array $args, bool $all): array
    {
        $cases = [];
        $values = [];
        foreach (self::RELATIONS as $type => [$column, $from]) {
            if ($visible[$type] === 0) continue;
            $cases[] = "WHEN '" . $type . "' THEN EXISTS (SELECT 1 " . $from . ' WHERE ' . $column . '=v.relatedId AND ' . $scope . ')';
            array_push($values, ...$args);
        }
        $cases[] = "WHEN 'user' THEN " . ($all ? '1' : 'v.relatedId=?');
        if (!$all) $values[] = $this->context->actor['id'];
        if (Policy::admin($this->context->actor)) $cases[] = "WHEN 'node' THEN 1";
        // CASE keeps the mutually exclusive ACL branches from competing with the
        // chronological index through OR/index-merge plans that sort all history.
        return $this->context->db->rows('SELECT v.*,a.firstname actorFirstName,a.lastname actorLastName FROM (SELECT v.* FROM `Event` v WHERE CASE v.eventType ' . implode(' ', $cases) . ' ELSE 0 END ORDER BY v.time DESC,v.eventId DESC LIMIT 20) v LEFT JOIN `User` a ON a.userId=v.userId ORDER BY v.time DESC,v.eventId DESC', $values);
    }

    private function targeted(array $visible, string $scope, array $args, bool $all): ?array
    {
        $selections = [];
        $values = [];
        foreach (self::RELATIONS as $type => [$column, $from]) {
            if ($visible[$type] === 0) continue;
            $selections[] = "SELECT '" . $type . "' resourceType," . $column . ' resourceId ' . $from . ' WHERE ' . $scope;
            array_push($values, ...$args);
        }
        $ids = [];
        if ($selections !== []) {
            $resources = $this->context->db->rows(implode(' UNION ALL ', $selections) . ' LIMIT ' . (self::TARGETED_RESOURCE_LIMIT + 1), $values);
            // Counts and activity need not share a transaction. A concurrent
            // import must not turn this bounded path into an enormous IN list.
            if (count($resources) > self::TARGETED_RESOURCE_LIMIT) return null;
            foreach ($resources as $row) $ids[$row['resourceType']][] = (int) $row['resourceId'];
        }
        $branches = [];
        $values = [];
        foreach ($ids as $type => $resources) {
            // Keep individual relationship lookups selective even when a small
            // number of foreign resources account for almost all stored events.
            foreach (array_chunk($resources, self::RELATED_IDS_PER_BRANCH) as $index => $chunk) {
                [$column, $from] = self::RELATIONS[$type];
                $predicate = "v.eventType='" . $type . "' AND v.relatedId IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')'
                    . ' AND EXISTS (SELECT 1 ' . $from . ' WHERE ' . $column . '=v.relatedId AND ' . $scope . ')';
                // Resource IDs bound the lookup cost; the event statement still
                // checks current ownership, membership and parent existence.
                $branches[] = $this->branch($predicate, $type . '_' . $index);
                array_push($values, ...$chunk, ...$args);
            }
        }
        $branches[] = $this->branch("v.eventType='user'" . ($all ? '' : ' AND v.relatedId=?'), 'user');
        if (!$all) $values[] = $this->context->actor['id'];
        if (Policy::admin($this->context->actor)) $branches[] = $this->branch("v.eventType='node'", 'node');
        // Resource types are disjoint. A row below rank 20 within its own type
        // cannot enter the global top 20, so each branch can stop independently.
        return $this->context->db->rows('SELECT v.*,a.firstname actorFirstName,a.lastname actorLastName FROM (' . implode(' UNION ALL ', $branches) . ') v LEFT JOIN `User` a ON a.userId=v.userId ORDER BY v.time DESC,v.eventId DESC LIMIT 20', $values);
    }

    private function branch(string $predicate, string $type): string
    {
        return 'SELECT * FROM (SELECT v.* FROM `Event` v WHERE ' . $predicate . ' ORDER BY v.time DESC,v.eventId DESC LIMIT 20) recent_' . $type;
    }
}
