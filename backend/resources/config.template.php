<?php

define('DEBUGMODE', false);

//define('LOG_LEVEL', 'debug'); // See LogLevels for details
define('LOG_LEVEL', 'notice');

define('SITE_NAME', 'Chronos Control');

define('PASSWORDS_MIN_LENGTH', 12);
define('PASSWORDS_MAX_LENGTH', 72);
define('REQUIRE_PASSWORD_COMPLEXITY', false);

define('ONLY_ACTIVATED_USERS', false);

// How long a session (login) stays active
define('SESSION_TIMEOUT', 36000);

// How long should 'remember me' (login) be stored (30 days) in seconds
define('REMEMBER_ME_COOKIE_LIFETIME', 2592000);

// Only allow access via https
define('REQUIRE_SSL', false);
define('REQUIRE_API_SSL', false);

// Enable remote repository support for systems
// Attention: This is a severe security risk!
define('ENABLE_REMOTE_REPOSITORY', false);

// Logger-Options
define('LOG_DIRECTORY', SERVER_ROOT . '/logs/');

// Lock-Files
define('LOCK_DIRECTORY', SERVER_ROOT . '/locks/');
define('LOCK_SUFFIX', '.lock');

// Where to store uploads
define('UPLOADED_MEDIA_PATH', SERVER_ROOT . '/webroot/media/');
define('UPLOADED_MEDIA_PATH_RELATIVE', '/media/');
define('UPLOADED_DATA_PATH', SERVER_ROOT . '/webroot/data/');
define('UPLOADED_DATA_PATH_RELATIVE', '/data/');

// Database settings (MySQL or MariaDB)
define('DB_TYPE', 'mysql');
define('DB_HOST', '__DB_HOST__');
define('DB_USER', '__DB_USER__');
define('DB_PASSWORD', '__DB_PASS__');
define('DB_NAME', '__DB_NAME__');
define('DB_PORT', 3306);


// Application updates use immutable CI packages; runtime Node.js/Composer are not required.
define('CHRONOS_ENABLE_UPDATES', true);

// Optional privileged maintenance must be enabled explicitly for this installation.
define('CHRONOS_ENABLE_STORAGE_MOUNT', false);
define('CHRONOS_ENABLE_DEMO_RESET', false);
define('CHRONOS_ENABLE_LOCAL_SYSTEM_RESET', false);
