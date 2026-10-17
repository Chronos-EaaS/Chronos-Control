<?php
declare(strict_types=1);

namespace Chronos\Application\Projects;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Application\Security\Policy;
use Chronos\Infrastructure\Persistence\Store;

final class ProjectService
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

    public function detail(int $id): array
    {
        return Dto::project($this->project($id), $this->actor);
    }

    public function projects(array $query): array
    {
        $options = $this->context->options($query, ['id' => 'p.projectId', 'name' => 'p.name', 'description' => 'p.description', 'system' => 's.name', 'owner' => "CONCAT_WS(' ',owner.firstname,owner.lastname)"]);
        [$where, $args] = $this->context->scope('p', $options['all']);
        if ($options['archived'] !== 'all') {
            $where .= ' AND p.isArchived=?';
            $args[] = $options['archived'] === 'true' ? 1 : 0;
        }
        if ($options['search'] !== '') {
            $where .= " AND (p.name LIKE ? ESCAPE '=' OR p.description LIKE ? ESCAPE '=' OR s.name LIKE ? ESCAPE '=' OR CONCAT_WS(' ',owner.firstname,owner.lastname) LIKE ? ESCAPE '=')";
            array_push($args, ...array_fill(0, 4, Context::search($options['search'])));
        }
        return $this->context->page('FROM `Project` p LEFT JOIN `System` s ON s.systemId=p.systemId LEFT JOIN `User` owner ON owner.userId=p.userId', $where, $args, $options, "p.*,s.name AS systemName,CONCAT_WS(' ',owner.firstname,owner.lastname) AS ownerName", fn(array $row): array => Dto::project($row, $this->actor), 'p.projectId');
    }

    public function createProject(array $body): array
    {
        Input::fields($body, ['name', 'description', 'systemId', 'ownerId']);
        $name = Dto::storedText(Input::text($body['name'] ?? null, 'name', 250), 'name', 256);
        $description = Dto::storedText(Input::text($body['description'] ?? '', 'description', 65535, false), 'description', 65535);
        $systemId = Input::id($body['systemId'] ?? null, 'systemId');
        $system = $this->context->system($systemId);
        if ((bool) $system['isArchived']) {
            Input::invalid('systemId', 'Choose a current system.');
        }
        $ownerId = isset($body['ownerId']) ? Input::id($body['ownerId'], 'ownerId') : $this->actor['id'];
        Policy::require($ownerId === $this->actor['id'] || Policy::admin($this->actor));
        $this->context->eligibleUser($ownerId, 'ownerId');
        $id = $this->db->transaction(function () use ($name, $description, $systemId, $ownerId): int {
            $system = $this->db->row('SELECT userId,isArchived FROM `System` WHERE systemId=? FOR UPDATE', [$systemId]);
            Policy::require($system !== null && Policy::system($this->actor, $system));
            if ((bool) $system['isArchived']) {
                Input::invalid('systemId', 'Choose a current system.');
            }
            $id = $this->db->insert('Project', ['name' => $name, 'description' => $description, 'userId' => $ownerId, 'systemId' => $systemId, 'isFinished' => 0, 'environment' => '', 'isArchived' => 0]);
            $this->db->insert('ProjectUser', ['userId' => $ownerId, 'projectId' => $id]);
            $this->context->event('project', $id, 'Project created', 'A new project was created.');
            return $id;
        });
        return Dto::project($this->project($id), $this->actor);
    }

    public function updateProject(int $id, array $body): array
    {
        Input::fields($body, ['name', 'description', 'archived']);
        $project = $this->project($id);
        $values = [];
        if (array_key_exists('name', $body)) {
            $values['name'] = Dto::storedText(Input::text($body['name'], 'name', 250), 'name', 256);
        }
        if (array_key_exists('description', $body)) {
            $values['description'] = Dto::storedText(Input::text($body['description'], 'description', 65535, false), 'description', 65535);
        }
        if (array_key_exists('archived', $body)) {
            Policy::require(Policy::manageProject($this->actor, $project));
            $values['isArchived'] = Input::boolean($body['archived'], 'archived') ? 1 : 0;
        }
        $this->db->transaction(function () use ($id, $values): void {
            $project = $this->project($id, true);
            if (isset($values['isArchived'])) {
                Policy::require(Policy::manageProject($this->actor, $project));
            }
            $this->db->update('Project', 'projectId', $id, $values);
            $this->context->event('project', $id, 'Project updated', 'Project metadata was updated.');
        });
        return Dto::project($this->project($id), $this->actor);
    }

    private function project(int $id, bool $lock = false): array
    {
        $row = $this->db->row("SELECT p.*,s.name AS systemName,CONCAT_WS(' ',owner.firstname,owner.lastname) AS ownerName FROM `Project` p LEFT JOIN `System` s ON s.systemId=p.systemId LEFT JOIN `User` owner ON owner.userId=p.userId WHERE p.projectId=?" . ($lock ? ' FOR UPDATE' : ''), [$id])
            ?? throw new HttpError(404, 'not_found', 'The project does not exist.');
        $member = $this->db->row('SELECT projectUserId FROM `ProjectUser` WHERE projectId=? AND userId=? LIMIT 1', [$id, $this->actor['id']]) !== null;
        Policy::require(Policy::project($this->actor, $row, $member));
        return $row;
    }

    public function members(int $projectId, array $query): array
    {
        $project = $this->project($projectId);
        Input::fields($query, ['candidates']);
        $candidates = Input::queryBoolean($query['candidates'] ?? 'false', 'candidates');
        if ($candidates) {
            Policy::require(Policy::manageProject($this->actor, $project));
            $rows = $this->db->rows('SELECT u.* FROM `User` u WHERE u.alive=1' . (ONLY_ACTIVATED_USERS ? ' AND u.activated=1' : '') . ' AND u.userId<>? AND NOT EXISTS (SELECT 1 FROM `ProjectUser` m WHERE m.projectId=? AND m.userId=u.userId) ORDER BY u.lastname,u.firstname,u.userId', [(int) $project['userId'], $projectId]);
        } else {
            $rows = $this->db->rows('SELECT DISTINCT u.* FROM `User` u JOIN `ProjectUser` m ON m.userId=u.userId WHERE m.projectId=? ORDER BY u.lastname,u.firstname,u.userId', [$projectId]);
        }
        return ['items' => array_map([Dto::class, 'member'], $rows), 'ownerId' => (int) $project['userId']];
    }

    public function addMember(int $projectId, array $body): array
    {
        Policy::require(Policy::manageProject($this->actor, $this->project($projectId)));
        Input::fields($body, ['userId']);
        $memberId = Input::id($body['userId'] ?? null, 'userId');
        $user = $this->context->eligibleUser($memberId);
        $this->db->transaction(function () use ($projectId, $memberId): void {
            Policy::require(Policy::manageProject($this->actor, $this->project($projectId, true)));
            if ($this->db->row('SELECT projectUserId FROM `ProjectUser` WHERE projectId=? AND userId=? LIMIT 1', [$projectId, $memberId]) === null) {
                $this->db->insert('ProjectUser', ['userId' => $memberId, 'projectId' => $projectId]);
                $this->context->event('project', $projectId, 'Member added', 'A project member was added.');
            }
        });
        return Dto::member($user);
    }

    public function removeMember(int $projectId, int $memberId): array
    {
        Policy::require(Policy::manageProject($this->actor, $this->project($projectId)));
        $removed = $this->db->transaction(function () use ($projectId, $memberId): bool {
            $project = $this->project($projectId, true);
            Policy::require(Policy::manageProject($this->actor, $project));
            if ((int) $project['userId'] === $memberId) {
                Input::invalid('userId', 'The project owner cannot be removed.');
            }
            $removed = $this->db->execute('DELETE FROM `ProjectUser` WHERE projectId=? AND userId=?', [$projectId, $memberId]) > 0;
            if ($removed) {
                $this->context->event('project', $projectId, 'Member removed', 'A project member was removed.');
            }
            return $removed;
        });
        return ['removed' => $removed];
    }
}
