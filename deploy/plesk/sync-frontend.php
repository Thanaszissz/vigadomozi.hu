<?php
// A buildelt Angular alkalmazás (frontend/dist/cinema-booking) szinkronizálása a webgyökérbe.
// Használat: php sync-frontend.php <forrás-mappa> <webgyökér> <jegyzékfájl>
// A jegyzékfájl az előző telepítés fájllistája: ezzel a régi (hash-elt nevű) fájlok törlődnek,
// a webgyökér egyéb tartalma (pl. .well-known) érintetlen marad.

[$script, $src, $dst, $manifestPath] = $argv + [null, null, null, null];

if (!$src || !$dst || !$manifestPath || !is_dir($src) || !is_dir($dst)) {
    fwrite(STDERR, "Használat: php sync-frontend.php <forrás> <webgyökér> <jegyzékfájl>\n");
    exit(1);
}

$src = rtrim(str_replace('\\', '/', realpath($src)), '/');
$dst = rtrim(str_replace('\\', '/', realpath($dst)), '/');

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($src) + 1);
    }
}

if (!in_array('index.html', $files, true)) {
    fwrite(STDERR, "HIBA: a forrásban nincs index.html, a szinkronizálás megszakítva.\n");
    exit(1);
}

// az index.html és a .htaccess kerül utoljára a helyére, hogy az átállás közben ne hiányozzon fájl
usort($files, fn ($a, $b) => (int) in_array($a, ['index.html', '.htaccess'], true) <=> (int) in_array($b, ['index.html', '.htaccess'], true));

foreach ($files as $relative) {
    $target = $dst . '/' . $relative;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0755, true);
    }
    if (!copy($src . '/' . $relative, $target)) {
        fwrite(STDERR, "HIBA: nem másolható: $relative\n");
        exit(1);
    }
}

$previous = is_file($manifestPath) ? (json_decode((string) file_get_contents($manifestPath), true) ?: []) : [];
foreach (array_diff($previous, $files) as $stale) {
    $path = $dst . '/' . $stale;
    if (is_file($path)) {
        unlink($path);
    }
}

file_put_contents($manifestPath, json_encode($files, JSON_PRETTY_PRINT));
echo count($files) . " fájl szinkronizálva ide: $dst\n";
