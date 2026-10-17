<?php
declare(strict_types=1);

namespace Chronos\Application\Systems;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
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
        $where = $options['all'] ? '1=1' : 's.userId=?';
        $args = $options['all'] ? [] : [$this->actor['id']];
        if ($options['archived'] !== 'all') {
            $where .= ' AND s.isArchived=?';
            $args[] = $options['archived'] === 'true' ? 1 : 0;
        }
        if ($options['search'] !== '') {
            $where .= " AND (s.name LIKE ? ESCAPE '=' OR s.description LIKE ? ESCAPE '=' OR CONCAT_WS(' ',owner.firstname,owner.lastname) LIKE ? ESCAPE '=')";
            array_push($args, ...array_fill(0, 3, Context::search($options['search'])));
        }
        return $this->context->page('FROM `System` s LEFT JOIN `User` owner ON owner.userId=s.userId', $where, $args, $options, "s.systemId,s.name,s.description,s.userId,s.created,s.lastEdit,s.isArchived,s.cem,CONCAT_WS(' ',owner.firstname,owner.lastname) AS ownerName", fn(array $row): array => Dto::system($row, $this->actor, $this->root), 's.systemId');
    }
}
