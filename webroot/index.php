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

ini_set("apc.enabled", "0");


include('../constants.php');

/**
 * include config
 */
if (!is_readable(SERVER_ROOT . '/config.php')) {
    die('config.php not found!');
}
include(SERVER_ROOT . '/config.php');
require_once(SERVER_ROOT . '/libraries/dba/init.php');
require_once(SERVER_ROOT . '/libraries/templating/init.php');
$FACTORIES = new Factory();


/**
 * Set local
 */
setlocale(LC_TIME, 'deu', 'de_DE.UTF-8');
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Europe/Berlin');


/**
 * If page requires SSL, and we're not in SSL mode,
 * redirect to the SSL version of the page
 */
if (REQUIRE_SSL && $_SERVER['SERVER_PORT'] != 443 && substr($_SERVER['QUERY_STRING'], 0, 5) != '/api/') {
    //header("HTTP/1.1 301 Moved Permanently");
    header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit();
}

if (REQUIRE_API_SSL && $_SERVER['SERVER_PORT'] != 443 && substr($_SERVER['QUERY_STRING'], 0, 5) == '/api/') {
    header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit();
}


/**
 * include the autoloader
 */
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


/**
 * Fetch the router
 */
require_once(SERVER_ROOT . '/core/' . 'router.php');
