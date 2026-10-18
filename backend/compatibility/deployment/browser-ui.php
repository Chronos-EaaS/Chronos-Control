<?php
declare(strict_types=1);

// Historical package validators require this installed path. Normal requests
// use the namespaced class directly; this bridge is an explicit compatibility entry.
require_once dirname(__DIR__) . '/app/Http/BrowserUi.php';
if (!class_exists('BrowserUi', false)) class_alias(\Chronos\Http\BrowserUi::class, 'BrowserUi');
