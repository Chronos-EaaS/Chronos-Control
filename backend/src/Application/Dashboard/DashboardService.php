<?php
declare(strict_types=1);

namespace Chronos\Application\Dashboard;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Input;
use Chronos\Infrastructure\Persistence\Store;

final class DashboardService
{
    private readonly Store $db;

    public function __construct(private readonly Context $context)
    {
        $this->db = $context->db;
    }

    public function dashboard(array $query): array
    {
        Input::fields($query, ['all']);
        $all = Input::queryBoolean($query['all'] ?? 'false', 'all');
        [$scope, $args] = $this->context->scope('p', $all);
        // Each relationship is counted once. In particular, joining memberships must not
        // multiply projects, and an evaluation without jobs is still completed.
        $projects = $this->db->row('SELECT COUNT(DISTINCT p.projectId) projects,COUNT(x.experimentId) experiments FROM `Project` p LEFT JOIN `Experiment` x ON x.projectId=p.projectId WHERE ' . $scope, $args);
        $evaluations = $this->db->row('SELECT COUNT(*) evaluations,COALESCE(SUM(t.running),0) running,COALESCE(SUM(t.jobs),0) jobs,COALESCE(SUM(t.activeJobs),0) activeJobs,COALESCE(SUM(t.failedJobs),0) failedJobs FROM ('
            . 'SELECT e.evaluationId,MAX(CASE WHEN j.status IN (-2,0,1,2) THEN 1 ELSE 0 END) running,COUNT(j.jobId) jobs,'
            . 'SUM(CASE WHEN j.status IN (0,1,2) THEN 1 ELSE 0 END) activeJobs,SUM(CASE WHEN j.status=-2 THEN 1 ELSE 0 END) failedJobs'
            . ' FROM `Evaluation` e JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId LEFT JOIN `Job` j ON j.evaluationId=e.evaluationId'
            . ' WHERE ' . $scope . ' GROUP BY e.evaluationId) t', $args);
        $counts = [
            'projects' => (int) $projects['projects'], 'experiments' => (int) $projects['experiments'],
            'evaluationsCompleted' => (int) $evaluations['evaluations'] - (int) $evaluations['running'],
            'evaluationsRunning' => (int) $evaluations['running'], 'jobs' => (int) $evaluations['jobs'],
            'activeJobs' => (int) $evaluations['activeJobs'], 'failedJobs' => (int) $evaluations['failedJobs'],
        ];

        $visible = [
            'project' => $counts['projects'], 'experiment' => $counts['experiments'],
            'evaluation' => (int) $evaluations['evaluations'], 'job' => $counts['jobs'],
        ];
        return ['counts' => $counts, 'events' => (new DashboardTimeline($this->context))->latest($visible, $all)];
    }
}
