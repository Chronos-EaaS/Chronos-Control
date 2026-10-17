<?php
declare(strict_types=1);

namespace Chronos\Http\Api\V3;

use Chronos\Http\JsonResponse;

use Chronos\Http\Request;
use Chronos\Application\Shared\Context;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;

use Chronos\Application\Dashboard\DashboardService;

final class DashboardRoutes
{
    private readonly DashboardService $dashboard;

    public function __construct(private readonly Request $request, Store $db, array $actor, string $root, Sessions $sessions)
    {
        $this->dashboard = new DashboardService(new Context($db, $actor, $root));
    }

    public function handle(string $path): ?array
    {
        if ($path !== '/dashboard') return null;
        $this->request->assertMethod(['GET']);
        return JsonResponse::success($this->dashboard->dashboard($this->request->query));
    }
}
