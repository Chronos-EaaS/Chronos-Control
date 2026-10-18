<?php
declare(strict_types=1);

namespace Chronos\Http\Api\Ui;

use Chronos\Http\JsonResponse;

use Chronos\Http\Request;
use Chronos\Application\Shared\Input;
use Chronos\Application\Shared\Context;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;

use Chronos\Application\Projects\ProjectService;

final class ProjectRoutes
{
    private readonly ProjectService $projects;

    public function __construct(private readonly Request $request, Store $db, array $actor, string $root, Sessions $sessions)
    {
        $this->projects = new ProjectService(new Context($db, $actor, $root));
    }

    public function handle(string $path): ?array
    {
        if ($path === '/projects') {
            $this->request->assertMethod(['GET', 'POST']);
            return $this->request->method === 'GET'
                ? JsonResponse::success($this->projects->projects($this->request->query))
                : JsonResponse::success($this->projects->createProject($this->request->json()), 201);
        }
        if (preg_match('#^/projects/([1-9][0-9]*)$#D', $path, $match)) {
            $this->request->assertMethod(['GET', 'PATCH']);
            $id = Input::id($match[1], 'projectId');
            return JsonResponse::success($this->request->method === 'GET'
                ? $this->projects->detail($id) : $this->projects->updateProject($id, $this->request->json()));
        }
        if (preg_match('#^/projects/([1-9][0-9]*)/members(?:/([1-9][0-9]*))?$#D', $path, $match)) {
            $id = Input::id($match[1], 'projectId');
            $member = isset($match[2]) ? Input::id($match[2], 'userId') : null;
            $this->request->assertMethod($member === null ? ['GET', 'POST'] : ['DELETE']);
            return JsonResponse::success(match ($this->request->method) {
                'GET' => $this->projects->members($id, $this->request->query),
                'POST' => $this->projects->addMember($id, $this->request->json()),
                'DELETE' => $this->projects->removeMember($id, $member),
            });
        }
        return null;
    }
}
