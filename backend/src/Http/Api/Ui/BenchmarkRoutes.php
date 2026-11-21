<?php
declare(strict_types=1);

namespace Chronos\Http\Api\Ui;

use Chronos\Application\Benchmarks\BenchmarkCatalog;
use Chronos\Application\Projects\ProjectComparisons;
use Chronos\Application\Shared\{Context, Input};
use Chronos\Http\{JsonResponse, Request};
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;

final class BenchmarkRoutes
{
    private readonly Context $context;

    public function __construct(private readonly Request $request, Store $db, array $actor, string $root, ?Sessions $sessions = null)
    {
        $this->context = new Context($db, $actor, $root);
    }

    public function handle(string $path): ?array
    {
        if ($path === '/benchmarks') {
            $this->request->assertMethod(['GET', 'POST']);
            $catalog = new BenchmarkCatalog($this->context);
            return $this->request->method === 'GET' ? JsonResponse::success($catalog->list($this->request->query))
                : JsonResponse::success($catalog->publishBenchmark($this->request->json()), 201);
        }
        if (preg_match('#^/benchmarks/([1-9][0-9]*)/bindings$#D', $path, $match)) {
            $this->request->assertMethod(['POST']);
            return JsonResponse::success((new BenchmarkCatalog($this->context))->publishBinding(Input::id($match[1], 'benchmarkId'), $this->request->json()), 201);
        }
        if (preg_match('#^/projects/([1-9][0-9]*)/comparisons$#D', $path, $match)) {
            $this->request->assertMethod(['GET', 'POST']);
            $id = Input::id($match[1], 'projectId');
            $comparisons = new ProjectComparisons($this->context);
            return $this->request->method === 'GET' ? JsonResponse::success(['items' => $comparisons->list($id)])
                : JsonResponse::success($comparisons->create($id, $this->request->json()), 201);
        }
        return null;
    }
}
