<?php
declare(strict_types=1);

namespace Chronos\Api\V3;

final class Kernel
{
    private ?Store $store = null;
    private array $actor = [];

    public function __construct(private readonly Request $request, private readonly Sessions $sessions, private readonly string $root)
    {
    }

    private function db(): Store
    {
        // All v3 domain operations share this connection, including their events.
        return $this->store ??= new Store(\DBA\Factory::getUserFactory()->getDB());
    }

    private function success(mixed $data, int $status = 200): array
    {
        return ['status' => $status, 'body' => ['data' => $data]];
    }

    public function handle(): array
    {
        $path = substr($this->request->path, strlen('/api/v3'));
        if ($path === '/meta') {
            $this->request->assertMethod(['GET']);
            $buildId = null;
            $file = $this->root . '/webroot/ui/build.json';
            if (is_file($file) && filesize($file) < 65536) {
                $build = json_decode((string) file_get_contents($file), true);
                $buildId = is_array($build) && is_string($build['id'] ?? null) ? $build['id'] : null;
            }
            return $this->success([
                'apiVersion' => 3, 'applicationVersion' => APP_VERSION, 'uiBuildId' => $buildId,
                'password' => ['minLength' => PASSWORDS_MIN_LENGTH, 'maxLength' => min(PASSWORDS_MAX_LENGTH, 72), 'requireComplexity' => REQUIRE_PASSWORD_COMPLEXITY],
                'timezone' => 'Europe/Zurich', 'features' => ['remoteRepositories' => defined('ENABLE_REMOTE_REPOSITORY') && ENABLE_REMOTE_REPOSITORY], 'extensions' => Extensions::capabilities(),
            ]);
        }
        if ($path === '/session') {
            $this->request->assertMethod(['GET', 'POST', 'DELETE']);
            if ($this->request->method === 'GET') {
                return $this->success($this->sessions->snapshot());
            }
            $this->mutation();
            return $this->success($this->request->method === 'POST'
                ? $this->sessions->login($this->request->json()) : $this->sessions->logout());
        }

        $this->actor = $this->sessions->requireUser();
        if (!in_array($this->request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $this->mutation();
        }
        foreach ([PresetRoutes::class, ExtensionRoutes::class, WorkflowRoutes::class, SystemRoutes::class, AdminRoutes::class] as $module) {
            $response = (new $module($this->request, $this->db(), $this->actor, $this->root, $this->sessions))->handle($path);
            if ($response !== null) {
                return $response;
            }
        }
        if ($path === '/session/impersonation') {
            $this->request->assertMethod(['POST', 'DELETE']);
            if ($this->request->method === 'DELETE') {
                if (!$this->sessions->switched()) {
                    throw new HttpError(409, 'not_impersonating', 'No impersonated session is active.');
                }
                return $this->success($this->sessions->logout());
            }
            $body = $this->request->json();
            Input::fields($body, ['userId', 'permanent']);
            $target = $this->userRow(Input::id($body['userId'] ?? null, 'userId'));
            Policy::require(Policy::impersonate($this->actor, $target, $this->sessions->switched()));
            if (ONLY_ACTIVATED_USERS && !(bool) $target['activated']) {
                Input::invalid('userId', 'Choose an activated account.');
            }
            $permanent = isset($body['permanent']) ? Input::boolean($body['permanent'], 'permanent') : false;
            return $this->success($this->sessions->switchTo($target, $permanent));
        }
        if ($path === '/profile') {
            $this->request->assertMethod(['GET', 'PATCH']);
            if ($this->request->method === 'GET') {
                return $this->success($this->actor);
            }
            return $this->success($this->updateUser($this->actor['id'], true));
        }
        if ($path === '/dashboard') {
            $this->request->assertMethod(['GET']);
            return $this->success($this->dashboard());
        }
        if ($path === '/users') {
            $this->request->assertMethod(['GET', 'POST']);
            Policy::require(Policy::admin($this->actor));
            return $this->request->method === 'GET'
                ? $this->success($this->users()) : $this->success($this->createUser(), 201);
        }
        if (preg_match('#^/users/([1-9][0-9]*)$#D', $path, $match)) {
            $this->request->assertMethod(['GET', 'PATCH']);
            Policy::require(Policy::admin($this->actor));
            $id = Input::id($match[1], 'userId');
            if ($this->request->method === 'GET') {
                return $this->success(Dto::user($this->userRow($id)));
            }
            return $this->success($this->updateUser($id, false));
        }
        if ($path === '/projects') {
            $this->request->assertMethod(['GET', 'POST']);
            return $this->request->method === 'GET'
                ? $this->success($this->projects()) : $this->success($this->createProject(), 201);
        }
        if (preg_match('#^/projects/([1-9][0-9]*)$#D', $path, $match)) {
            $this->request->assertMethod(['GET', 'PATCH']);
            $id = Input::id($match[1], 'projectId');
            return $this->success($this->request->method === 'GET'
                ? Dto::project($this->project($id), $this->actor) : $this->updateProject($id));
        }
        if (preg_match('#^/projects/([1-9][0-9]*)/members(?:/([1-9][0-9]*))?$#D', $path, $match)) {
            return $this->members(Input::id($match[1], 'projectId'), isset($match[2]) ? Input::id($match[2], 'userId') : null);
        }
        if ($path === '/systems') {
            $this->request->assertMethod(['GET', 'POST']);
            return $this->request->method === 'GET'
                ? $this->success($this->systems()) : $this->success($this->createSystem(), 201);
        }
        if (preg_match('#^/systems/([1-9][0-9]*)$#D', $path, $match)) {
            $this->request->assertMethod(['GET']);
            return $this->success(Dto::system($this->system(Input::id($match[1], 'systemId')), $this->actor, $this->root));
        }
        throw new HttpError(404, 'not_found', 'This API resource does not exist.');
    }

    private function mutation(): void
    {
        $this->request->assertSameOrigin(Sessions::https() ? 'https' : 'http', $_SERVER['HTTP_HOST'] ?? '');
        $this->request->assertCsrf($this->sessions->token());
    }

    private function userRow(int $id): array
    {
        return $this->db()->row('SELECT * FROM `User` WHERE userId=?', [$id])
            ?? throw new HttpError(404, 'not_found', 'The user does not exist.');
    }

    private function eligibleUser(int $id, string $field = 'userId'): array
    {
        $user = $this->userRow($id);
        if (!(bool) $user['alive'] || (ONLY_ACTIVATED_USERS && !(bool) $user['activated'])) {
            Input::invalid($field, 'Choose an active eligible account.');
        }
        return $user;
    }

    private function system(int $id): array
    {
        $row = $this->db()->row("SELECT s.systemId,s.name,s.description,s.userId,s.created,s.lastEdit,s.isArchived,s.cem,CONCAT_WS(' ',owner.firstname,owner.lastname) AS ownerName FROM `System` s LEFT JOIN `User` owner ON owner.userId=s.userId WHERE s.systemId=?", [$id])
            ?? throw new HttpError(404, 'not_found', 'The system does not exist.');
        Policy::require(Policy::system($this->actor, $row));
        return $row;
    }

    private function project(int $id, bool $lock = false): array
    {
        $row = $this->db()->row("SELECT p.*,s.name AS systemName,CONCAT_WS(' ',owner.firstname,owner.lastname) AS ownerName FROM `Project` p LEFT JOIN `System` s ON s.systemId=p.systemId LEFT JOIN `User` owner ON owner.userId=p.userId WHERE p.projectId=?" . ($lock ? ' FOR UPDATE' : ''), [$id])
            ?? throw new HttpError(404, 'not_found', 'The project does not exist.');
        $member = $this->db()->row('SELECT projectUserId FROM `ProjectUser` WHERE projectId=? AND userId=? LIMIT 1', [$id, $this->actor['id']]) !== null;
        Policy::require(Policy::project($this->actor, $row, $member));
        return $row;
    }

    private function scope(string $alias, bool $all): array
    {
        if ($all) {
            Policy::require(Policy::admin($this->actor));
            return ['1=1', []];
        }
        return ['(' . $alias . '.userId=? OR EXISTS (SELECT 1 FROM `ProjectUser` scopeMember WHERE scopeMember.projectId=' . $alias . '.projectId AND scopeMember.userId=?))', [$this->actor['id'], $this->actor['id']]];
    }

    private function searchPattern(string $search): string
    {
        return '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search) . '%';
    }

    private function listOptions(array $query, array $sorts): array
    {
        if (!array_key_exists('pageSize', $query)) {
            $setting = $this->db()->row("SELECT value FROM `Setting` WHERE systemId=0 AND section='other' AND item='rowsPerPage' LIMIT 1");
            $configured = $setting !== null ? filter_var($setting['value'], FILTER_VALIDATE_INT) : false;
            $query['pageSize'] = (string) ($configured !== false && $configured > 0 ? min($configured, 100) : 20);
        }
        return Input::listOptions($query, $sorts);
    }

    private function page(string $from, string $where, array $args, array $options, string $select, callable $map, string $tie): array
    {
        $total = (int) $this->db()->row('SELECT COUNT(*) AS total ' . $from . ' WHERE ' . $where, $args)['total'];
        $offset = ($options['page'] - 1) * $options['pageSize'];
        $rows = $this->db()->rows('SELECT ' . $select . ' ' . $from . ' WHERE ' . $where . ' ORDER BY ' . $options['order'] . ',' . $tie . ' ASC LIMIT ' . $options['pageSize'] . ' OFFSET ' . $offset, $args);
        return ['items' => array_map($map, $rows), 'total' => $total, 'page' => $options['page'], 'pageSize' => $options['pageSize']];
    }

    private function projects(): array
    {
        $options = $this->listOptions($this->request->query, ['id' => 'p.projectId', 'name' => 'p.name', 'description' => 'p.description', 'system' => 's.name', 'owner' => "CONCAT_WS(' ',owner.firstname,owner.lastname)"]);
        [$where, $args] = $this->scope('p', $options['all']);
        if ($options['archived'] !== 'all') {
            $where .= ' AND p.isArchived=?';
            $args[] = $options['archived'] === 'true' ? 1 : 0;
        }
        if ($options['search'] !== '') {
            $where .= " AND (p.name LIKE ? ESCAPE '=' OR p.description LIKE ? ESCAPE '=' OR s.name LIKE ? ESCAPE '=' OR CONCAT_WS(' ',owner.firstname,owner.lastname) LIKE ? ESCAPE '=')";
            array_push($args, ...array_fill(0, 4, $this->searchPattern($options['search'])));
        }
        return $this->page('FROM `Project` p LEFT JOIN `System` s ON s.systemId=p.systemId LEFT JOIN `User` owner ON owner.userId=p.userId', $where, $args, $options, "p.*,s.name AS systemName,CONCAT_WS(' ',owner.firstname,owner.lastname) AS ownerName", fn(array $row): array => Dto::project($row, $this->actor), 'p.projectId');
    }

    private function systems(): array
    {
        $options = $this->listOptions($this->request->query, ['id' => 's.systemId', 'name' => 's.name', 'description' => 's.description', 'owner' => "CONCAT_WS(' ',owner.firstname,owner.lastname)"]);
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
            array_push($args, ...array_fill(0, 3, $this->searchPattern($options['search'])));
        }
        return $this->page('FROM `System` s LEFT JOIN `User` owner ON owner.userId=s.userId', $where, $args, $options, "s.systemId,s.name,s.description,s.userId,s.created,s.lastEdit,s.isArchived,s.cem,CONCAT_WS(' ',owner.firstname,owner.lastname) AS ownerName", fn(array $row): array => Dto::system($row, $this->actor, $this->root), 's.systemId');
    }

    private function createProject(): array
    {
        $body = $this->request->json();
        Input::fields($body, ['name', 'description', 'systemId', 'ownerId']);
        $name = Dto::storedText(Input::text($body['name'] ?? null, 'name', 250), 'name', 256);
        $description = Dto::storedText(Input::text($body['description'] ?? '', 'description', 65535, false), 'description', 65535);
        $systemId = Input::id($body['systemId'] ?? null, 'systemId');
        $system = $this->system($systemId);
        if ((bool) $system['isArchived']) {
            Input::invalid('systemId', 'Choose a current system.');
        }
        $ownerId = isset($body['ownerId']) ? Input::id($body['ownerId'], 'ownerId') : $this->actor['id'];
        Policy::require($ownerId === $this->actor['id'] || Policy::admin($this->actor));
        $this->eligibleUser($ownerId, 'ownerId');
        $id = $this->db()->transaction(function () use ($name, $description, $systemId, $ownerId): int {
            $system = $this->db()->row('SELECT userId,isArchived FROM `System` WHERE systemId=? FOR UPDATE', [$systemId]);
            Policy::require($system !== null && Policy::system($this->actor, $system));
            if ((bool) $system['isArchived']) {
                Input::invalid('systemId', 'Choose a current system.');
            }
            $id = $this->db()->insert('Project', ['name' => $name, 'description' => $description, 'userId' => $ownerId, 'systemId' => $systemId, 'isFinished' => 0, 'environment' => '', 'isArchived' => 0]);
            $this->db()->insert('ProjectUser', ['userId' => $ownerId, 'projectId' => $id]);
            $this->event('project', $id, 'Project created', 'A new project was created.');
            return $id;
        });
        return Dto::project($this->project($id), $this->actor);
    }

    private function updateProject(int $id): array
    {
        $body = $this->request->json();
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
        $this->db()->transaction(function () use ($id, $values): void {
            $project = $this->project($id, true);
            if (isset($values['isArchived'])) {
                Policy::require(Policy::manageProject($this->actor, $project));
            }
            $this->db()->update('Project', 'projectId', $id, $values);
            $this->event('project', $id, 'Project updated', 'Project metadata was updated.');
        });
        return Dto::project($this->project($id), $this->actor);
    }

    private function members(int $projectId, ?int $memberId): array
    {
        $this->request->assertMethod($memberId === null ? ['GET', 'POST'] : ['DELETE']);
        $project = $this->project($projectId);
        if ($this->request->method === 'GET') {
            Input::fields($this->request->query, ['candidates']);
            $candidates = Input::queryBoolean($this->request->query['candidates'] ?? 'false', 'candidates');
            if ($candidates) {
                Policy::require(Policy::manageProject($this->actor, $project));
                $rows = $this->db()->rows('SELECT u.* FROM `User` u WHERE u.alive=1' . (ONLY_ACTIVATED_USERS ? ' AND u.activated=1' : '') . ' AND u.userId<>? AND NOT EXISTS (SELECT 1 FROM `ProjectUser` m WHERE m.projectId=? AND m.userId=u.userId) ORDER BY u.lastname,u.firstname,u.userId', [(int) $project['userId'], $projectId]);
            } else {
                $rows = $this->db()->rows('SELECT DISTINCT u.* FROM `User` u JOIN `ProjectUser` m ON m.userId=u.userId WHERE m.projectId=? ORDER BY u.lastname,u.firstname,u.userId', [$projectId]);
            }
            return $this->success(['items' => array_map([Dto::class, 'member'], $rows), 'ownerId' => (int) $project['userId']]);
        }
        Policy::require(Policy::manageProject($this->actor, $project));
        if ($this->request->method === 'POST') {
            $body = $this->request->json();
            Input::fields($body, ['userId']);
            $memberId = Input::id($body['userId'] ?? null, 'userId');
            $user = $this->eligibleUser($memberId);
            $this->db()->transaction(function () use ($projectId, $memberId): void {
                Policy::require(Policy::manageProject($this->actor, $this->project($projectId, true)));
                if ($this->db()->row('SELECT projectUserId FROM `ProjectUser` WHERE projectId=? AND userId=? LIMIT 1', [$projectId, $memberId]) === null) {
                    $this->db()->insert('ProjectUser', ['userId' => $memberId, 'projectId' => $projectId]);
                    $this->event('project', $projectId, 'Member added', 'A project member was added.');
                }
            });
            return $this->success(Dto::member($user));
        }
        $removed = $this->db()->transaction(function () use ($projectId, $memberId): bool {
            $project = $this->project($projectId, true);
            Policy::require(Policy::manageProject($this->actor, $project));
            if ((int) $project['userId'] === $memberId) {
                Input::invalid('userId', 'The project owner cannot be removed.');
            }
            $removed = $this->db()->execute('DELETE FROM `ProjectUser` WHERE projectId=? AND userId=?', [$projectId, $memberId]) > 0;
            if ($removed) {
                $this->event('project', $projectId, 'Member removed', 'A project member was removed.');
            }
            return $removed;
        });
        return $this->success(['removed' => $removed]);
    }

    private function users(): array
    {
        $query = $this->request->query;
        $active = $query['active'] ?? 'true';
        unset($query['active']);
        if (!in_array($active, ['true', 'false', 'all'], true)) {
            Input::invalid('active', 'Use true, false or all.');
        }
        $options = $this->listOptions($query, ['id' => 'u.userId', 'name' => 'u.lastname', 'username' => 'u.username']);
        $where = '1=1';
        $args = [];
        if ($active !== 'all') {
            $where .= ' AND u.alive=?';
            $args[] = $active === 'true' ? 1 : 0;
        }
        if ($options['search'] !== '') {
            $where .= " AND (u.username LIKE ? ESCAPE '=' OR u.firstname LIKE ? ESCAPE '=' OR u.lastname LIKE ? ESCAPE '=')";
            array_push($args, ...array_fill(0, 3, $this->searchPattern($options['search'])));
        }
        return $this->page('FROM `User` u', $where, $args, $options, 'u.*', [Dto::class, 'user'], 'u.userId');
    }

    private function username(mixed $value): string
    {
        $username = Input::text($value, 'username', 50);
        if (is_numeric($username) || preg_match('/[<>&\r\n\t]/', $username)) {
            Input::invalid('username', 'Use a non-numeric username without markup or control characters.');
        }
        return $username;
    }

    private function createUser(): array
    {
        $body = $this->request->json();
        Input::fields($body, ['username', 'firstName', 'lastName', 'email', 'gender', 'password', 'passwordConfirmation', 'role', 'active', 'activated']);
        $values = Input::identity($body, true);
        foreach (['firstname' => 'firstName', 'lastname' => 'lastName'] as $column => $field) {
            $values[$column] = Dto::storedText($values[$column], $field, 50);
        }
        $values['username'] = $this->username($body['username'] ?? null);
        $values['password'] = password_hash(Input::password($body['password'] ?? null, $body['passwordConfirmation'] ?? null, PASSWORDS_MIN_LENGTH, PASSWORDS_MAX_LENGTH, REQUIRE_PASSWORD_COMPLEXITY), PASSWORD_BCRYPT);
        $values['role'] = isset($body['role']) ? Input::integer($body['role'], 'role', 0, 2) : 0;
        Policy::require($values['role'] === 0 || Policy::superAdmin($this->actor));
        $values['alive'] = isset($body['active']) ? (int) Input::boolean($body['active'], 'active') : 1;
        $values['activated'] = isset($body['activated']) ? (int) Input::boolean($body['activated'], 'activated') : 1;
        $values['created'] = $values['lastEdit'] = date('Y-m-d H:i:s');
        $values['lastLogin'] = null;
        $id = $this->db()->transaction(function () use ($values): int {
            if ($this->db()->row('SELECT userId FROM `User` WHERE username=? FOR UPDATE', [$values['username']]) !== null) {
                throw new HttpError(409, 'username_in_use', 'This username is already in use.', ['username' => 'Choose another username.']);
            }
            $id = $this->db()->insert('User', $values);
            $this->event('user', $id, 'User created', 'A new user account was created.');
            return $id;
        });
        return Dto::user($this->userRow($id));
    }

    private function updateUser(int $id, bool $profile): array
    {
        $target = $this->userRow($id);
        Policy::require($profile ? $id === $this->actor['id'] : Policy::editUser($this->actor, $target));
        Policy::require($this->sessions->originalId() !== $id);
        $body = $this->request->json();
        $allowed = ['firstName', 'lastName', 'email', 'gender', 'password', 'passwordConfirmation', 'currentPassword'];
        Input::fields($body, $profile ? $allowed : [...$allowed, 'username', 'role', 'active', 'activated']);
        $values = Input::identity($body, false);
        foreach (['firstname' => 'firstName', 'lastname' => 'lastName'] as $column => $field) {
            if (isset($values[$column])) {
                $values[$column] = Dto::storedText($values[$column], $field, 50);
            }
        }
        if (array_key_exists('username', $body)) {
            $values['username'] = $this->username($body['username']);
        }
        if (array_key_exists('role', $body)) {
            Policy::require(Policy::superAdmin($this->actor));
            $values['role'] = Input::integer($body['role'], 'role', 0, 2);
        }
        foreach (['active' => 'alive', 'activated' => 'activated'] as $field => $column) {
            if (array_key_exists($field, $body)) {
                $values[$column] = (int) Input::boolean($body[$field], $field);
            }
        }
        $passwordRequested = array_key_exists('password', $body) || array_key_exists('passwordConfirmation', $body) || array_key_exists('currentPassword', $body);
        if ($passwordRequested) {
            if ($profile && (!is_string($body['currentPassword'] ?? null) || !password_verify($body['currentPassword'], $target['password']))) {
                Input::invalid('currentPassword', 'The current password is incorrect.');
            }
            $values['password'] = password_hash(Input::password($body['password'] ?? null, $body['passwordConfirmation'] ?? null, PASSWORDS_MIN_LENGTH, PASSWORDS_MAX_LENGTH, REQUIRE_PASSWORD_COMPLEXITY), PASSWORD_BCRYPT);
        }
        if ($values === []) {
            Input::invalid('body', 'Submit at least one field to update.');
        }
        $values['lastEdit'] = date('Y-m-d H:i:s');
        $this->db()->transaction(function () use ($id, $values, $profile, $body, $passwordRequested): void {
            $fresh = $this->db()->row('SELECT * FROM `User` WHERE userId=? FOR UPDATE', [$id])
                ?? throw new HttpError(404, 'not_found', 'The user does not exist.');
            Policy::require($profile ? $id === $this->actor['id'] : Policy::editUser($this->actor, $fresh));
            if ($passwordRequested && $profile && !password_verify($body['currentPassword'], $fresh['password'])) {
                Input::invalid('currentPassword', 'The current password is incorrect.');
            }
            if (isset($values['username']) && $this->db()->row('SELECT userId FROM `User` WHERE username=? AND userId<>? FOR UPDATE', [$values['username'], $id]) !== null) {
                throw new HttpError(409, 'username_in_use', 'This username is already in use.', ['username' => 'Choose another username.']);
            }
            $this->db()->update('User', 'userId', $id, $values);
            if ($passwordRequested || (isset($values['alive']) && !$values['alive'])) {
                $this->db()->execute('DELETE FROM `Session` WHERE userId=?', [$id]);
            }
            $this->event('user', $id, 'User updated', 'User account settings were updated.');
        });
        if ($profile && $passwordRequested) {
            $this->sessions->bindCredentials();
            $this->sessions->rotate(false);
        }
        return Dto::user($this->userRow($id));
    }

    private function createSystem(): array
    {
        Policy::require(Policy::admin($this->actor));
        $body = $this->request->json();
        Input::fields($body, ['name', 'description', 'ownerId', 'cem', 'repository']);
        $name = Dto::storedText(Input::text($body['name'] ?? null, 'name', 250), 'name', 256);
        $description = Dto::storedText(Input::text($body['description'] ?? '', 'description', 65535, false), 'description', 65535);
        $owner = Input::id($body['ownerId'] ?? null, 'ownerId');
        $this->eligibleUser($owner, 'ownerId');
        $cem = isset($body['cem']) ? (int) Input::boolean($body['cem'], 'cem') : 0;
        $repository = null;
        if (array_key_exists('repository', $body)) {
            Policy::require(defined('ENABLE_REMOTE_REPOSITORY') && ENABLE_REMOTE_REPOSITORY);
            $repository = SystemRoutes::repositoryInput($body['repository']);
        }
        $createdPath = null;
        try {
            $id = $this->db()->transaction(function () use ($name, $description, $owner, $cem, $repository, &$createdPath): int {
                $now = date('Y-m-d H:i:s');
                $id = $this->db()->insert('System', ['name' => $name, 'description' => $description, 'userId' => $owner, ...($repository ?? ['vcsUrl' => '', 'vcsBranch' => 'master', 'vcsType' => 'git', 'vcsUser' => '', 'vcsPassword' => '']), 'created' => $now, 'lastEdit' => $now, 'isArchived' => 0, 'cem' => $cem]);
                $base = $this->root . '/webroot/systems';
                if (!is_dir($base) && !mkdir($base, 0770, true)) {
                    throw new \RuntimeException('System storage is unavailable.');
                }
                $path = $base . '/' . $id;
                if (file_exists($path) || is_link($path) || !mkdir($path, 0770)) {
                    throw new \RuntimeException('System storage cannot be created.');
                }
                $createdPath = $path;
                if ($repository !== null) (new SystemRoutes($this->request, $this->db(), $this->actor, $this->root, $this->sessions))->initializeRemote($id, $repository);
                $initializedFiles = [];
                foreach ([
                    'config.json' => ['name' => Dto::text($name), 'identifier' => 'system-' . bin2hex(random_bytes(12))],
                    'parameters.json' => [], 'resultConfigurations.json' => ['version' => '1.0', 'elements' => new \stdClass()],
                ] as $file => $data) {
                    if ($repository !== null && (is_file($path . '/' . $file) || $file === 'resultConfigurations.json' && (is_file($path . '/results.json') || is_file($path . '/resultsJob.json')))) continue;
                    if (file_put_contents($path . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                        throw new \RuntimeException('System configuration cannot be written.');
                    }
                    $initializedFiles[] = $file;
                }
                if (!is_file($path . '/logo.png')) {
                    if (!copy($this->root . '/webroot/images/active.png', $path . '/logo.png')) throw new \RuntimeException('System logo cannot be initialized.');
                    $initializedFiles[] = 'logo.png';
                }
                if ($repository === null) Repository::initialize($path, $this->root);
                else (new SystemRoutes($this->request, $this->db(), $this->actor, $this->root, $this->sessions))->recordInitialConfiguration($id, $initializedFiles);
                foreach ([['environments', 'default', 'unused value'], ['defaultValues', 'environment', 'system-default'], ['defaultValues', 'phase_warmUp', '1']] as [$section, $item, $value]) {
                    $this->db()->insert('Setting', ['section' => $section, 'item' => $item, 'value' => $value, 'systemId' => $id]);
                }
                return $id;
            });
        } catch (\Throwable $error) {
            if ($createdPath !== null) {
                foreach (['config.json', 'parameters.json', 'resultConfigurations.json', 'logo.png'] as $file) {
                    if (is_file($createdPath . '/' . $file)) {
                        unlink($createdPath . '/' . $file);
                    }
                }
                // Only the newly created system directory belongs to this
                // failed unit of work, including its newly initialized .git.
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($createdPath, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $entry) {
                    $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
                rmdir($createdPath);
            }
            throw $error;
        }
        return Dto::system($this->system($id), $this->actor, $this->root);
    }

    private function event(string $type, int $id, string $title, string $text): void
    {
        $this->db()->insert('Event', ['title' => $title, 'time' => date('Y-m-d H:i:s'), 'eventText' => $text, 'eventType' => $type, 'relatedId' => $id, 'userId' => $this->actor['id'], 'nodeId' => null]);
    }

    private function dashboard(): array
    {
        Input::fields($this->request->query, ['all']);
        $all = Input::queryBoolean($this->request->query['all'] ?? 'false', 'all');
        [$scope, $args] = $this->scope('p', $all);
        $scalar = fn(string $sql, array $values = []): int => (int) $this->db()->row($sql, $values)['n'];
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
        $events = $this->db()->rows('SELECT v.*,a.firstname actorFirstName,a.lastname actorLastName FROM `Event` v LEFT JOIN `User` a ON a.userId=v.userId WHERE ' . implode(' OR ', $eventWhere) . ' ORDER BY v.time DESC,v.eventId DESC LIMIT 20', $eventArgs);
        return ['counts' => $counts, 'events' => array_map((new EventProjection(new Context($this->db(), $this->actor, $this->root)))->event(...), $events)];
    }
}
