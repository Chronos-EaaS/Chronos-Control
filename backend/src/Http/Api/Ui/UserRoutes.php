<?php
declare(strict_types=1);

namespace Chronos\Http\Api\Ui;

use Chronos\Http\JsonResponse;

use Chronos\Http\Request;
use Chronos\Application\Shared\Input;
use Chronos\Application\Shared\Context;
use Chronos\Application\Security\Policy;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;

use Chronos\Application\Users\UserService;

final class UserRoutes
{
    private readonly UserService $users;

    public function __construct(private readonly Request $request, Store $db, private readonly array $actor, string $root, Sessions $sessions)
    {
        $this->users = new UserService(new Context($db, $actor, $root), $sessions);
    }

    public function handle(string $path): ?array
    {
        if ($path === '/session/impersonation') {
            $this->request->assertMethod(['POST', 'DELETE']);
            return JsonResponse::success($this->request->method === 'POST'
                ? $this->users->impersonate($this->request->json()) : $this->users->endImpersonation());
        }
        if ($path === '/profile') {
            $this->request->assertMethod(['GET', 'PATCH']);
            return JsonResponse::success($this->request->method === 'GET'
                ? $this->actor : $this->users->updateUser($this->actor['id'], true, $this->request->json()));
        }
        if ($path === '/users') {
            $this->request->assertMethod(['GET', 'POST']);
            Policy::require(Policy::admin($this->actor));
            return $this->request->method === 'GET'
                ? JsonResponse::success($this->users->users($this->request->query))
                : JsonResponse::success($this->users->createUser($this->request->json()), 201);
        }
        if (preg_match('#^/users/([1-9][0-9]*)$#D', $path, $match)) {
            $this->request->assertMethod(['GET', 'PATCH']);
            Policy::require(Policy::admin($this->actor));
            $id = Input::id($match[1], 'userId');
            return JsonResponse::success($this->request->method === 'GET'
                ? $this->users->detail($id) : $this->users->updateUser($id, false, $this->request->json()));
        }
        return null;
    }
}
