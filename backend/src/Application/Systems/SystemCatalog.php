<?php
declare(strict_types=1);

namespace Chronos\Application\Systems;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Application\Shared\TextSearch;
use Chronos\Application\Security\Policy;
use Chronos\Infrastructure\Persistence\Store;

final class SystemCatalog
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

    public function systems(array $query): array
    {
        $options = $this->context->options($query, ['id' => 's.systemId', 'name' => 's.name', 'description' => 's.description', 'owner' => "CONCAT_WS(' ',owner.firstname,owner.lastname)"]);
        if ($options['all']) {
            Policy::require(Policy::admin($this->actor));
        }
        $membership = 'EXISTS(SELECT 1 FROM `SystemMaintainer` maintainer WHERE maintainer.systemId=s.systemId AND maintainer.userId=?)';
        $where = $options['all'] ? '1=1' : '(s.userId=? OR ' . $membership . ')';
        $args = $options['all'] ? [] : [$this->actor['id'], $this->actor['id']];
        if ($options['archived'] !== 'all') {
            $where .= ' AND s.isArchived=?';
            $args[] = $options['archived'] === 'true' ? 1 : 0;
        }
        if ($options['search'] !== '') {
            [$clause, $patterns] = TextSearch::condition($options['search'], ['s.name', 's.description', "CONCAT_WS(' ',owner.firstname,owner.lastname)"]);
            $where .= ' AND ' . $clause; array_push($args, ...$patterns);
        }
        // The same scoped predicate implies membership for every non-admin
        // result. Keep permissions actor-specific without per-row SQL reads.
        $select = "s.systemId,s.name,s.description,s.userId,s.created,s.lastEdit,s.isArchived,s.cem,CONCAT_WS(' ',owner.firstname,owner.lastname) AS ownerName";
        return $this->context->page('FROM `System` s LEFT JOIN `User` owner ON owner.userId=s.userId', $where, $args, $options, $select, fn(array $row): array => Dto::system($row + ['isMaintainer' => !$options['all']], $this->actor, $this->root), 's.systemId');
    }
}
