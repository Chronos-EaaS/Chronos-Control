<?php
declare(strict_types=1);

namespace Chronos\Http\Api\V3;

use Chronos\Http\JsonResponse;

use Chronos\Http\HttpError;
use Chronos\Http\Request;
use Chronos\Infrastructure\Legacy\LegacyDatabase;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;

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
        return $this->store ??= new Store(LegacyDatabase::connection());
    }

    public function handle(): array
    {
        $path = substr($this->request->path, strlen('/api/v3'));
        if ($path === '/meta') {
            $this->request->assertMethod(['GET']);
            return JsonResponse::success((new \Chronos\Application\Metadata\MetadataService($this->root))->snapshot());
        }
        if ($path === '/session') {
            $this->request->assertMethod(['GET', 'POST', 'DELETE']);
            if ($this->request->method === 'GET') {
                return JsonResponse::success($this->sessions->snapshot());
            }
            $this->mutation();
            return JsonResponse::success($this->request->method === 'POST'
                ? $this->sessions->login($this->request->json()) : $this->sessions->logout());
        }

        $this->actor = $this->sessions->requireUser();
        if (!in_array($this->request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $this->mutation();
        }
        foreach ([UserRoutes::class, ProjectRoutes::class, CatalogRoutes::class, DashboardRoutes::class,
            PresetRoutes::class, ExtensionRoutes::class, WorkflowRoutes::class, SystemRoutes::class, AdminRoutes::class] as $module) {
            $response = (new $module($this->request, $this->db(), $this->actor, $this->root, $this->sessions))->handle($path);
            if ($response !== null) {
                return $response;
            }
        }
        throw new HttpError(404, 'not_found', 'This API resource does not exist.');
    }

    private function mutation(): void
    {
        $this->request->assertSameOrigin(Sessions::https() ? 'https' : 'http', $_SERVER['HTTP_HOST'] ?? '');
        $this->request->assertCsrf($this->sessions->token());
    }

}
