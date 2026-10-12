<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// A Laravel alkalmazás a webgyökér alatti backend/ mappában van.
$base = __DIR__ . '/backend';

if (file_exists($maintenance = $base . '/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $base . '/vendor/autoload.php';

(require_once $base . '/bootstrap/app.php')->handleRequest(Request::capture());
