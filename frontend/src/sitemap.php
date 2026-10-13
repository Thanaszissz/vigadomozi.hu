<?php
/**
 * Dinamikus sitemap.xml: az állandó oldalak és a műsoron lévő filmek oldalai.
 */
require __DIR__ . '/_seo.php';

$site = seo_site_url();

$urls = [
    ['/', 'daily', '1.0', null],
    ['/schedule', 'daily', '0.9', null],
    ['/movies', 'daily', '0.9', null],
    ['/prices', 'monthly', '0.6', null],
    ['/hazirend', 'yearly', '0.3', null],
    ['/fizetesi-tajekoztato', 'yearly', '0.3', null],
    ['/adatkezelesi-tajekoztato', 'yearly', '0.3', null],
    ['/mediaajanlat', 'yearly', '0.3', null],
];

// Csak azok a filmek kerülnek be, amelyeknek van még várható vetítésük
$now = time();
$upcoming = [];
foreach (seo_api_get('/showtimes') ?? [] as $showtime) {
    if (is_array($showtime) && ($showtime['status'] ?? '') === 'active' && strtotime($showtime['starts_at'] ?? '') > $now) {
        $upcoming[(int) $showtime['movie_id']] = true;
    }
}

foreach (seo_api_get('/movies') ?? [] as $movie) {
    if (is_array($movie) && isset($movie['id']) && isset($upcoming[(int) $movie['id']])) {
        $lastmod = !empty($movie['updated_at']) ? gmdate('Y-m-d', strtotime($movie['updated_at'])) : null;
        $urls[] = ['/movies/' . (int) $movie['id'], 'weekly', '0.8', $lastmod];
    }
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=600');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$path, $freq, $priority, $lastmod]) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($site . ($path === '/' ? '/' : $path), ENT_XML1) . "</loc>\n";
    if ($lastmod) {
        echo '    <lastmod>' . $lastmod . "</lastmod>\n";
    }
    echo '    <changefreq>' . $freq . "</changefreq>\n";
    echo '    <priority>' . $priority . "</priority>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";
