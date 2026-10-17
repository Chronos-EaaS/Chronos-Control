<?php
declare(strict_types=1);

namespace Chronos\Application\Dashboard;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Input;
use Chronos\Application\Security\Policy;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Application\Shared\EventProjection;

final class DashboardService
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

    public function dashboard(array $query): array
    {
        Input::fields($query, ['all']);
        $all = Input::queryBoolean($query['all'] ?? 'false', 'all');
        [$scope, $args] = $this->context->scope('p', $all);
        $scalar = fn(string $sql, array $values = []): int => (int) $this->db->row($sql, $values)['n'];
        $counts = [
            'projects' => $scalar('SELECT COUNT(*) n FROM `Project` p WHERE ' . $scope, $args),
            'experiments' => $scalar('SELECT COUNT(*) n FROM `Experiment` x JOIN `Project` p ON p.projectId=x.projectId WHERE ' . $scope, $args),
            'evaluationsCompleted' => 0, 'evaluationsRunning' => 0, 'jobs' => 0, 'activeJobs' => 0, 'failedJobs' => 0,
        ];
        $evaluationsFrom = ' FROM `Evaluation` e JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId WHERE ' . $scope;
        $running = 'EXISTS (SELECT 1 FROM `Job` j WHERE j.evaluationId=e.evaluationId AND j.status IN (-2,0,1,2))';
        $counts['evaluationsRunning'] = $scalar('SELECT COUNT(*) n' . $evaluationsFrom . ' AND ' . $running, $args);
        $counts['evaluationsCompleted'] = $scalar('SELECT COUNT(*) n' . $evaluationsFrom . ' AND NOT ' . $running, $args);
        $jobsFrom = ' FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId WHERE ' . $scope;
        $counts['jobs'] = $scalar('SELECT COUNT(*) n' . $jobsFrom, $args);
        $counts['activeJobs'] = $scalar('SELECT COUNT(*) n' . $jobsFrom . ' AND j.status IN (0,1,2)', $args);
        $counts['failedJobs'] = $scalar('SELECT COUNT(*) n' . $jobsFrom . ' AND j.status=-2', $args);

        $eventWhere = [];
        $eventArgs = [];
        foreach ([
            'project' => 'FROM `Project` p WHERE p.projectId=v.relatedId',
            'experiment' => 'FROM `Experiment` x JOIN `Project` p ON p.projectId=x.projectId WHERE x.experimentId=v.relatedId',
            'evaluation' => 'FROM `Evaluation` e JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId WHERE e.evaluationId=v.relatedId',
            'job' => 'FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId WHERE j.jobId=v.relatedId',
        ] as $type => $from) {
            $eventWhere[] = "(v.eventType='" . $type . "' AND EXISTS (SELECT 1 " . $from . ' AND ' . $scope . '))';
            array_push($eventArgs, ...$args);
        }
        $eventWhere[] = "(v.eventType='user' AND v.relatedId=?)";
        $eventArgs[] = $this->actor['id'];
        if (Policy::admin($this->actor)) {
            $eventWhere[] = "v.eventType='node'";
            if ($all) {
                $eventWhere[] = "v.eventType='user'";
            }
        }
        $events = $this->db->rows('SELECT v.*,a.firstname actorFirstName,a.lastname actorLastName FROM `Event` v LEFT JOIN `User` a ON a.userId=v.userId WHERE ' . implode(' OR ', $eventWhere) . ' ORDER BY v.time DESC,v.eventId DESC LIMIT 20', $eventArgs);
        return ['counts' => $counts, 'events' => array_map((new EventProjection(new Context($this->db, $this->actor, $this->root)))->event(...), $events)];
    }
}
