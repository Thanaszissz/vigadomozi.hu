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
    public const TTL_LIST = 30;
    public const TTL_SEATMAP = 5;

    public static function dir(): string
    {
        return storage_path('framework/response-cache');
    }

    /** A kért URL gyorsítótárazható-e, és meddig (másodperc); null = nem. */
    public static function ttlFor(string $uri): ?int
    {
        if (strlen($uri) > 120) {
            return null;
        }

        $parts = explode('?', $uri, 2);
        $query = $parts[1] ?? '';

        // egy előadás nézőtere: rövid életű, a foglalások és zárolások azonnal törlik
        if (preg_match('#^/api/showtimes/\d{1,9}$#', $parts[0]) === 1) {
            return $query === '' ? self::TTL_SEATMAP : null;
        }

        if (!in_array($parts[0], ['/api/movies', '/api/theater-shows', '/api/showtimes'], true)) {
            return null;
        }

        return ($query === '' || preg_match('/^(date=\d{4}-\d{2}-\d{2}|movie_id=\d{1,9}|theater_show_id=\d{1,9})$/', $query) === 1)
            ? self::TTL_LIST
            : null;
    }

    public static function store(string $uri, string $json, int $ttl): void
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
        $write($key . '.meta', json_encode(['ttl' => $ttl, 'origins' => array_values((array) config('cors.allowed_origins'))]));
    }

    public static function flush(): void
    {
        foreach (glob(self::dir() . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** Egy előadás nézőterének gyorsítótára (foglalás, zárolás után). */
    public static function forgetShowtime(?int $showtimeId): void
    {
        if (!$showtimeId) {
            return;
        }

        $key = md5('/api/showtimes/' . $showtimeId);
        foreach (['json', 'gz', 'meta'] as $ext) {
            @unlink(self::dir() . '/' . $key . '.' . $ext);
        }
    }
}
