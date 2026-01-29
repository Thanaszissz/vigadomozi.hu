<?php
/**
 * Laravel public entry point wrapper
 * Routes all requests to backend/public/index.php
 */

define('LARAVEL_START', microtime(true));

// Register the Composer autoloader from backend
if (file_exists(__DIR__ . '/backend/vendor/autoload.php')) {
    require __DIR__ . '/backend/vendor/autoload.php';
}

// Bootstrap Laravel from backend
$app = require_once __DIR__ . '/backend/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

$response->send();

$kernel->terminate($request, $response);
