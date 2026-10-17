<?php
declare(strict_types=1);

namespace Chronos\Application\Users;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Application\Shared\TextSearch;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Application\Security\Policy;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;

final class UserService
{
    private readonly Store $db;
    private readonly array $actor;
    private readonly string $root;

    public function __construct(private readonly Context $context, private readonly Sessions $sessions)
    {
        $this->db = $context->db;
        $this->actor = $context->actor;
        $this->root = $context->root;
    }

    public function detail(int $id): array
    {
        return Dto::user($this->context->user($id));
    }

    public function users(array $query): array
    {
        $active = $query['active'] ?? 'true';
        unset($query['active']);
        if (!in_array($active, ['true', 'false', 'all'], true)) {
            Input::invalid('active', 'Use true, false or all.');
        }
        $options = $this->context->options($query, ['id' => 'u.userId', 'name' => 'u.lastname', 'username' => 'u.username']);
        $where = '1=1';
        $args = [];
        if ($active !== 'all') {
            $where .= ' AND u.alive=?';
            $args[] = $active === 'true' ? 1 : 0;
        }
        if ($options['search'] !== '') {
            [$clause, $patterns] = TextSearch::condition($options['search'], ['u.firstname', 'u.lastname'], ['u.username']);
            $where .= ' AND ' . $clause; array_push($args, ...$patterns);
        }
        return $this->context->page('FROM `User` u', $where, $args, $options, 'u.*', [Dto::class, 'user'], 'u.userId');
    }

    private function username(mixed $value): string
    {
        $username = Input::text($value, 'username', 50);
        if (is_numeric($username) || preg_match('/[<>&\r\n\t]/', $username)) {
            Input::invalid('username', 'Use a non-numeric username without markup or control characters.');
        }
        $this->db->assertText('User', 'username', $username, 'username');
        return $username;
    }

    public function createUser(array $body): array
    {
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
        $id = $this->db->transaction(function () use ($values): int {
            if ($this->db->row('SELECT userId FROM `User` WHERE username=? FOR UPDATE', [$values['username']]) !== null) {
                throw new HttpError(409, 'username_in_use', 'This username is already in use.', ['username' => 'Choose another username.']);
            }
            $id = $this->db->insert('User', $values);
            $this->context->event('user', $id, 'User created', 'A new user account was created.');
            return $id;
        });
        return Dto::user($this->context->user($id));
    }

    public function updateUser(int $id, bool $profile, array $body): array
    {
        $target = $this->context->user($id);
        Policy::require($profile ? $id === $this->actor['id'] : Policy::editUser($this->actor, $target));
        Policy::require($this->sessions->originalId() !== $id);
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
        $this->db->transaction(function () use ($id, $values, $profile, $body, $passwordRequested): void {
            $fresh = $this->db->row('SELECT * FROM `User` WHERE userId=? FOR UPDATE', [$id])
                ?? throw new HttpError(404, 'not_found', 'The user does not exist.');
            Policy::require($profile ? $id === $this->actor['id'] : Policy::editUser($this->actor, $fresh));
            if ($passwordRequested && $profile && !password_verify($body['currentPassword'], $fresh['password'])) {
                Input::invalid('currentPassword', 'The current password is incorrect.');
            }
            if (isset($values['username']) && $this->db->row('SELECT userId FROM `User` WHERE username=? AND userId<>? FOR UPDATE', [$values['username'], $id]) !== null) {
                throw new HttpError(409, 'username_in_use', 'This username is already in use.', ['username' => 'Choose another username.']);
            }
            $this->db->update('User', 'userId', $id, $values);
            if ($passwordRequested || (isset($values['alive']) && !$values['alive'])) {
                $this->db->execute('DELETE FROM `Session` WHERE userId=?', [$id]);
            }
            $this->context->event('user', $id, 'User updated', 'User account settings were updated.');
        });
        if ($profile && $passwordRequested) {
            $this->sessions->bindCredentials();
            $this->sessions->rotate(false);
        }
        return Dto::user($this->context->user($id));
    }

    public function impersonate(array $body): array
    {
        Input::fields($body, ['userId', 'permanent']);
        $target = $this->context->user(Input::id($body['userId'] ?? null, 'userId'));
        Policy::require(Policy::impersonate($this->actor, $target, $this->sessions->switched()));
        if (ONLY_ACTIVATED_USERS && !(bool) $target['activated']) {
            Input::invalid('userId', 'Choose an activated account.');
        }
        $permanent = isset($body['permanent']) ? Input::boolean($body['permanent'], 'permanent') : false;
        return $this->sessions->switchTo($target, $permanent);
    }

    public function endImpersonation(): array
    {
        if (!$this->sessions->switched()) {
            throw new HttpError(409, 'not_impersonating', 'No impersonated session is active.');
        }
        return $this->sessions->logout();
    }
}
