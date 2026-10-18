<?php

/*
The MIT License (MIT)

Copyright (c) 2018 The Chronos Project

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
 */

use Chronos\Http\BrowserUi;
use Chronos\Http\Api\Ui\ApiPath;
use Chronos\Infrastructure\Persistence\Connection;
use Chronos\Infrastructure\Security\Authentication;
use Chronos\Infrastructure\Logging\Logger;
use Chronos\Infrastructure\Deployment\ApplicationLock;

define('SERVER_ROOT', dirname(__DIR__));
define('SITE_ROOT', $_SERVER['SERVER_NAME'] ?? 'localhost');

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$maintenanceResponse = static function () use ($requestPath): never {
    http_response_code(503);
    header('Retry-After: 30'); header('Cache-Control: no-store');
    if (str_starts_with($requestPath, '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":{"code":"maintenance","message":"Chronos is being updated. Please retry shortly."}}';
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Chronos maintenance</title><body><main><h1>Chronos is being updated</h1><p>Please retry shortly. Your stored experiments and results are retained.</p><button onclick="location.reload()">Retry</button></main></body></html>';
    }
    exit;
};
if (is_file(SERVER_ROOT . '/.chronos-maintenance.json') || is_link(SERVER_ROOT . '/.chronos-maintenance.json')) $maintenanceResponse();
require_once SERVER_ROOT . '/app/Infrastructure/Deployment/ApplicationLock.php';
try {
    if (!ApplicationLock::enterRequest(SERVER_ROOT)) $maintenanceResponse();
} catch (Throwable $error) {
    error_log('Chronos request lock failed: ' . $error->getMessage());
    $maintenanceResponse();
}


require SERVER_ROOT . '/constants.php';
if (!is_readable(SERVER_ROOT . '/config.php')) {
    define('CHRONOS_INSTALL_ROOT', SERVER_ROOT);
    require SERVER_ROOT . '/install.php';
    exit;
}
require SERVER_ROOT . '/config.php';
require SERVER_ROOT . '/vendor/autoload.php';
Connection::configureFromConstants();
setlocale(LC_TIME, 'deu', 'de_DE.UTF-8');
date_default_timezone_set('Europe/Zurich');
header('Content-Type: text/html; charset=utf-8');
$isUiRequest = ApiPath::relative($requestPath) !== null;
$isAgentRequest = $requestPath === '/api/v1' || str_starts_with($requestPath, '/api/v1/');
if (((!$isAgentRequest && REQUIRE_SSL) || ($isAgentRequest && REQUIRE_API_SSL)) && $_SERVER['SERVER_PORT'] != 443) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit;
}
if (!$isUiRequest && BrowserUi::serve(SERVER_ROOT, $requestPath)) exit;
try {
    $migrations = require SERVER_ROOT . '/database.php';
    if (!is_array($migrations) || ($migrations['format'] ?? null) !== 1 || !($migrations['run'] ?? null) instanceof Closure) throw new RuntimeException('The database migration runtime is invalid.');
    $migrations['run'](Connection::get(), 'assert');
} catch (Throwable $error) {
    error_log('Chronos database schema check failed: ' . get_class($error));
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('Retry-After: 30');
    echo '{"error":{"code":"database_upgrade_required","message":"The database schema is unavailable or does not match this application. Complete database migration or deployment recovery before retrying."}}';
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', DEBUGMODE ? 'On' : 'Off');
ini_set('log_errors', 'On');
if (DEBUGMODE) ini_set('error_log', SERVER_ROOT . '/logs/error.log');
$log = Logger::getInstance();
$log->setLogLevelThreshold(LOG_LEVEL);
$log->debug('--------- request from: ' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' ---------');
$auth = Authentication::getInstance();
require SERVER_ROOT . '/api/' . ($isUiRequest ? 'ui' : 'v1') . '/bootstrap.php';
