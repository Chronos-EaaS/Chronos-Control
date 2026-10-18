<?php
declare(strict_types=1);

namespace Chronos\Http\Api\Ui;

use Chronos\Application\Extensions\ExtensionService;
use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Input;
use Chronos\Http\JsonResponse;
use Chronos\Http\Request;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;

final class ExtensionRoutes
{
    private readonly ExtensionService $extensions;

    public function __construct(private readonly Request $request, Store $db, array $actor, string $root, Sessions $sessions)
    {
        $this->extensions = new ExtensionService(new Context($db, $actor, $root));
    }

    public function handle(string $path): ?array
    {
        if (!preg_match('#^/systems/([1-9][0-9]*)/(extensions|pages/([a-z][a-z0-9-]{0,63}))$#D', $path, $match)) return null;
        $this->request->assertMethod(['GET']);
        return JsonResponse::success($this->extensions->read(Input::id($match[1], 'systemId'), $match[3] ?? null));
    }
}
