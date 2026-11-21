<?php
declare(strict_types=1);

use Chronos\Http\Api\Ui\{BenchmarkRoutes, WorkflowRoutes};
use Chronos\Http\Api\V1\AgentRouter;
use Chronos\Http\{HttpError, Request};
use Chronos\Infrastructure\Persistence\{Connection, Store};

require_once __DIR__ . '/application-autoload.php';
// Agent responses and node reconciliation also use the production PSR logger.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = getenv('CHRONOS_EXECUTION_FIXTURE');
if (PHP_SAPI !== 'cli-server' || getenv('CHRONOS_TEST_MIGRATIONS') !== '1' || !is_file('/.dockerenv')
    || !is_string($root) || !preg_match('#^/tmp/chronos-execution-[a-f0-9]+$#D', $root)) exit(1);
define('SERVER_ROOT', $root);
define('LOG_DIRECTORY', $root . '/logs/');
define('UPLOADED_DATA_PATH', $root . '/webroot/data/');
define('SESSION_TIMEOUT', 3600);
define('ONLY_ACTIVATED_USERS', false);
date_default_timezone_set('Europe/Zurich');

Connection::configure('mysql:host=chronos-migration-db;dbname=chronos_migration_test;charset=utf8mb4', 'chronos_migration', 'chronos_migration_only');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/v1/')) {
    AgentRouter::dispatch($path, $_SERVER['REQUEST_METHOD']);
    return;
}

try {
    // Synthetic fixture actor only. Production requests authorize in the UI kernel.
    $actor = ['id' => ($_SERVER['HTTP_X_FIXTURE_OUTSIDER'] ?? '') === '1' ? 2 : 1, 'role' => 0];
    $db = new Store(Connection::get());
    $request = Request::fromGlobals();
    $path = substr($request->path, strlen('/api/ui'));
    $response = (new BenchmarkRoutes($request, $db, $actor, $root))->handle($path)
        ?? (new WorkflowRoutes($request, $db, $actor, $root))->handle($path)
        ?? throw new HttpError(404, 'not_found', 'Missing test route.');
    http_response_code($response['status']);
    foreach ($response['headers'] ?? [] as $name => $value) header($name . ': ' . $value);
    if (isset($response['stream'])) $response['stream']();
    else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response['body'], JSON_THROW_ON_ERROR);
    }
} catch (HttpError $error) {
    http_response_code($error->status);
    echo json_encode($error->body(), JSON_THROW_ON_ERROR);
}
