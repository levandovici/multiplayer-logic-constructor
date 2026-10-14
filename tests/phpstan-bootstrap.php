<?php

// PHPStan bootstrap — symbol discovery only, never executed.
// Defines the runtime constants/globals bootstrap.php creates, so analysis
// of entry points (bin/, cron/, public_html/) resolves them.

define('APP_SRC', dirname(__DIR__) . '/src');
define('APP_ROOT', dirname(__DIR__));

require_once APP_SRC . '/helpers.php';
require_once APP_ROOT . '/vendor/autoload.php';
