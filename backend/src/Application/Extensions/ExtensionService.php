<?php
declare(strict_types=1);

namespace Chronos\Application\Extensions;

use Chronos\Application\Security\Policy;
use Chronos\Application\Shared\Context;
use Chronos\Http\HttpError;

/** Resolve system membership before loading approved extension descriptors/data. */
final class ExtensionService
{
    public function __construct(private readonly Context $context) {}

    public function read(int $id, ?string $page): array
    {
        $system = $this->context->db->row('SELECT systemId,userId,EXISTS(SELECT 1 FROM `SystemMaintainer` maintainer WHERE maintainer.systemId=s.systemId AND maintainer.userId=?) AS isMaintainer FROM `System` s WHERE systemId=?', [$this->context->actor['id'], $id])
            ?? throw new HttpError(404, 'not_found', 'The system does not exist.');
        $owner = Policy::system($this->context->actor, $system);
        $member = $owner || $this->context->db->row(
            'SELECT p.projectId FROM `Project` p WHERE p.systemId=? AND (p.userId=? OR EXISTS (SELECT 1 FROM `ProjectUser` member WHERE member.projectId=p.projectId AND member.userId=?)) LIMIT 1',
            [$id, $this->context->actor['id'], $this->context->actor['id']],
        ) !== null;
        Policy::require($member);
        $extensions = new Extensions($this->context->root, $id);
        return $page === null ? $extensions->catalog($owner) : $extensions->page($page, $owner, [
            'systemId' => $id, 'userId' => (int) $this->context->actor['id'], 'role' => (int) $this->context->actor['role'],
        ]);
    }
}
