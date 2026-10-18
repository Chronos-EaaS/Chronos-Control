<?php
declare(strict_types=1);

use Chronos\Http\Api\V1\AgentRouter;

\Chronos\Http\Api\V1\AgentRouter::dispatch($requestPath, $_SERVER['REQUEST_METHOD'] ?? 'GET');
