<?php
/**
 * Megosztási kép egy filmhez (1200x630): elmosott, sötétített plakáthátteret és középen a plakátot kapja.
 * A kész képet fájlban gyorsítótárazza. Ha a képgenerálás valamiért nem megy (nincs GD, nem elérhető a plakát),
 * a plakátra vagy az alapértelmezett képre irányít át, így a megosztás sosem marad kép nélkül.
 */
require __DIR__ . '/_seo.php';

const OG_W = 1200;
const OG_H = 630;

function og_fallback(?string $poster): never
{
    $target = $poster && preg_match('#^https?://#i', $poster) ? $poster : seo_site_url() . '/assets/og-default.jpg';
    header('Location: ' . $target, true, 302);
    header('Cache-Control: public, max-age=300');
    exit;
}

function og_send(string $file): never
{
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}

$id = (int) ($_GET['movie'] ?? 0);
if ($id <= 0) {
    og_fallback(null);
}

$movie = seo_api_get('/movies/' . $id);
$poster = is_array($movie) ? (string) ($movie['poster_path'] ?? '') : '';
if ($poster === '') {
    og_fallback(null);
}

$cache = sys_get_temp_dir() . '/vigado-og-' . $id . '-' . md5($poster) . '.jpg';
if (is_file($cache) && filesize($cache) > 0) {
    og_send($cache);
}

if (!function_exists('imagecreatetruecolor') || !function_exists('imagecreatefromstring')) {
    og_fallback($poster);
}

$bytes = seo_http_get($poster, 'image/*', 6000000);
$src = $bytes !== null ? @imagecreatefromstring($bytes) : false;
if (!$src) {
    og_fallback($poster);
}

$sw = imagesx($src);
$sh = imagesy($src);

$canvas = imagecreatetruecolor(OG_W, OG_H);

// Háttér: a plakát kitöltve, kis méretben elmosva (gyors), majd felnagyítva és sötétítve
$small = imagecreatetruecolor((int) (OG_W / 10), (int) (OG_H / 10));
$scale = max(imagesx($small) / $sw, imagesy($small) / $sh);
$cw = (int) ceil($sw * $scale);
$ch = (int) ceil($sh * $scale);
imagecopyresampled($small, $src, (int) ((imagesx($small) - $cw) / 2), (int) ((imagesy($small) - $ch) / 2), 0, 0, $cw, $ch, $sw, $sh);
for ($i = 0; $i < 6; $i++) {
    imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
}
// Két lépésben nagyítjuk vissza, közben újra elmosva, hogy ne látszódjanak a kockák
$mid = imagecreatetruecolor((int) (OG_W / 3), (int) (OG_H / 3));
imagecopyresampled($mid, $small, 0, 0, 0, 0, imagesx($mid), imagesy($mid), imagesx($small), imagesy($small));
imagedestroy($small);
for ($i = 0; $i < 4; $i++) {
    imagefilter($mid, IMG_FILTER_GAUSSIAN_BLUR);
}
imagecopyresampled($canvas, $mid, 0, 0, 0, 0, OG_W, OG_H, imagesx($mid), imagesy($mid));
imagedestroy($mid);

imagealphablending($canvas, true);
$shade = imagecolorallocatealpha($canvas, 30, 18, 8, 55);
imagefilledrectangle($canvas, 0, 0, OG_W, OG_H, $shade);

// Középen a plakát, arany kerettel
$ph = OG_H - 70;
$pw = (int) round($sw * $ph / $sh);
$px = (int) ((OG_W - $pw) / 2);
$py = (int) ((OG_H - $ph) / 2);
$gold = imagecolorallocate($canvas, 212, 165, 116);
imagefilledrectangle($canvas, $px - 4, $py - 4, $px + $pw + 3, $py + $ph + 3, $gold);
imagecopyresampled($canvas, $src, $px, $py, 0, 0, $pw, $ph, $sw, $sh);

imagedestroy($src);

$tmp = $cache . '.' . getmypid() . '.tmp';
if (@imagejpeg($canvas, $tmp, 86) && @rename($tmp, $cache)) {
    imagedestroy($canvas);
    og_send($cache);
}
imagedestroy($canvas);
@unlink($tmp);

og_fallback($poster);
