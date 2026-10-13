<?php
/**
 * Közösségi megosztás (Facebook, Messenger, WhatsApp, X, LinkedIn, Viber stb.): ezek a robotok nem futtatnak JavaScriptet,
 * ezért az .htaccess nekik ezt a szkriptet adja az Angular alkalmazás helyett. Minden oldalhoz saját cím, leírás és kép tartozik.
 */
require __DIR__ . '/_seo.php';

$path = (string) ($_GET['path'] ?? '/');
if (!preg_match('#^/[A-Za-z0-9\-_/]*$#', $path) || strlen($path) > 200) {
    $path = '/';
}

$page = seo_resolve($path);
$site = seo_site_url();
$url = $site . ($page['path'] === '/' ? '/' : $page['path']);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
if ($page['noindex']) {
    header('X-Robots-Tag: noindex, nofollow');
}

$size = $page['image_size'] ?? [1200, 630];
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title><?= seo_h($page['title']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= seo_h($page['description']) ?>">
  <meta name="robots" content="<?= $page['noindex'] ? 'noindex, nofollow' : 'index, follow, max-image-preview:large' ?>">
  <link rel="canonical" href="<?= seo_h($url) ?>">

  <meta property="og:site_name" content="<?= seo_h(SEO_SITE_NAME) ?>">
  <meta property="og:locale" content="hu_HU">
  <meta property="og:type" content="<?= seo_h($page['type']) ?>">
  <meta property="og:title" content="<?= seo_h($page['title']) ?>">
  <meta property="og:description" content="<?= seo_h($page['description']) ?>">
  <meta property="og:url" content="<?= seo_h($url) ?>">
  <meta property="og:image" content="<?= seo_h($page['image']) ?>">
  <meta property="og:image:width" content="<?= (int) $size[0] ?>">
  <meta property="og:image:height" content="<?= (int) $size[1] ?>">
  <meta property="og:image:alt" content="<?= seo_h($page['title']) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= seo_h($page['title']) ?>">
  <meta name="twitter:description" content="<?= seo_h($page['description']) ?>">
  <meta name="twitter:image" content="<?= seo_h($page['image']) ?>">
<?php if ($page['jsonld']): ?>
  <script type="application/ld+json"><?= json_encode($page['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
</head>
<body>
  <?= $page['body'] ?>
  <p><a href="<?= seo_h($site) ?>/"><?= seo_h(SEO_SITE_NAME) ?> Kisújszállás</a></p>
</body>
</html>
