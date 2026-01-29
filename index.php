<?php
/**
 * Laravel public entry point wrapper
 * Routes all requests to the Laravel backend installation
 */

define('LARAVEL_START', microtime(true));

$basePath = __DIR__ . '/backend';

// Check if backend vendor exists
if (!file_exists($basePath . '/vendor/autoload.php')) {
    http_response_code(500);
    die('Error: Laravel dependencies not installed. Backend vendor folder missing.');
}

// Register Composer autoloader
require $basePath . '/vendor/autoload.php';

// Bootstrap Laravel application
$app = require_once $basePath . '/bootstrap/app.php';

// Handle the request
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

$response->send();

$kernel->terminate($request, $response);
