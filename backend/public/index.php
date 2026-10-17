<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Gyors kiszolgalas a Laravel inditasa nelkul a gyakori publikus listakhoz (filmek, elo adasok, musor).
// A gyorsitotarat az App\Http\Middleware\StorePublicListCache tolti, torlese az AppServiceProvider-ben van.
// A jogosult URL-ek szabalyai megegyeznek az App\Support\ResponseCache::ttlFor() szabalyaival.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $cacheUri = $_SERVER['REQUEST_URI'] ?? '';
    $cacheParts = explode('?', $cacheUri, 2);
    $cacheQuery = $cacheParts[1] ?? '';
    $cacheOk = strlen($cacheUri) <= 120 && (
        (preg_match('#^/api/showtimes/\d{1,9}$#', $cacheParts[0]) === 1 && $cacheQuery === '')
        || (
            in_array($cacheParts[0], ['/api/movies', '/api/theater-shows', '/api/showtimes'], true)
            && ($cacheQuery === '' || preg_match('/^(date=\d{4}-\d{2}-\d{2}|movie_id=\d{1,9}|theater_show_id=\d{1,9})$/', $cacheQuery) === 1)
        )
    );
    if ($cacheOk) {
        $cacheBase = __DIR__.'/../storage/framework/response-cache/'.md5($cacheUri);
        $cacheMtime = @filemtime($cacheBase.'.meta');
        $cacheMeta = $cacheMtime !== false ? json_decode((string) @file_get_contents($cacheBase.'.meta'), true) : null;
        if (is_array($cacheMeta) && time() - $cacheMtime < (int) ($cacheMeta['ttl'] ?? 0)) {
            $cacheGzip = str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') && is_file($cacheBase.'.gz');
            $cacheFile = $cacheBase.($cacheGzip ? '.gz' : '.json');
            if (is_file($cacheFile)) {
                header('Content-Type: application/json');
                header('Cache-Control: public, max-age='.(int) $cacheMeta['ttl']);
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: DENY');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
                header('Cross-Origin-Resource-Policy: cross-origin');
                header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
                if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
                }
                header('Vary: Origin, Accept-Encoding');
                $cacheOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
                if ($cacheOrigin !== '' && in_array($cacheOrigin, $cacheMeta['origins'] ?? [], true)) {
                    header('Access-Control-Allow-Origin: '.$cacheOrigin);
                    header('Access-Control-Expose-Headers: Retry-After');
                }
                if ($cacheGzip) {
                    header('Content-Encoding: gzip');
                }
                header('Content-Length: '.filesize($cacheFile));
                header('X-Cache: HIT');
                readfile($cacheFile);
                exit;
            }
        }
    }
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
