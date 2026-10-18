<?php
declare(strict_types=1);

// Historical package validators and upgrade tools require this installed path.
// Application requests use the namespaced deployment service directly.
require_once dirname(__DIR__) . '/app/Infrastructure/Deployment/ReleaseManager.php';
if (!class_exists('Release_Library', false)) class_alias(\Chronos\Infrastructure\Deployment\ReleaseManager::class, 'Release_Library');
