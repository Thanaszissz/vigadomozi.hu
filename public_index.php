<?php
/**
 * Laravel public/index.php wrapper for hosting platforms
 * Routes requests to the actual Laravel installation in /backend/public
 */
$basePath = __DIR__ . '/backend';

if (!is_dir($basePath) || !file_exists($basePath . '/public/index.php')) {
    http_response_code(500);
    echo "Error: Laravel installation not found in {$basePath}/public/index.php\n";
    exit(1);
}

// Include the actual Laravel index.php
require $basePath . '/public/index.php';
