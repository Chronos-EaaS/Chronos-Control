<?php
declare(strict_types=1);

namespace Chronos\Http\Api\V1;

use Chronos\Infrastructure\Security\Authentication;

use RuntimeException;
use Throwable;


/** Supported agent endpoints; browser controllers and system PHP pages are excluded. */
final class AgentRouter
{
    public static function route(string $path, string $method): ?array
    {
        if (!preg_match('#^/api/v1/(job|cem)(?:/(.*))?/?$#D', $path, $match)) return null;
        $action = strtolower($method);
        $allowed = $match[1] === 'job' ? ['get', 'post', 'patch'] : ['get', 'post'];
        $variables = [];
        foreach (explode('/', rtrim($match[2] ?? '', '/')) as $argument) {
            if (!str_contains($argument, '=')) continue;
            [$name, $value] = explode('=', $argument, 2);
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) continue;
            $variables[$name] = urldecode($value);
        }
        return ['endpoint' => $match[1], 'action' => $action, 'allowed' => in_array($action, $allowed, true), 'variables' => $variables, 'methods' => array_map('strtoupper', $allowed)];
    }

    public static function dispatch(string $path, string $method): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $route = self::route($path, $method);
        if ($route === null || !$route['allowed']) {
            http_response_code($route === null ? 404 : 405);
            if ($route !== null) header('Allow: ' . implode(', ', $route['methods']));
            echo json_encode(['status' => ['code' => 600, 'message' => 'This agent endpoint or method is not available.']]);
            return;
        }
        $class = $route['endpoint'] === 'cem' ? CemController::class : JobController::class;
        $controller = new $class($route['variables']);
        $access = $route['action'] . '_access';
        try {
            if (($controller->$access ?? null) !== Authentication::A_PUBLIC) throw new RuntimeException('This agent action is not accessible.');
            $controller->{$route['action']}();
        } catch (Throwable $error) {
            $controller->setError($error->getMessage());
        }
        // The unchanged API base class renders the established numeric envelope.
    }
}
