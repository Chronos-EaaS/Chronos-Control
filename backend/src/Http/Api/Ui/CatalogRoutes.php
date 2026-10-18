<?php
declare(strict_types=1);

namespace Chronos\Http\Api\Ui;

use Chronos\Http\JsonResponse;

use Chronos\Http\Request;
use Chronos\Application\Shared\Context;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;

use Chronos\Application\Systems\{SystemCatalog, SystemCreation};

final class CatalogRoutes
{
    public function __construct(private readonly Request $request, private readonly Store $db, private readonly array $actor, private readonly string $root, Sessions $sessions)
    {
    }

    public function handle(string $path): ?array
    {
        if ($path !== '/systems') return null;
        $this->request->assertMethod(['GET', 'POST']);
        $context = new Context($this->db, $this->actor, $this->root);
        return $this->request->method === 'GET'
            ? JsonResponse::success((new SystemCatalog($context))->systems($this->request->query))
            : JsonResponse::success((new SystemCreation($context))->createSystem($this->request->json()), 201);
    }
}
