<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Realtime;

use Chronos\Application\Cem\NodeService;
use Chronos\Application\Metadata\MetadataService;
use Chronos\Application\Security\Policy;
use Chronos\Application\Shared\Context;
use Chronos\Http\Api\Ui\CatalogRoutes;
use Chronos\Http\Api\Ui\DashboardRoutes;
use Chronos\Http\Api\Ui\ProjectRoutes;
use Chronos\Http\Api\Ui\SystemRoutes;
use Chronos\Http\Api\Ui\WorkflowRoutes;
use Chronos\Http\HttpError;
use Chronos\Http\Request;
use Chronos\Infrastructure\Deployment\ApplicationLock;
use Chronos\Infrastructure\Persistence\Connection;
use Chronos\Infrastructure\Persistence\Store;
use PDO;

/** Read-only socket projections share the HTTP resource services and their authorization checks. */
final class LiveProjection
{
    public function __construct(private readonly string $root, private readonly ?PDO $database = null)
    {
    }

    public function read(?string $lease, string $path, array $query): array
    {
        try {
            clearstatcache();
            if (!ApplicationLock::enterRequest($this->root)) {
                throw new HttpError(503, 'maintenance', 'Chronos is being updated. Please retry shortly.');
            }
            if (str_starts_with($path, '/api/ui/')) {
                $path = substr($path, 7);
            }
            if (strlen($path) > 1024 || strlen(json_encode($query, JSON_THROW_ON_ERROR)) > 8192 || !self::allowed($path)) {
                throw new HttpError(404, 'not_found', 'This live resource does not exist.');
            }
            if ($path === '/meta') {
                return ['ok' => true, 'data' => (new MetadataService($this->root))->snapshot()];
            }
            $database = $this->database ?? Connection::get();
            $identity = (new LiveTickets($this->root, $database))->validate($lease) ?? throw new HttpError(401, 'authentication_required', 'Please sign in to continue.');
            if ($path === '/session') {
                return ['ok' => true, 'data' => $identity['session']];
            }
            if ($path === '/profile') {
                return ['ok' => true, 'data' => $identity['actor']];
            }
            $request = new Request('GET', '/api/ui' . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)));
            $store = new Store($database);
            $actor = $identity['actor'];
            if (str_starts_with($path, '/cem/nodes')) {
                Policy::require(Policy::admin($actor));
                $nodes = new NodeService(new Context($store, $actor, $this->root));
                return ['ok' => true, 'data' => $path === '/cem/nodes' ? $nodes->nodes($request->query) : $nodes->nodeDetail(substr($request->path, strlen('/api/ui/cem/nodes/')))];
            }
            foreach ([ProjectRoutes::class, CatalogRoutes::class, DashboardRoutes::class, WorkflowRoutes::class, SystemRoutes::class] as $module) {
                $response = (new $module($request, $store, $actor, $this->root))->handle($request->path === '/api/ui' ? '/' : substr($request->path, 7));
                if ($response !== null) {
                    if (isset($response['stream']) || ($response['status'] ?? 500) !== 200 || !array_key_exists('data', $response['body'] ?? [])) {
                        throw new HttpError(404, 'not_found', 'This live resource does not exist.');
                    }
                    return ['ok' => true, 'data' => $response['body']['data']];
                }
            }
            throw new HttpError(404, 'not_found', 'This live resource does not exist.');
        } catch (HttpError $error) {
            return ['ok' => false, 'status' => $error->status, 'error' => $error->body()['error']];
        } catch (\Throwable $error) {
            error_log('Chronos live projection failed: ' . get_class($error));
            return ['ok' => false, 'status' => 500, 'error' => ['code' => 'internal_error', 'message' => 'The live resource could not be read.']];
        } finally {
            ApplicationLock::leaveRequest();
        }
    }

    public static function allowed(string $path): bool
    {
        if (in_array($path, ['/meta', '/session', '/profile', '/dashboard', '/projects', '/systems', '/jobs', '/evaluations', '/status', '/cem/nodes'], true)) {
            return true;
        }
        return preg_match('~^/(?:projects/[1-9][0-9]*(?:/(?:experiments|experiment-options|evaluations|events|members))?|experiments/[1-9][0-9]*(?:/(?:evaluations|run-options|events|parameters|result-configurations))?|evaluations/[1-9][0-9]*(?:/(?:jobs|events|results))?|jobs/[1-9][0-9]*(?:/(?:log|events|cdl|results))?|systems/[1-9][0-9]*(?:/(?:parameters|result-configurations|settings|environments|history|repository|maintainers))?|cem/nodes/[^/\\\\?#\x00-\x1f]{1,192})$~D', $path) === 1;
    }
}
