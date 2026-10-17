<?php
declare(strict_types=1);

namespace Chronos\Api\V3;

final class Sessions
{
    public function __construct(private readonly \Auth_Library $auth)
    {
    }

    public function user(): ?array
    {
        try {
            if (!$this->auth->isLoggedIn()) {
                return null;
            }
            $user = Dto::user($this->auth->getUser()->getKeyValueDict());
            if (!$user['active'] || (ONLY_ACTIVATED_USERS && !$user['activated'])) {
                unset($_SESSION['login'], $_SESSION['realLogin']);
                return null;
            }
            return $user;
        } catch (\PDOException $error) {
            throw $error;
        } catch (\Exception) {
            unset($_SESSION['login'], $_SESSION['realLogin']);
            return null;
        }
    }

    public function requireUser(): array
    {
        return $this->user() ?? throw new HttpError(401, 'authentication_required', 'Please sign in to continue.');
    }

    public function token(): string
    {
        if (!isset($_SESSION['v3Csrf']) || !is_string($_SESSION['v3Csrf']) || strlen($_SESSION['v3Csrf']) !== 64) {
            $_SESSION['v3Csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['v3Csrf'];
    }

    public function snapshot(): array
    {
        $user = $this->user();
        $switched = $user !== null && $this->auth->isSwitchedUser();
        $original = $switched ? Dto::user($this->auth->getRealUser()->getKeyValueDict()) : null;
        return ['user' => $user, 'csrfToken' => $this->token(), 'impersonation' => ['active' => $switched, 'originalUser' => $original]];
    }

    public function login(array $body): array
    {
        Input::fields($body, ['username', 'password', 'remember']);
        $username = Input::text($body['username'] ?? null, 'username', 50);
        if (!is_string($body['password'] ?? null) || strlen($body['password']) > 1024 || $body['password'] === '') {
            Input::invalid('password', 'Enter your password.');
        }
        $remember = array_key_exists('remember', $body) ? Input::boolean($body['remember'], 'remember') : false;
        if ($this->auth->isSwitchedUser()) {
            throw new HttpError(409, 'impersonation_active', 'Return to your own account before signing in again.');
        }
        try {
            $success = $this->auth->login($username, $body['password'], $remember ? true : null);
        } catch (\Exception) {
            throw new HttpError(401, 'invalid_credentials', 'Invalid username or password.');
        }
        if (!$success) {
            throw new HttpError(401, 'invalid_credentials', 'Invalid username or password.');
        }
        $this->rotate();
        return $this->snapshot();
    }

    public function logout(): array
    {
        $switched = $this->auth->isSwitchedUser();
        $this->auth->logout();
        if (!$switched) {
            $_SESSION = [];
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $this->clearRememberCookie();
        }
        $this->rotate();
        return $this->snapshot();
    }

    public function switchTo(array $target, bool $permanent): array
    {
        if ($permanent) {
            $this->revokeRememberToken();
        }
        $this->auth->switchUser($target['username'], $permanent);
        if ($permanent) {
            $this->clearRememberCookie();
        }
        $this->rotate();
        return $this->snapshot();
    }

    public function switched(): bool
    {
        return $this->auth->isSwitchedUser();
    }

    public function originalId(): ?int
    {
        return $this->switched() ? (int) $this->auth->getRealUser()->getId() : null;
    }

    public function rotate(bool $csrf = true): void
    {
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Unable to rotate session.');
        }
        if ($csrf) {
            $_SESSION['v3Csrf'] = bin2hex(random_bytes(32));
        }
    }

    private function clearRememberCookie(): void
    {
        $options = ['expires' => 1, 'path' => '/', 'secure' => self::https(), 'httponly' => true, 'samesite' => 'Lax'];
        setcookie('rememberMe', '', $options);
        setcookie('rememberMe', '', $options + ['domain' => $_SERVER['SERVER_NAME']]);
        unset($_COOKIE['rememberMe']);
    }

    private function revokeRememberToken(): void
    {
        $cookie = $_COOKIE['rememberMe'] ?? null;
        if (is_string($cookie)) {
            $selector = explode(':', $cookie, 2)[0];
            $statement = \DBA\Factory::getUserFactory()->getDB()->prepare('DELETE FROM `Session` WHERE selector=?');
            $statement->execute([$selector]);
        }
    }

    public static function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    }

    public function hardenCookies(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires' => $params['lifetime'] ? time() + $params['lifetime'] : 0,
                'path' => $params['path'] ?: '/', 'domain' => $params['domain'],
                'secure' => self::https(), 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        // Auth_Library also emits renewal/login remember cookies. Replace their
        // options without exposing the selector/authenticator to API consumers.
        foreach (headers_list() as $header) {
            if (preg_match('/^Set-Cookie:\s*rememberMe=([^;]*)/i', $header, $match)) {
                $value = rawurldecode($match[1]);
                // PHP serializes an expired empty value as "deleted". Do not
                // accidentally renew that tombstone while hardening options.
                if (\RememberToken_Library::decodeCookie($value) === null) {
                    $value = '';
                }
                setcookie('rememberMe', $value, [
                    'expires' => $value === '' ? 1 : time() + REMEMBER_ME_COOKIE_LIFETIME,
                    'path' => '/', 'domain' => $_SERVER['SERVER_NAME'], 'secure' => self::https(),
                    'httponly' => true, 'samesite' => 'Lax',
                ]);
            }
        }
    }
}
