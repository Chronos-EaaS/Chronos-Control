<?php
declare(strict_types=1);

// Historical package validators require this installed path. The installer
// loads the namespaced implementation directly without application bootstrap.
require_once dirname(__DIR__) . '/app/Infrastructure/Deployment/Installer.php';
if (!class_exists('Install_Library', false)) class_alias(\Chronos\Infrastructure\Deployment\Installer::class, 'Install_Library');
