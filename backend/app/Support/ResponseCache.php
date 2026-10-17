<?php

namespace App\Support;

/**
 * A gyakran lekért, publikus listák (filmek, előadások, műsor) rövid életű fájl-gyorsítótára.
 * A tárolt válaszokat a public/index.php a Laravel elindítása nélkül szolgálja ki (a Laravel indulása a tárhelyen
 * kérésenként ~200 ms), a módosításkor (AuditObserver) a gyorsítótár azonnal törlődik.
 *
 * A "jogosult URL" szabályait a public/index.php is tartalmazza (ott nincs autoloader), a kettőt együtt kell módosítani.
 */
class ResponseCache
{
    public const TTL_SECONDS = 30;

    public static function dir(): string
    {
        return storage_path('framework/response-cache');
    }

    public static function eligible(string $uri): bool
    {
        if (strlen($uri) > 120) {
            return false;
        }

        $parts = explode('?', $uri, 2);
        if (!in_array($parts[0], ['/api/movies', '/api/theater-shows', '/api/showtimes'], true)) {
            return false;
        }

        $query = $parts[1] ?? '';

        return $query === '' || preg_match('/^(date=\d{4}-\d{2}-\d{2}|movie_id=\d{1,9}|theater_show_id=\d{1,9})$/', $query) === 1;
    }

    public static function store(string $uri, string $json): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $key = md5($uri);
        $write = function (string $file, string $content) use ($dir) {
            $tmp = $dir . '/' . $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $content) !== false) {
                @rename($tmp, $dir . '/' . $file);
            }
        };

        $write($key . '.json', $json);
        $write($key . '.gz', (string) gzencode($json, 5));
        // a meta fájl írása a legutolsó: a kiszolgáló ennek az ideje alapján dönt a frissességről
        $write($key . '.meta', json_encode(['origins' => array_values((array) config('cors.allowed_origins'))]));
    }

    public static function flush(): void
    {
        foreach (glob(self::dir() . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }
}
