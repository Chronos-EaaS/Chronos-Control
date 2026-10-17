<?php

/*
The MIT License (MIT)

Copyright (c) 2018 Databases and Information Systems Research Group,
University of Basel, Switzerland

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

/**
 * Define document paths
 */

use DBA\Factory;

define('SERVER_ROOT', realpath(dirname(__FILE__) . "/.."));
define('SITE_ROOT', $_SERVER['SERVER_NAME']);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (is_file(SERVER_ROOT . '/.chronos-maintenance.json')) {
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
}

include('../constants.php');

/**
 * include config
 */
if (!is_readable(SERVER_ROOT . '/config.php')) {
    define('CHRONOS_INSTALL_ROOT', SERVER_ROOT);
    require SERVER_ROOT . '/install.php';
    exit;
}
include(SERVER_ROOT . '/config.php');
require_once(SERVER_ROOT . '/libraries/dba/init.php');
require_once(SERVER_ROOT . '/libraries/templating/init.php');


/**
 * Set local
 */
setlocale(LC_TIME, 'deu', 'de_DE.UTF-8');
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Europe/Zurich');


/**
 * If page requires SSL, and we're not in SSL mode,
 * redirect to the SSL version of the page
 */
$v3RequestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$isV3Request = is_string($v3RequestPath) && ($v3RequestPath === '/api/v3' || str_starts_with($v3RequestPath, '/api/v3/'));
if (REQUIRE_SSL && $_SERVER['SERVER_PORT'] != 443 && !$isV3Request && substr($_SERVER['QUERY_STRING'], 0, 5) != '/api/') {
    //header("HTTP/1.1 301 Moved Permanently");
    header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit();
}

if (REQUIRE_API_SSL && $_SERVER['SERVER_PORT'] != 443 && ($isV3Request || substr($_SERVER['QUERY_STRING'], 0, 5) == '/api/')) {
    header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit();
}

if (!$isV3Request) {
    require_once SERVER_ROOT . '/core/browserui.php';
    if (BrowserUi::serve(SERVER_ROOT, $requestPath)) exit;
}

/**
 * include the autoloader
 */
require_once SERVER_ROOT . '/vendor/autoload.php';
require_once(SERVER_ROOT . '/core/' . 'autoloader.php');


/**
 * Instantiate Logger
 */
$log = Logger_Library::getInstance();
$log->setLogLevelThreshold(LOG_LEVEL);
$log->debug('--------- request from: ' . $_SERVER['REMOTE_ADDR'] . ' ---------');

/**
 * Error reporting.
 */
if (DEBUGMODE) {
    error_reporting(E_ALL | E_STRICT);
    ini_set('display_errors', 'On');
    ini_set('log_errors', 'On');
    ini_set('error_log', SERVER_ROOT . '/logs/error.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 'Off');
    ini_set('log_errors', 'On');
    //ini_set('error_log', ROOT.DS.'tmp'.DS.'logs'.DS.'error.log');
}


/**
 * Include Auth-Library
 */
require_once(SERVER_ROOT . '/libraries/' . 'auth.php');
$auth = Auth_Library::getInstance();

// The browser API uses conventional paths/query parameters and its own JSON
// boundary. Keep agent v1 and legacy UI dispatch unchanged during migration.
if ($isV3Request) {
    require SERVER_ROOT . '/api/v3/bootstrap.php';
}


/**
 * Fetch the router
 */
require_once(SERVER_ROOT . '/core/' . 'router.php');
