<?php
/**
 * Közös segédfüggvények a közösségi megosztáshoz (share.php), a sitemap-hez és a megosztási képhez (og-image.php).
 * Az Angular alkalmazás mellé kerül (a build a kimenet gyökerébe másolja), a filmadatokat az API-ból olvassa.
 *
 * Beállítás környezeti változókkal (opcionális): SEO_SITE_URL, SEO_API_URL.
 */

const SEO_SITE_NAME = 'Vigadó Jegy';
const SEO_DEFAULT_TITLE = 'Vigadó Jegy Kisújszállás – műsor, jegyvásárlás online';
const SEO_DEFAULT_DESCRIPTION = 'A kisújszállási Vigadó Jegy műsora, vetítési időpontok, jegyárak és online jegyfoglalás. Foglalj helyet kedvenc filmedre percek alatt!';

function seo_site_url(): string
{
    return rtrim(getenv('SEO_SITE_URL') ?: 'https://vigadojegy.hu', '/');
}

function seo_api_url(): string
{
    return rtrim(getenv('SEO_API_URL') ?: 'https://api.vigadojegy.hu/api', '/');
}

function seo_h(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Az oldalak szövegei (az Angular seo-routes.ts tükre). */
function seo_static_pages(): array
{
    return [
        '/' => [null, SEO_DEFAULT_DESCRIPTION],
        '/movies' => ['Filmek', 'Aktuális filmek a kisújszállási Vigadó Jegyen: plakátok, leírások, előzetesek és vetítési időpontok egy helyen.'],
        '/schedule' => ['Műsor és vetítési időpontok', 'A Vigadó Jegy teljes műsora napokra bontva: mikor és mit játszunk Kisújszálláson. Jegyfoglalás online.'],
        '/prices' => ['Jegyárak', 'A Vigadó Jegy jegyárai Kisújszálláson: teljes árú, kedvezményes és csoportos jegyek.'],
        '/mediaajanlat' => ['Médiaajánlat', 'Hirdetési és médiaajánlat a Vigadó Jegy vásznán és felületein, Kisújszálláson.'],
        '/hazirend' => ['Házirend', 'A Vigadó Jegy házirendje: látogatási szabályok, korhatár-besorolások és tudnivalók a mozilátogatáshoz.'],
        '/fizetesi-tajekoztato' => ['Fizetési tájékoztató', 'Fizetési tájékoztató a Vigadó Jegy online jegyvásárlásához: elfogadott fizetési módok és a vásárlás menete.'],
        '/adatkezelesi-tajekoztato' => ['Adatkezelési tájékoztató', 'A Vigadó Jegy adatkezelési tájékoztatója: hogyan kezeljük a jegyfoglalás és vásárlás során megadott adataidat.'],
    ];
}

/** GET kérés az API-hoz, rövid fájl-gyorsítótárral; hiba esetén az elévült példányt adja vissza. */
function seo_api_get(string $path, int $ttl = 300): ?array
{
    $url = seo_api_url() . $path;
    $cache = sys_get_temp_dir() . '/vigado-seo-' . md5($url) . '.json';

    if (is_file($cache) && filemtime($cache) > time() - $ttl) {
        $data = json_decode((string) file_get_contents($cache), true);
        if (is_array($data)) {
            return $data;
        }
    }

    $body = seo_http_get($url, 'application/json');
    $data = $body !== null ? json_decode($body, true) : null;

    if (is_array($data)) {
        @file_put_contents($cache, $body, LOCK_EX);

        return $data;
    }

    if (is_file($cache)) {
        $stale = json_decode((string) file_get_contents($cache), true);

        return is_array($stale) ? $stale : null;
    }

    return null;
}

function seo_http_get(string $url, string $accept = '*/*', int $maxBytes = 8000000): ?string
{
    if (!preg_match('#^https?://#i', $url)) {
        return null;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
            CURLOPT_USERAGENT => 'VigadoJegySeo/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ($body !== false && $code >= 200 && $code < 300 && strlen($body) <= $maxBytes) ? $body : null;
    }

    $context = stream_context_create(['http' => ['timeout' => 6, 'header' => "Accept: $accept\r\nUser-Agent: VigadoJegySeo/1.0\r\n"]]);
    $body = @file_get_contents($url, false, $context, 0, $maxBytes);

    return $body === false ? null : $body;
}

function seo_truncate(string $text, int $max = 300): string
{
    $plain = trim(preg_replace('/\s+/u', ' ', $text));
    if (mb_strlen($plain) <= $max) {
        return $plain;
    }

    return rtrim(preg_replace('/\s+\S*$/u', '', mb_substr($plain, 0, $max - 1))) . '…';
}

function seo_sentence(string $text): string
{
    return $text !== '' && !preg_match('/[.!?…]$/u', $text) ? $text . '.' : $text;
}

function seo_rating_label(?string $rating): string
{
    if (!$rating) {
        return '';
    }

    return $rating === 'general' ? 'Korhatár nélkül' : $rating . '+';
}

function seo_format_datetime(string $iso): string
{
    $months = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];

    try {
        $date = new DateTimeImmutable($iso);
    } catch (Exception) {
        return $iso;
    }
    $date = $date->setTimezone(new DateTimeZone('Europe/Budapest'));

    return $date->format('Y') . '. ' . $months[(int) $date->format('n') - 1] . ' ' . $date->format('j') . '. ' . $date->format('H:i');
}

/** A film megosztási képe: a generált 1200x630-as kártya (og-image.php), hiba esetén ez is a plakátra esik vissza. */
function seo_movie_image(array $movie): string
{
    return seo_site_url() . '/og-image.php?movie=' . (int) $movie['id'];
}

/** Az aktív, még le nem zajlott vetítések egy filmhez, időrendben. */
function seo_upcoming_showtimes(int $movieId): array
{
    $all = seo_api_get('/showtimes?movie_id=' . $movieId) ?? [];
    $now = time();

    $list = array_filter($all, function ($s) use ($movieId, $now) {
        return is_array($s)
            && (int) ($s['movie_id'] ?? 0) === $movieId
            && ($s['status'] ?? '') === 'active'
            && strtotime($s['starts_at'] ?? '') > $now;
    });
    usort($list, fn($a, $b) => strtotime($a['starts_at']) <=> strtotime($b['starts_at']));

    return array_values($list);
}

/**
 * Egy útvonal megosztási adatai.
 *
 * @return array{title:string,description:string,image:string,type:string,noindex:bool,jsonld:?array,body:string,path:string}
 */
function seo_resolve(string $path): array
{
    $site = seo_site_url();
    $clean = rtrim($path, '/') ?: '/';

    $page = [
        'title' => SEO_DEFAULT_TITLE,
        'description' => SEO_DEFAULT_DESCRIPTION,
        'image' => $site . '/assets/og-default.jpg',
        'image_size' => [1200, 630],
        'type' => 'website',
        'noindex' => false,
        'jsonld' => null,
        'body' => '',
        'path' => $clean,
    ];

    $static = seo_static_pages();

    if (isset($static[$clean])) {
        [$title, $description] = $static[$clean];
        $page['title'] = $title ? $title . ' | ' . SEO_SITE_NAME . ' Kisújszállás' : SEO_DEFAULT_TITLE;
        $page['description'] = $description;
        $page['body'] = '<h1>' . seo_h($title ?: SEO_SITE_NAME . ' Kisújszállás') . '</h1><p>' . seo_h($description) . '</p>';

        return $page;
    }

    if (preg_match('#^/movies/(\d+)$#', $clean, $m)) {
        $movie = seo_api_get('/movies/' . (int) $m[1]);
        if (is_array($movie) && isset($movie['id'])) {
            return seo_movie_page($page, $movie);
        }

        return $page;
    }

    if (preg_match('#^/showtimes/(\d+)/select-seats$#', $clean, $m)) {
        $page['noindex'] = true;
        $showtimes = seo_api_get('/showtimes') ?? [];
        foreach ($showtimes as $showtime) {
            if (is_array($showtime) && (int) ($showtime['id'] ?? 0) === (int) $m[1] && isset($showtime['movie'])) {
                $movie = $showtime['movie'];
                $when = seo_format_datetime($showtime['starts_at']);
                $room = $showtime['auditorium']['name'] ?? '';
                $page['title'] = 'Jegyfoglalás: ' . $movie['title'] . ' – ' . $when . ' | ' . SEO_SITE_NAME;
                $page['description'] = 'Foglalj helyet a(z) ' . $movie['title'] . ' vetítésére a kisújszállási Vigadó Jegyen: ' . $when . ($room ? ', ' . $room : '') . '.';
                $page['image'] = seo_movie_image($movie);
                $page['type'] = 'video.movie';
                $page['body'] = '<h1>' . seo_h($page['title']) . '</h1><p>' . seo_h($page['description']) . '</p>';
                break;
            }
        }

        return $page;
    }

    if (preg_match('#^/(admin|checkout|hold-summary|success)(/|$)#', $clean)) {
        $page['noindex'] = true;
    }

    return $page;
}

function seo_movie_page(array $page, array $movie): array
{
    $site = seo_site_url();
    $title = (string) $movie['title'];
    $rating = seo_rating_label($movie['rating'] ?? null);
    $duration = (int) ($movie['duration_min'] ?? 0);

    $parts = array_filter([
        seo_sentence(trim((string) ($movie['description'] ?? ''))),
        $duration ? 'Játékidő: ' . $duration . ' perc.' : '',
        $rating ? 'Korhatár: ' . $rating . '.' : '',
    ]);

    $showtimes = seo_upcoming_showtimes((int) $movie['id']);

    $page['title'] = $title . ' | ' . SEO_SITE_NAME . ' Kisújszállás';
    $page['description'] = seo_truncate(implode(' ', $parts) ?: SEO_DEFAULT_DESCRIPTION);
    $page['image'] = seo_movie_image($movie);
    $page['type'] = 'video.movie';

    $theater = [
        '@type' => 'MovieTheater',
        'name' => 'Vigadó Jegy',
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Szabadság tér 7.', 'addressLocality' => 'Kisújszállás', 'postalCode' => '5310', 'addressCountry' => 'HU'],
    ];

    $work = array_filter([
        '@type' => 'Movie',
        'name' => $title,
        'description' => $movie['description'] ?? null,
        'image' => $movie['poster_path'] ?? null,
        'url' => $site . '/movies/' . (int) $movie['id'],
        'duration' => $duration ? 'PT' . $duration . 'M' : null,
        'contentRating' => $rating ?: null,
    ]);

    $events = [];
    foreach (array_slice($showtimes, 0, 12) as $showtime) {
        $events[] = [
            '@type' => 'ScreeningEvent',
            'name' => $title,
            'startDate' => (new DateTimeImmutable($showtime['starts_at']))->format(DATE_ATOM),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => $theater,
            'workPresented' => ['@type' => 'Movie', 'name' => $title],
            'url' => $site . '/showtimes/' . (int) $showtime['id'] . '/select-seats',
        ];
    }
    $page['jsonld'] = ['@context' => 'https://schema.org', '@graph' => array_merge([$work], $events)];

    $body = '<h1>' . seo_h($title) . '</h1>';
    if (!empty($movie['poster_path'])) {
        $body .= '<img src="' . seo_h($movie['poster_path']) . '" alt="' . seo_h($title) . '">';
    }
    $body .= '<p>' . seo_h($page['description']) . '</p>';
    if ($showtimes) {
        $body .= '<h2>Vetítések</h2><ul>';
        foreach ($showtimes as $showtime) {
            $body .= '<li><a href="' . seo_h($site . '/showtimes/' . (int) $showtime['id'] . '/select-seats') . '">' . seo_h(seo_format_datetime($showtime['starts_at'])) . '</a></li>';
        }
        $body .= '</ul>';
    }
    $page['body'] = $body;

    return $page;
}
