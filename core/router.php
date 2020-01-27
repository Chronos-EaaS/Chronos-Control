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
 * This controller routes all incoming requests to the appropriate controller
 */

// fetch the passed request
$request = $_SERVER['QUERY_STRING'];
$log->debug('query string: ' . $request);

// if $request is empty (user called www.domain.tld) show home
if ($request == '') {
    $page = DEFAULT_CONTROLLER;
    $action = DEFAULT_ACTION;
    $getVars = array();
    $parsed = null;
    $view_type = 'default';
    $log->debug('=> empty query string --> client calls www.domain.tld. Show ' . $page . '/' . $action);
} else {
    // there should be no / at the end of the string
    if (substr($request, -1, 1) == '/') {
        $request = substr($request, 0, strlen($request) - 1);
    }

    // parse the page request and other GET variables
    $parsed = explode('/', $request);
    array_shift($parsed);

    // the page is the first element
    $page = array_shift($parsed);

    if ($page == 'api') {
        $page = array_shift($parsed);
        if ($page == 'system') {
            $page = array_shift($parsed);
            $system = $page;
            $view_type = 'system-api';
            $log->debug('requested system-api');
        } else {
            $view_type = 'api';
            $api_version = $page;
            $page = array_shift($parsed);
            $log->debug('requested api --> api-version: ' . $api_version);
        }

    } else if ($page == 'system') {
        $page = array_shift($parsed);
        $system = $page;
        $view_type = 'system';
        $log->debug('requested system --> view type: ' . $view_type);
    } else {
        $view_type = 'default';
    }

    // the action is the second element
    if ($view_type == 'api') {
        $action = strtolower($_SERVER['REQUEST_METHOD']);
    } else {
        $action = array_shift($parsed);
    }
    $log->debug('requested action: ' . $action);

    // the rest of the array are get statements, parse them out.
    $getVars = array();
    foreach ($parsed as $argument) {
        // split GET vars along '=' symbol to separate variable, values
        if (strpos($argument, "=") !== false) {
            list($variable, $value) = preg_split('/=/', $argument);
            $getVars[$variable] = urldecode($value);
        }
    }
    $log->debug('following get-Vars were recognized: ' . print_r($getVars, true));
}

// compute the path to the file
if (isset($system)) {
    $target = SERVER_ROOT . '/webroot/systems/' . $system . '/' . $system . '.php';
} else if ($view_type == 'api') {
    $target = SERVER_ROOT . '/api/' . $api_version . '/' . $page . '.php';
} else {
    $target = SERVER_ROOT . '/controllers/' . $page . '.php';
}
$log->debug('path to controller: ' . $target);

$found = true;

// get target
if (file_exists($target)) {
    include_once($target);

    // modify page to fit naming convention
    if (isset($system)) {
        $class = ucfirst($system) . '_System';
    } else if ($view_type == 'api') {
        $class = ucfirst($page) . '_API';
    } else {
        $class = ucfirst($page) . '_Controller';
    }


    // instantiate the appropriate class
    if (class_exists($class)) {
        if (isset($system)) {
            $controller = new $class($getVars, $system);
        } else {
            $controller = new $class($getVars);
        }
    } else {
        // class does not exist!
        $found = false;
        $log->debug('=> file found, but class does not exist: ' . $target);
        die('file found, but class does not exist: ' . $target);
    }

    // check if function exists
    if (!method_exists($controller, $action)) {
        // function does not exist!
        $found = false;
        $log->debug('=> class found, but action does not exist: ' . $action);
        die('=> class found, but action does not exist: ' . $action);
    }
    $log->debug('controller found and instantiated: ' . $class);
} else {
    // can't find the file in 'controllers'!
    $found = false;
    $log->debug('=> file does not exist: ' . $target);
}

if (!$found) {
    // Show 404 Page
    $page = 'home';
    $action = 'error404';
    $getVars = array();

    $target = SERVER_ROOT . '/controllers/home.php';
    include_once($target);

    $class = ucfirst($page) . '_Controller';
    $controller = new $class($getVars);
    $log->debug('=> show 404 page: ' . $page . '/' . $action);
    $system = null;
}

// check if allowed to access
$allowed = false;
$s = $action . '_access';
if (!empty($controller->$s)) {
    $level = $controller->$s;
    if ($level == Auth_Library::A_PUBLIC) {
        $allowed = true;
        $log->debug('access granted! access level is: public');
    } else if ($level == Auth_Library::A_LOGGEDIN && $auth->isLoggedIn()) {
        $allowed = true;
        $log->debug('access granted! access level is: loggedin');
    } else if ($level == Auth_Library::A_ADMIN && $auth->isLoggedIn() && $auth->isAdmin()) {
        $allowed = true;
        $log->debug('access granted! access level is: admin');
    } else if ($level == Auth_Library::A_SUPERADMIN && $auth->isLoggedIn() && $auth->isSuperAdmin()) {
        $allowed = true;
        $log->debug('access granted! access level is: superadmin');
    }
} else {
    $log->warning('access level definition (' . $s . ') does not exist --> access denied!');
}

if (!$allowed) {
    $log->info('access denied!');
    if ($auth->isLoggedIn()) {
        // Show 403 Page
        $page = 'home';
        $action = 'error403';
        $getVars = array();
        $target = SERVER_ROOT . '/controllers/home.php';
        include_once($target);
        $log->debug('=> user is authenticated (' . $auth->getUser()->getUsername() . ') --> show 403 page: ' . $page . '/' . $action);
    } else {
        // Login
        if ($page != 'user' || $action != 'login') {
            $redirect = "/" . $page . "/" . $action;
            if (sizeof($getVars) > 0) {
                $vars = [];
                foreach ($getVars as $key => $value) {
                    $vars[] = $key . "=" . urlencode($value);
                }
                $redirect .= "/".implode("/", $vars);
            }
            $_SESSION['redirect'] = $redirect;
        }
        $page = 'user';
        $action = 'login';
        $getVars = array();
        $target = SERVER_ROOT . '/controllers/user.php';
        include_once($target);
        $log->debug('=> not authenticated --> show login page: ' . $page . '/' . $action);
    }

    $class = ucfirst($page) . '_Controller';
    $controller = new $class($getVars);
    $system = null;
}

// Save page in history
if ($found && $allowed && !$_POST && $page != 'file' && $view_type != 'system-api' && $view_type != 'api') {
    History_Library::add($page, $action, $parsed);
    $log->debug('save page in history');
}

if (isset($system)) {
    if ($view_type == 'system-api') {
        $controller->view = new SystemView('', $view_type);
    } else {
        $controller->view = new SystemView($system . '/views/' . $action, $view_type);
    }
} else if ($view_type == 'system') { // system is null but view type is set to system. This means that something gone wrong. Change view type back to default for showing error page
    $view_type = 'default';
    $controller->view = new View($page . '/' . $action, $view_type);
} else if ($view_type != 'api') { // API has no view
    $controller->view = new View($page . '/' . $action, $view_type);
}

try {
    if (method_exists($controller, '__before')) {
        $controller->__before($action);
    }
    $controller->$action();
} catch (Exception $e) {
    $log->info('exception thrown: ' . $e);
    if ($view_type == 'api') {
        $controller->setError($e->getMessage());
    } else {
        $controller->view->setError($e);
    }
    die();
}

