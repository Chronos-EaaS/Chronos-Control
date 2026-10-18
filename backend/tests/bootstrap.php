<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') throw new RuntimeException('The unit bootstrap runs only from the CLI.');
define('CHRONOS_BACKEND_ROOT', dirname(__DIR__));
define('CHRONOS_REPOSITORY_ROOT', dirname(CHRONOS_BACKEND_ROOT));
define('CHRONOS_TEST_ROOT', CHRONOS_BACKEND_ROOT);
define('SERVER_ROOT', CHRONOS_BACKEND_ROOT);
date_default_timezone_set('Europe/Zurich');
// Composer loads inert PSR definitions only. No configuration, connection or sessions.
require_once CHRONOS_BACKEND_ROOT . '/vendor/autoload.php';
