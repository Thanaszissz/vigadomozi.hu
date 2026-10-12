<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A nagyobb JSON/szöveges válaszokat gzip-pel tömöríti, ha a kliens kéri.
 * (A tárhely előtti webszerver a PHP válaszait nem tömöríti.)
 */
class CompressResponse
{
    private const MIN_BYTES = 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            !function_exists('gzencode')
            || $response instanceof StreamedResponse
            || $response instanceof BinaryFileResponse
            || $response->headers->has('Content-Encoding')
            || !str_contains((string) $request->header('Accept-Encoding'), 'gzip')
        ) {
            return $response;
        }

        $type = (string) $response->headers->get('Content-Type');
        if (!str_contains($type, 'json') && !str_starts_with($type, 'text/')) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || strlen($content) < self::MIN_BYTES) {
            return $response;
        }

        $compressed = gzencode($content, 5);
        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        $response->headers->set('Vary', 'Accept-Encoding', false);

        return $response;
    }
}
