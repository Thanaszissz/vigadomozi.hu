<?php
/**
 * Laravel public entry point wrapper
 * Routes all requests to backend/public/index.php
 */

// Debug mode - remove this after successful deployment
$debug = false;
if (isset($_GET['debug']) || !file_exists(__DIR__ . '/backend/.env')) {
    $debug = true;
    echo "<h1>Laravel Debug Info</h1>";
    echo "<p>Backend path: " . __DIR__ . "/backend</p>";
    echo "<p>.env exists: " . (file_exists(__DIR__ . '/backend/.env') ? 'YES' : 'NO') . "</p>";
    echo "<p>vendor exists: " . (file_exists(__DIR__ . '/backend/vendor/autoload.php') ? 'YES' : 'NO') . "</p>";
    echo "<p>bootstrap/app.php exists: " . (file_exists(__DIR__ . '/backend/bootstrap/app.php') ? 'YES' : 'NO') . "</p>";
    
    if (file_exists(__DIR__ . '/backend/.env')) {
        $env = file_get_contents(__DIR__ . '/backend/.env');
        echo "<p>APP_KEY set: " . (strpos($env, 'APP_KEY=base64:') !== false ? 'YES' : 'NO - Run artisan key:generate') . "</p>";
    }
    
    if (!file_exists(__DIR__ . '/backend/vendor/autoload.php')) {
        echo "<p><strong>ERROR: Run 'composer install' in backend directory</strong></p>";
    }
    exit;
}

define('LARAVEL_START', microtime(true));

// Register the Composer autoloader from backend
if (file_exists(__DIR__ . '/backend/vendor/autoload.php')) {
    require __DIR__ . '/backend/vendor/autoload.php';
} else {
    die('Composer dependencies not installed. Run: cd backend && composer install');
}

// Bootstrap Laravel from backend
$app = require_once __DIR__ . '/backend/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

$response->send();

$kernel->terminate($request, $response);
