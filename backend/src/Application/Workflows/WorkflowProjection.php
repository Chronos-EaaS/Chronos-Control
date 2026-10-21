<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Domain\Workflows\Environment;
use Chronos\Infrastructure\Persistence\Store;

/** Application operations independent of HTTP requests and routing. */
final class WorkflowProjection
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

    public function experiment(array $row): array
    {
        return [
            'id' => (int) $row['experimentId'], 'name' => Dto::text($row['name']), 'description' => Dto::text($row['description']),
            'projectId' => (int) $row['projectId'], 'projectName' => Dto::text($row['projectName'] ?? ''),
            'systemId' => (int) $row['systemId'], 'systemName' => Dto::text($row['systemName'] ?? ''),
            'internalId' => (int) $row['internalId'], 'archived' => (bool) $row['isArchived'], 'createdAt' => Dto::instant($row['created']),
            'resultId' => $row['resultId'], 'defaultEnvironment' => $row['defaultEnvironment'], 'phases' => (int) $row['phases'], 'status' => (int) $row['status'],
            'permissions' => ['canEdit' => true, 'canArchive' => true, 'canRun' => !(bool) $row['isArchived'], 'canCopy' => true],
        ];
    }

    public function counts(int $id): array
    {
        $row = $this->db->row('SELECT ' . self::countSelection() . ' FROM `Job` WHERE evaluationId=?', [$id]);
        return array_map(static fn(mixed $value): int => (int) $value, $row);
    }

    private static function countSelection(): string
    {
        return 'COUNT(*) total,COALESCE(SUM(status=3),0) finished,COALESCE(SUM(status=-2),0) failed,COALESCE(SUM(status IN (0,1,2)),0) active,COALESCE(SUM(status=-1),0) aborted,COALESCE(ROUND(AVG(' . JobProgressSql::overall() . ')),0) progress';
    }

    public function evaluation(array $row): array
    {
        return $this->evaluations([$row])[0];
    }

    /** A list page needs two shared aggregates, rather than two queries per evaluation. */
    public function evaluations(array $rows): array
    {
        if ($rows === []) return [];
        $ids = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['evaluationId'], $rows)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $counts = [];
        foreach ($this->db->rows('SELECT evaluationId,' . self::countSelection() . ' FROM `Job` WHERE evaluationId IN (' . $placeholders . ') GROUP BY evaluationId', $ids) as $summary) {
            $id = (int) $summary['evaluationId']; unset($summary['evaluationId']);
            $counts[$id] = array_map(static fn(mixed $value): int => (int) $value, $summary);
        }
        $environments = [];
        foreach ($this->db->rows('SELECT DISTINCT evaluationId,environment FROM `Job` WHERE evaluationId IN (' . $placeholders . ') ORDER BY evaluationId,environment', $ids) as $environment) {
            $environments[(int) $environment['evaluationId']][] = Dto::text($environment['environment']);
        }
        $empty = ['total' => 0, 'finished' => 0, 'failed' => 0, 'active' => 0, 'aborted' => 0, 'progress' => 0];
        return array_map(fn(array $row): array => $this->evaluationSummary($row, $counts[(int) $row['evaluationId']] ?? $empty, $environments[(int) $row['evaluationId']] ?? []), $rows);
    }

    private function evaluationSummary(array $row, array $counts, array $environments): array
    {
        $progress = $counts['progress'];
        unset($counts['progress']);
        return [
            'id' => (int) $row['evaluationId'], 'name' => Dto::text($row['name']), 'description' => Dto::text($row['description']),
            'experimentId' => (int) $row['experimentId'], 'experimentInternalId' => (int) ($row['experimentInternalId'] ?? 0), 'experimentName' => Dto::text($row['experimentName'] ?? ''),
            'projectId' => (int) $row['projectId'], 'projectName' => Dto::text($row['projectName'] ?? ''),
            'systemId' => (int) $row['systemId'], 'systemName' => Dto::text($row['systemName'] ?? ''),
            'internalId' => (int) $row['internalId'], 'archived' => (bool) $row['isArchived'], 'starred' => (bool) $row['isStarred'],
            'resultId' => $row['resultId'], 'counts' => $counts, 'progress' => $progress, 'environment' => implode(', ', $environments), 'environments' => $environments,
            'permissions' => ['canEdit' => true, 'canArchive' => $counts['active'] + $counts['failed'] === 0, 'canAbort' => $counts['active'] + $counts['failed'] > 0, 'canReschedule' => $counts['aborted'] + $counts['failed'] > 0, 'canDownload' => $counts['total'] > 0 && $counts['finished'] === $counts['total']],
        ];
    }

    public function job(array $row): array
    {
        $node=$this->db->row('SELECT nodeId,hostname,lastUpdate FROM `Node` WHERE currentJob=? ORDER BY lastUpdate DESC LIMIT 1',[(int)$row['jobId']]);
        $owner=$this->context->user((int)$row['userId']);
        [, $cem] = Environment::parse($row['environment']);
        $previous=$this->db->row('SELECT jobId FROM `Job` WHERE evaluationId=? AND (internalId<? OR (internalId=? AND jobId<?)) ORDER BY internalId DESC,jobId DESC LIMIT 1',[$row['evaluationId'],$row['internalId'],$row['internalId'],$row['jobId']]);
        $next=$this->db->row('SELECT jobId FROM `Job` WHERE evaluationId=? AND (internalId>? OR (internalId=? AND jobId>?)) ORDER BY internalId,jobId LIMIT 1',[$row['evaluationId'],$row['internalId'],$row['internalId'],$row['jobId']]);
        $lastContact=$node ? Dto::instant($node['lastUpdate']) : null;
        $clientStatus=$node ? (strtotime($node['lastUpdate']) < time()-max(1,(int)$this->context->setting(0,'cem','nodeMissingTimeout','300')) ? 'missing' : 'connected') : 'unassigned';
        return [
            'owner' => array_intersect_key(Dto::user($owner),array_flip(['id','username','firstName','lastName'])), 'cem'=>$cem, 'setupRequired'=>$cem,
            'previousId'=>$previous?(int)$previous['jobId']:null,'nextId'=>$next?(int)$next['jobId']:null,
            'id' => (int) $row['jobId'], 'internalId' => (int) $row['internalId'], 'description' => Dto::text($row['description']),
            'evaluationId' => (int) $row['evaluationId'], 'evaluationInternalId' => (int) ($row['evaluationInternalId'] ?? 0), 'evaluationName' => Dto::text($row['evaluationName'] ?? ''),
            'experimentId' => (int) $row['experimentId'], 'experimentInternalId' => (int) ($row['experimentInternalId'] ?? 0), 'experimentName' => Dto::text($row['experimentName'] ?? ''),
            'projectId' => (int) $row['projectId'], 'projectName' => Dto::text($row['projectName'] ?? ''),
            'systemId' => (int) $row['systemId'], 'systemName' => Dto::text($row['systemName'] ?? ''),
            'environment' => $row['environment'], 'client' => $node ? ($node['hostname'] ?: $node['nodeId']) : '', 'clientStatus' => $clientStatus, 'lastContactAt' => $lastContact, 'status' => (int) $row['status'],
            'statusName' => [-2 => 'failed', -1 => 'aborted', 0 => 'scheduled', 1 => 'setup', 2 => 'running', 3 => 'finished'][(int) $row['status']] ?? 'unknown',
            'progress' => (int) $row['progress'], 'phases' => (int) $row['phases'], 'currentPhase' => $row['currentPhase'] === null ? null : (int) $row['currentPhase'],
            'configuration' => json_decode($row['configuration'], true) ?? [], 'createdAt' => Dto::instant($row['created']), 'startedAt' => Dto::instant($row['started']), 'finishedAt' => Dto::instant($row['finished']),
            'permissions' => ['canEdit' => true, 'canAbort' => in_array((int) $row['status'], [-2,0,1,2], true), 'canReschedule' => true, 'canDownload' => (int) $row['status'] === 3 && is_file($this->root . '/webroot/data/evaluation/' . (int) $row['jobId'] . '.zip')],
        ];
    }

    /** Table rows deliberately exclude configuration, results and detail-only lookups. */
    public function jobSummary(array $row): array
    {
        [, $cem] = Environment::parse($row['environment']);
        return [
            'id' => (int) $row['jobId'], 'internalId' => (int) $row['internalId'], 'description' => Dto::text($row['description']),
            'evaluationId' => (int) $row['evaluationId'], 'status' => (int) $row['status'],
            'statusName' => [-2 => 'failed', -1 => 'aborted', 0 => 'scheduled', 1 => 'setup', 2 => 'running', 3 => 'finished'][(int) $row['status']] ?? 'unknown',
            'progress' => (int) $row['progress'], 'phases' => (int) $row['phases'], 'currentPhase' => $row['currentPhase'] === null ? null : (int) $row['currentPhase'],
            'setupRequired' => $cem,
            'permissions' => ['canDownload' => (int) $row['status'] === 3 && is_file($this->root . '/webroot/data/evaluation/' . (int) $row['jobId'] . '.zip')],
        ];
    }

    public function events(string $type, int $id, array $options): array
    {
        return (new WorkflowTimeline($this->context))->events($type, $id, $options);
    }

    public function status(bool $all): array
    {
        [$scope, $args] = $this->context->scope('p', $all);
        $rows=$this->db->rows('SELECT j.*,e.internalId evaluationInternalId,e.name evaluationName,e.experimentId,x.internalId experimentInternalId,x.projectId,x.name experimentName,p.name projectName,s.name systemName FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId JOIN `System` s ON s.systemId=j.systemId WHERE '.$scope.' AND j.status IN (-2,0,1,2) ORDER BY j.jobId DESC LIMIT 500',$args);
        $counts=['scheduled'=>0,'setup'=>0,'running'=>0,'failed'=>0];foreach($rows as$row){$name=[-2=>'failed',0=>'scheduled',1=>'setup',2=>'running'][(int)$row['status']];$counts[$name]++;}
        return ['counts'=>$counts,'jobs'=>array_map(fn(array$row):array=>$this->job($row),$rows),'limit'=>500,'at'=>date(DATE_ATOM)];
    }
}
