<?php

namespace App\Http\Middleware;

use App\Services\BotGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A foglaláshoz kötelező a láthatatlan robotvédelem megoldása; a rejtett "website" mező kitöltése robotra utal. */
class RequireHumanProof
{
    public function __construct(private BotGuard $guard)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('botguard.enabled')) {
            return $next($request);
        }

        $captcha = $request->input('captcha');
        $captcha = is_array($captcha) ? $captcha : [];

        $honeypotTripped = filled($request->input('website'));
        $solved = $this->guard->verify($captcha['token'] ?? null, isset($captcha['solution']) ? (string) $captcha['solution'] : null);

        if ($honeypotTripped || !$solved) {
            return response()->json([
                'message' => 'Az automatikus ellenőrzés nem sikerült. Kérjük, töltse újra az oldalt és próbálja újra.',
                'code' => 'captcha_failed',
            ], 422);
        }

        return $next($request);
    }
}
