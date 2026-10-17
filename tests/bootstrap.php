<?php

declare(strict_types=1);

// This is deliberately not the application bootstrap. Never load config.php,
// webroot/index.php, core/autoloader.php, DBA/init.php, sessions, or services here.
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('The unit test bootstrap may only run from the CLI.');
}

define('CHRONOS_TEST_ROOT', dirname(__DIR__));
define('SERVER_ROOT', CHRONOS_TEST_ROOT);
date_default_timezone_set('Europe/Zurich');

// Make accidental access to an operational service fail before an application
// loader can connect to a database, run Git/mount, or contact another machine.
spl_autoload_register(static function (string $class): void {
    $forbidden = array_map('strtolower', [
        'API', 'Controller', 'System', 'SystemView', 'View', 'Auth_Library',
        'VCS_Library', 'Mount_Library', 'MAAS_Library', 'REST_Library',
        'Settings_Library', 'Systems_Library', 'Upload_Library', 'Demo_Library',
    ]);
    $normalizedClass = strtolower($class);
    if (in_array($normalizedClass, $forbidden, true)
        || str_starts_with($normalizedClass, 'dba\\') && str_ends_with($normalizedClass, 'factory')) {
        throw new LogicException('Operational services are forbidden in unit tests: ' . $class);
    }
}, true, true);

// These files contain inert class definitions only. The legacy Define file has
// leading whitespace outside PHP; suppress that whitespace, reject other output.
ob_start();
try {
    foreach ([
        'core/define.php',
        'core/util.php',
        'core/element.php',
        'core/plot.php',
        'libraries/builder.php',
        'libraries/cdl.php',
        'libraries/results.php',
        'libraries/dba/AbstractModel.class.php',
        'libraries/dba/models/Job.class.php',
    ] as $classFile) {
        require_once CHRONOS_TEST_ROOT . '/' . $classFile;
    }
} finally {
    $bootstrapOutput = ob_get_clean();
}
if (trim($bootstrapOutput) !== '') {
    throw new RuntimeException('The domain-only bootstrap produced unexpected output.');
}
