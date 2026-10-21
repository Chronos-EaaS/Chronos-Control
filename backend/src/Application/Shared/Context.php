<?php
declare(strict_types=1);

namespace Chronos\Application\Shared;

use Chronos\Http\HttpError;
use Chronos\Application\Security\Policy;
use Chronos\Infrastructure\Persistence\Store;

/** Shared authorization and transaction helpers for browser resource modules. */
final class Context
{
    public function __construct(public readonly Store $db, public readonly array $actor, public readonly string $root)
    {
    }

    public function user(int $id, bool $lock = false): array
    {
        $row = $this->db->row('SELECT * FROM `User` WHERE userId=?' . ($lock ? ' FOR UPDATE' : ''), [$id])
            ?? throw new HttpError(404, 'not_found', 'The user does not exist.');
        return $row;
    }

    public function eligibleUser(int $id, string $field = 'userId', bool $lock = false): array
    {
        $user = $this->user($id, $lock);
        if (!(bool) $user['enabled'] || (ONLY_ACTIVATED_USERS && !(bool) $user['activated'])) {
            Input::invalid($field, 'Choose an enabled eligible account.');
        }
        return $user;
    }

    public function system(int $id, bool $lock = false): array
    {
        $row = $this->db->row("SELECT s.*,CONCAT_WS(' ',owner.firstname,owner.lastname) AS ownerName,EXISTS(SELECT 1 FROM `SystemMaintainer` maintainer WHERE maintainer.systemId=s.systemId AND maintainer.userId=?) AS isMaintainer FROM `System` s LEFT JOIN `User` owner ON owner.userId=s.userId WHERE s.systemId=?" . ($lock ? ' FOR UPDATE' : ''), [$this->actor['id'], $id])
            ?? throw new HttpError(404, 'not_found', 'The system does not exist.');
        if ($lock) {
            // Access changes serialize on the system row. Read membership only
            // after acquiring it, using a current read under REPEATABLE READ.
            $row['isMaintainer'] = $this->db->row('SELECT userId FROM `SystemMaintainer` WHERE systemId=? AND userId=? FOR UPDATE', [$id, $this->actor['id']]) !== null;
        }
        Policy::require(Policy::system($this->actor, $row));
        return $row;
    }

    public function project(int $id, bool $lock = false): array
    {
        $row = $this->db->row("SELECT p.*,s.name AS systemName,CONCAT_WS(' ',owner.firstname,owner.lastname) AS ownerName FROM `Project` p JOIN `System` s ON s.systemId=p.systemId LEFT JOIN `User` owner ON owner.userId=p.userId WHERE p.projectId=?" . ($lock ? ' FOR UPDATE' : ''), [$id])
            ?? throw new HttpError(404, 'not_found', 'The project does not exist.');
        $member = $this->db->row('SELECT projectUserId FROM `ProjectUser` WHERE projectId=? AND userId=? LIMIT 1', [$id, $this->actor['id']]) !== null;
        Policy::require(Policy::project($this->actor, $row, $member));
        return $row;
    }

    public function experiment(int $id, bool $lock = false): array
    {
        $row = $this->db->row('SELECT x.*,p.name AS projectName,s.name AS systemName FROM `Experiment` x JOIN `Project` p ON p.projectId=x.projectId JOIN `System` s ON s.systemId=x.systemId WHERE x.experimentId=?' . ($lock ? ' FOR UPDATE' : ''), [$id])
            ?? throw new HttpError(404, 'not_found', 'The experiment does not exist.');
        $this->project((int) $row['projectId'], $lock);
        return $row;
    }

    public function evaluation(int $id, bool $lock = false): array
    {
        $row = $this->db->row('SELECT e.*,x.projectId,x.internalId AS experimentInternalId,x.name AS experimentName,x.resultId,p.name AS projectName,s.name AS systemName FROM `Evaluation` e JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId JOIN `System` s ON s.systemId=e.systemId WHERE e.evaluationId=?' . ($lock ? ' FOR UPDATE' : ''), [$id])
            ?? throw new HttpError(404, 'not_found', 'The evaluation does not exist.');
        $this->project((int) $row['projectId'], $lock);
        return $row;
    }

    public function job(int $id, bool $lock = false): array
    {
        $row = $this->db->row('SELECT j.*,e.internalId AS evaluationInternalId,e.name AS evaluationName,e.experimentId,x.internalId AS experimentInternalId,x.name AS experimentName,x.projectId,p.name AS projectName,s.name AS systemName FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId JOIN `System` s ON s.systemId=j.systemId WHERE j.jobId=?' . ($lock ? ' FOR UPDATE' : ''), [$id])
            ?? throw new HttpError(404, 'not_found', 'The job does not exist.');
        $this->project((int) $row['projectId'], $lock);
        return $row;
    }

    public function scope(string $alias, bool $all = false): array
    {
        if ($all) {
            Policy::require(Policy::admin($this->actor));
            return ['1=1', []];
        }
        return ['(' . $alias . '.userId=? OR EXISTS (SELECT 1 FROM `ProjectUser` member WHERE member.projectId=' . $alias . '.projectId AND member.userId=?))', [$this->actor['id'], $this->actor['id']]];
    }

    public function options(array $query, array $sorts, string $defaultSort = 'id', bool $allowAllRows = false): array
    {
        if (!array_key_exists('pageSize', $query)) {
            $setting = $this->db->row("SELECT value FROM `Setting` WHERE systemId=0 AND section='other' AND item='rowsPerPage' LIMIT 1");
            $configured = $setting !== null ? filter_var($setting['value'], FILTER_VALIDATE_INT) : false;
            $query['pageSize'] = (string) ($configured !== false && $configured > 0 ? min($configured, 100) : 20);
        }
        return Input::listOptions($query, $sorts, $defaultSort, $allowAllRows);
    }

    public function page(string $from, string $where, array $args, array $options, string $select, callable $map, string $tie): array
    {
        $selection = 'SELECT ' . $select . ' ' . $from . ' WHERE ' . $where . ' ORDER BY ' . $options['order'] . ',' . $tie . ' ASC';
        if ($options['pageSize'] === null) {
            $items = array_map($map, $this->db->rows($selection, $args));
            $total = count($items);
            return ['items' => $items, 'total' => $total, 'page' => 1, 'pageSize' => max(1, $total)];
        }
        $total = (int) $this->db->row('SELECT COUNT(*) AS total ' . $from . ' WHERE ' . $where, $args)['total'];
        $lastPage = max(1, (int) ceil($total / $options['pageSize']));
        $page = min($options['page'], $lastPage);
        $offset = ($page - 1) * $options['pageSize'];
        $rows = $this->db->rows($selection . ' LIMIT ' . $options['pageSize'] . ' OFFSET ' . $offset, $args);
        return ['items' => array_map($map, $rows), 'total' => $total, 'page' => $page, 'pageSize' => $options['pageSize']];
    }

    public static function search(string $text): string
    {
        return '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $text) . '%';
    }

    public function event(string $type, int $id, string $title, string $text): void
    {
        $this->db->insert('Event', ['title' => $title, 'time' => date('Y-m-d H:i:s'), 'eventText' => $text, 'eventType' => $type, 'relatedId' => $id, 'userId' => $this->actor['id'], 'nodeId' => null]);
    }

    public function setting(int $systemId, string $section, string $item, string $fallback = ''): string
    {
        $row = $this->db->row('SELECT value FROM `Setting` WHERE systemId=? AND section=? AND item=? LIMIT 1', [$systemId, $section, $item]);
        return $row === null ? $fallback : (string) $row['value'];
    }
}
