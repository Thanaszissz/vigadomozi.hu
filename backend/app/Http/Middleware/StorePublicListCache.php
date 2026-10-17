<?php

namespace App\Http\Middleware;

use App\Support\ResponseCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A publikus listák sikeres válaszát elmenti a ResponseCache-be (lásd public/index.php). */
class StorePublicListCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            $request->isMethod('GET')
            && $response->getStatusCode() === 200
            && !$request->headers->has('Authorization')
            && ResponseCache::eligible($request->getRequestUri())
        ) {
            $content = $response->getContent();
            if (is_string($content) && $content !== '') {
                ResponseCache::store($request->getRequestUri(), $content);
            }
        }

        return $response;
    }
}
