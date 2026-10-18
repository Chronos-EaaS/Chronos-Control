<?php
declare(strict_types=1);

namespace Chronos\Http\Api\Ui;

use Chronos\Http\JsonResponse;

use Chronos\Http\Request;
use Chronos\Application\Shared\Context;
use Chronos\Application\Security\Policy;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;

use Chronos\Application\Administration\SettingsService;
use Chronos\Application\Cem\{EnvironmentService, NodeService};
use Chronos\Application\Maintenance\{AdminOperationExecutor, MaintenanceService};
use Chronos\Infrastructure\Administration\NativeAdminOperations;

/** HTTP dispatch only; application services own settings and operation lifecycles. */
final class AdminRoutes
{
    private readonly SettingsService $settings;
    private readonly EnvironmentService $environments;
    private readonly NodeService $nodes;
    private readonly MaintenanceService $maintenance;

    public function __construct(
        private readonly Request $request,
        Store $db,
        private readonly array $actor,
        string $root,
        Sessions $sessions,
        ?AdminOperationExecutor $operations = null,
    ) {
        $context = new Context($db, $actor, $root);
        $this->settings = new SettingsService($context);
        $this->environments = new EnvironmentService($context);
        $this->nodes = new NodeService($context);
        $this->maintenance = new MaintenanceService($context, $sessions, $operations ?? new NativeAdminOperations($db, $root));
    }

    public function handle(string $path): ?array
    {
        if (!str_starts_with($path, '/admin/') && !str_starts_with($path, '/cem/')) return null;
        Policy::require(Policy::admin($this->actor));
        if ($path === '/admin/settings') {
            $this->request->assertMethod(['GET']);
            return JsonResponse::success(['sections' => $this->settings->sections()]);
        }
        if (preg_match('#^/admin/settings/([^/]+)$#D', $path, $match)) {
            $this->request->assertMethod(['PATCH']);
            return JsonResponse::success($this->settings->saveSection($match[1], $this->request->json()));
        }
        if ($path === '/admin/environments') {
            $this->request->assertMethod(['GET', 'POST']);
            return $this->request->method === 'POST'
                ? JsonResponse::success($this->environments->addEnvironment($this->request->json()), 201)
                : JsonResponse::success(['items' => $this->environments->environments()]);
        }
        if (preg_match('#^/admin/environments/([^/]+)$#D', $path, $match)) {
            $this->request->assertMethod(['DELETE']);
            return JsonResponse::success($this->environments->deleteEnvironment($match[1]));
        }
        if ($path === '/admin/storage') {
            $this->request->assertMethod(['GET']);
            return JsonResponse::success($this->maintenance->storage());
        }
        if ($path === '/admin/maintenance') {
            $this->request->assertMethod(['GET', 'PATCH']);
            Policy::require(Policy::superAdmin($this->actor));
            return JsonResponse::success($this->request->method === 'PATCH'
                ? $this->maintenance->selectBranch($this->request->json()) : $this->maintenance->maintenance());
        }
        if ($path === '/admin/operations') {
            $this->request->assertMethod(['POST']);
            Policy::require(Policy::superAdmin($this->actor));
            return JsonResponse::success($this->maintenance->planOperation($this->request->json()), 201);
        }
        if (preg_match('#^/admin/operations/([a-f0-9]{32})/(simulate|execute)$#D', $path, $match)) {
            $this->request->assertMethod(['POST']);
            Policy::require(Policy::superAdmin($this->actor));
            return JsonResponse::success($this->maintenance->runPlan($match[1], $match[2], $this->request->json()));
        }
        if ($path === '/cem/nodes') {
            $this->request->assertMethod(['GET']);
            return JsonResponse::success($this->nodes->nodes($this->request->query));
        }
        if (preg_match('#^/cem/nodes/([^/]+)$#D', $path, $match)) {
            $this->request->assertMethod(['GET']);
            return JsonResponse::success($this->nodes->nodeDetail($match[1]));
        }
        return null;
    }
}
