<?php
declare(strict_types=1);

namespace Chronos\Application\Security;

use Chronos\Http\HttpError;

final class Policy
{
    public static function admin(array $actor): bool
    {
        return in_array($actor['role'], [1, 2], true);
    }

    public static function superAdmin(array $actor): bool
    {
        return $actor['role'] === 2;
    }

    public static function system(array $actor, array $system): bool
    {
        // isMaintainer is a fresh database annotation, never a request field.
        return self::manageSystemMaintainers($actor, $system) || (bool) ($system['isMaintainer'] ?? false);
    }

    public static function manageSystemMaintainers(array $actor, array $system): bool
    {
        return self::admin($actor) || $actor['id'] === (int) $system['userId'];
    }

    public static function project(array $actor, array $project, bool $member): bool
    {
        return self::manageProject($actor, $project) || $member;
    }

    public static function manageProject(array $actor, array $project): bool
    {
        return self::admin($actor) || $actor['id'] === (int) $project['userId'];
    }

    public static function editUser(array $actor, array $target): bool
    {
        return self::admin($actor) && $actor['id'] !== (int) $target['userId']
            && ((int) $target['role'] !== 2 || self::superAdmin($actor));
    }

    public static function impersonate(array $actor, array $target, bool $alreadySwitched): bool
    {
        return !$alreadySwitched && self::admin($actor) && $actor['id'] !== (int) $target['userId']
            && (int) $target['role'] !== 2 && (bool) $target['enabled'];
    }

    public static function require(bool $allowed): void
    {
        if (!$allowed) {
            throw new HttpError(403, 'forbidden', 'You do not have permission to perform this action.');
        }
    }
}
