<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Plakátok feldolgozása: kép forrása (URL vagy base64 data URI) → átméretezés → WebP → public lemez (posters/).
 */
class WebpImageService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_PIXELS = 25_000_000;
    public const MAX_SIDE = 1600;
    public const QUALITY = 82;
    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /** Meglévő (már feldolgozott) plakát törlése, ha a saját posters mappánkban van. */
    public function deleteStored(?string $posterPath): void
    {
        if ($posterPath && str_contains($posterPath, '/storage/posters/')) {
            Storage::disk('public')->delete('posters/' . basename(parse_url($posterPath, PHP_URL_PATH) ?: $posterPath));
        }
    }

    public function isSupported(): bool
    {
        return (function_exists('imagecreatefromstring') && function_exists('imagewebp'))
            || (class_exists(\Imagick::class) && in_array('WEBP', \Imagick::queryFormats('WEBP'), true));
    }

    /** Csak a forrás formáját ellenőrzi (próbafuttatáshoz), nem tölt le semmit. */
    public function validateSource(string $source): void
    {
        $source = trim($source);
        if (preg_match('#^data:image/[a-z0-9.+-]+;base64,#i', $source)) {
            return;
        }
        if (!preg_match('#^https?://#i', $source) || !filter_var($source, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('A kép forrása http(s) URL vagy base64 data URI lehet.');
        }
    }

    /**
     * Feldolgozza a képet, és visszaadja a mentett WebP nyilvános URL-jét.
     *
     * @return array{url: string, width: int, height: int, bytes: int}
     */
    public function storeAsWebp(string $source): array
    {
        $this->validateSource($source);
        if (!$this->isSupported()) {
            throw new RuntimeException('A szerveren nincs WebP támogatás (GD webp vagy Imagick szükséges).');
        }

        $bytes = $this->load(trim($source));
        $webp = $this->convert($bytes);

        $name = 'posters/' . Str::uuid() . '.webp';
        Storage::disk('public')->put($name, $webp['data']);

        return [
            'url' => url('/storage') . '/' . $name,
            'width' => $webp['width'],
            'height' => $webp['height'],
            'bytes' => strlen($webp['data']),
        ];
    }

    private function load(string $source): string
    {
        if (str_starts_with(strtolower($source), 'data:')) {
            $encoded = substr($source, strpos($source, ',') + 1);
            $bytes = base64_decode(preg_replace('/\s+/', '', $encoded), true);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('A base64 kép hibás.');
            }
            if (strlen($bytes) > self::MAX_BYTES) {
                throw new RuntimeException('A kép túl nagy (legfeljebb 10 MB lehet).');
            }
            return $bytes;
        }

        return $this->download($source);
    }

    private function download(string $url): string
    {
        for ($hop = 0; $hop <= 3; $hop++) {
            $parts = parse_url($url);
            $scheme = strtolower($parts['scheme'] ?? '');
            $host = $parts['host'] ?? '';
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                throw new RuntimeException('Érvénytelen kép URL.');
            }
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            $ip = $this->publicIp($host);

            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'VigadoJegy-Import/1.0', 'Accept' => 'image/*'])
                ->withOptions([
                    'allow_redirects' => false,
                    // a feloldott (ellenőrzött) címhez kötjük a kapcsolatot, így DNS-váltással sem kerülhető meg az ellenőrzés
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                    'progress' => function ($total, $downloaded) {
                        if ($downloaded > self::MAX_BYTES) {
                            throw new RuntimeException('A kép túl nagy (legfeljebb 10 MB lehet).');
                        }
                    },
                ])
                ->get($url);

            if ($response->redirect()) {
                $location = $response->header('Location');
                if ($location === '') {
                    throw new RuntimeException('Hibás átirányítás a kép URL-jén.');
                }
                $url = $this->absoluteUrl($location, $scheme, $host, $parts['port'] ?? null);
                continue;
            }
            if (!$response->successful()) {
                throw new RuntimeException('A kép nem tölthető le (HTTP ' . $response->status() . ').');
            }

            $body = $response->body();
            if (strlen($body) > self::MAX_BYTES) {
                throw new RuntimeException('A kép túl nagy (legfeljebb 10 MB lehet).');
            }
            return $body;
        }

        throw new RuntimeException('Túl sok átirányítás a kép URL-jén.');
    }

    private function absoluteUrl(string $location, string $scheme, string $host, ?int $port): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            return $scheme . '://' . $host . ($port ? ':' . $port : '') . $location;
        }
        throw new RuntimeException('Nem támogatott átirányítás a kép URL-jén.');
    }

    /** Csak nyilvános IPv4 címre oldódó hosztot engedünk (belső hálózati kérések tiltása). */
    private function publicIp(string $host): string
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) {
            throw new RuntimeException("A kép hosztja nem oldható fel: {$host}");
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Belső vagy tiltott címre mutató kép URL nem engedélyezett.');
            }
        }
        return $ips[0];
    }

    /** @return array{data: string, width: int, height: int} */
    private function convert(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], self::ALLOWED_TYPES, true)) {
            throw new RuntimeException('A fájl nem támogatott képformátum (JPG, PNG, GIF vagy WebP lehet).');
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new RuntimeException('A kép felbontása túl nagy.');
        }

        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));

        if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
            return $this->convertWithGd($bytes, $width, $height, $newW, $newH);
        }

        return $this->convertWithImagick($bytes, $newW, $newH);
    }

    private function convertWithGd(string $bytes, int $width, int $height, int $newW, int $newH): array
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new RuntimeException('A kép nem olvasható be.');
        }
        imagepalettetotruecolor($src);

        $dst = imagecreatetruecolor($newW, $newH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);

        ob_start();
        $ok = imagewebp($dst, null, self::QUALITY);
        $data = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok || $data === '') {
            throw new RuntimeException('A WebP konvertálás nem sikerült.');
        }

        return ['data' => $data, 'width' => $newW, 'height' => $newH];
    }

    private function convertWithImagick(string $bytes, int $newW, int $newH): array
    {
        try {
            $image = new \Imagick();
            $image->readImageBlob($bytes);
            $image->setIteratorIndex(0);
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(self::QUALITY);
            $image->resizeImage($newW, $newH, \Imagick::FILTER_LANCZOS, 1);
            $data = $image->getImageBlob();
            $image->clear();
        } catch (\ImagickException $e) {
            throw new RuntimeException('A WebP konvertálás nem sikerült: ' . $e->getMessage());
        }

        return ['data' => $data, 'width' => $newW, 'height' => $newH];
    }
}
