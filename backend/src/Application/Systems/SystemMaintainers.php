<?php
declare(strict_types=1);

namespace Chronos\Application\Systems;

use Chronos\Application\Security\Policy;
use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Application\Shared\Input;

/** Maintainers edit systems; the designated owner and administrators manage access. */
final class SystemMaintainers
{
    public function __construct(private readonly Context $context) {}

    public function read(int $id, array $query): array
    {
        $system = $this->context->system($id);
        Input::fields($query, ['candidates']);
        $candidates = Input::queryBoolean($query['candidates'] ?? 'false', 'candidates');
        $manage = Policy::manageSystemMaintainers($this->context->actor, $system);
        if ($candidates) {
            Policy::require($manage);
            $rows = $this->context->db->rows('SELECT u.* FROM `User` u WHERE u.enabled=1'
                . (ONLY_ACTIVATED_USERS ? ' AND u.activated=1' : '')
                . ' AND u.userId<>? AND NOT EXISTS(SELECT 1 FROM `SystemMaintainer` maintainer WHERE maintainer.systemId=? AND maintainer.userId=u.userId) ORDER BY u.lastname,u.firstname,u.userId',
                [(int) $system['userId'], $id]);
        } else {
            $rows = $this->context->db->rows('SELECT u.* FROM `User` u JOIN `SystemMaintainer` maintainer ON maintainer.userId=u.userId WHERE maintainer.systemId=? ORDER BY u.lastname,u.firstname,u.userId', [$id]);
        }
        return [
            'items' => array_map(self::member(...), $rows),
            'ownerId' => (int) $system['userId'],
            'permissions' => ['canManageMaintainers' => $manage, 'canChangeOwner' => $manage],
        ];
    }

    public function add(int $id, array $body): array
    {
        Policy::require(Policy::manageSystemMaintainers($this->context->actor, $this->context->system($id)));
        Input::fields($body, ['userId']);
        $userId = Input::id($body['userId'] ?? null, 'userId');
        return $this->context->db->transaction(function () use ($id, $userId): array {
            $system = $this->context->system($id, true);
            Policy::require(Policy::manageSystemMaintainers($this->context->actor, $system));
            $user = $this->context->eligibleUser($userId, 'userId', true);
            if ($this->context->db->row('SELECT userId FROM `SystemMaintainer` WHERE systemId=? AND userId=? FOR UPDATE', [$id, $userId]) === null) {
                $this->context->db->insert('SystemMaintainer', ['systemId' => $id, 'userId' => $userId]);
                $this->context->event('system', $id, 'Maintainer added', 'A system maintainer was added.');
            }
            return self::member($user);
        });
    }

    public function remove(int $id, int $userId): array
    {
        Policy::require(Policy::manageSystemMaintainers($this->context->actor, $this->context->system($id)));
        return $this->context->db->transaction(function () use ($id, $userId): array {
            $system = $this->context->system($id, true);
            Policy::require(Policy::manageSystemMaintainers($this->context->actor, $system));
            if ((int) $system['userId'] === $userId) {
                Input::invalid('userId', 'The system owner cannot be removed. Choose another owner first.');
            }
            $members = $this->context->db->rows('SELECT userId FROM `SystemMaintainer` WHERE systemId=? FOR UPDATE', [$id]);
            if (!in_array($userId, array_map(static fn(array $member): int => (int) $member['userId'], $members), true)) return ['removed' => false];
            if (count($members) <= 1) Input::invalid('userId', 'A system must retain at least one maintainer.');
            $this->context->db->execute('DELETE FROM `SystemMaintainer` WHERE systemId=? AND userId=?', [$id, $userId]);
            $this->context->event('system', $id, 'Maintainer removed', 'A system maintainer was removed.');
            return ['removed' => true];
        });
    }

    private static function member(array $user): array
    {
        return Dto::member($user) + [
            'enabled' => (bool) $user['enabled'],
            'activated' => (bool) $user['activated'],
            'eligible' => (bool) $user['enabled'] && (!ONLY_ACTIVATED_USERS || (bool) $user['activated']),
        ];
    }
}
