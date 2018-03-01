<?php

define('DEBUGMODE', true);

//define('LOG_LEVEL', 'debug'); // See LogLevels for details
define('LOG_LEVEL', 'notice');

define('SITE_NAME', 'Chronos Control');
define('ERROR_TEXT', 'system error!');

define('PASSWORDS_MIN_LENGTH', 4);
define('PASSWORDS_MAX_LENGTH', 30);
define('REQUIRE_PASSWORD_COMPLEXITY', false);

define('ONLY_ACTIVATED_USERS', false);
define('SEND_ACTIVATION_MAIL', false);

// set default controller and action
define('DEFAULT_CONTROLLER', 'home');
define('DEFAULT_ACTION', 'main');

// set the default design
define('DEFAULT_DESIGN', 'default');
// set the default template
define('DEFAULT_TEMPLATE', null);

// Session timeout (10 hours)
define('SESSION_TIMEOUT', 36000);

// How long should 'remember me' (login) be stored (30 days) in seconds
define('REMEMBER_ME_COOKIE_LIFETIME', 2592000);

// Only allow access via https
define('REQUIRE_SSL', false);
define('REQUIRE_API_SSL', false);

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

// VCS settings
define('REPOSITORY_TYPE', '__REPO_TYPE__');
define('REPOSITORY_URL', '__REPO_URL__');
define('REPOSITORY_USER', '__REPO_USER__');
define('REPOSITORY_PASS', '__REPO_PASS__');
define('REPOSITORY_BRANCH', '__REPO_BRANCH__');


/**
 * Define Database
 */
define('DB_TYPE', 'mysql');
define('DB_HOST', '__DB_HOST__');
define('DB_USER', '__DB_USER__');
define('DB_PASSWORD', '__DB_PASS__');
define('DB_NAME', '__DB_NAME__');
define('DB_PORT', 3306);


/**
 * Define Mail
 * If MAIL_USERNAME is empty, no auth. If MAIL_HOST is empty, send from this server
 */
define('MAIL_HOST', '');
define('MAIL_PORT', 25);
define('MAIL_USERNAME', '');
define('MAIL_PASSWORD', '');
define('MAIL_FROM', 'chronos@unibas.ch');
define('MAIL_FROMNAME', 'Chronos Control');


/**
 * FTP Data Upload Config
 * (This data is provided to the agents)
 */
define('FTP_SERVER', 'labcl-nas.dmi.unibas.ch');
define('FTP_PORT', 21);
define('FTP_USERNAME', 'USERNAME');
define('FTP_PASSWORD', 'PASSWORD');


// The cluster network (used e.g. to decide if the results should be uploaded directly via ftp)
define('LOCAL_NETWORK_CIDR', '10.34.58.0/24');
define('USE_FTP_UPLOAD_FOR_LOCAL_CLIENTS', true);


/**
 * Other Config
 */
define('ROWS_PER_PAGE', 20); // Number of (table) Rows per page
define('DESCRIPTION_LENGTH', 300);
define('MAX_JOBS_PER_EVALUATION', 1000);

