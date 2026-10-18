<?php
declare(strict_types=1);

namespace Chronos\Http\Api\Ui;

use Chronos\Application\Projects\PresetService;
use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Input;
use Chronos\Http\JsonResponse;
use Chronos\Http\Request;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;

final class PresetRoutes
{
    private readonly PresetService $presets;

    public function __construct(private readonly Request $request, Store $db, array $actor, string $root, Sessions $sessions)
    {
        $this->presets = new PresetService(new Context($db, $actor, $root), $sessions);
    }

    public function handle(string $path): ?array
    {
        if (!preg_match('#^/projects/([1-9][0-9]*)/parameter-presets(?:/([a-f0-9]{32}))?$#D', $path, $match)) return null;
        $this->request->assertMethod(isset($match[2]) ? ['PUT', 'DELETE'] : ['GET', 'POST']);
        $id = Input::id($match[1], 'projectId');
        $result = match ($this->request->method) {
            'GET' => $this->presets->listing($id),
            'DELETE' => $this->presets->delete($id, $match[2], $this->request->json()),
            default => $this->presets->save($id, $match[2] ?? null, $this->request->json()),
        };
        return JsonResponse::success($result, $this->request->method === 'POST' ? 201 : 200);
    }
}
