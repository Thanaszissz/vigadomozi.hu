<?php

namespace App\Http\Middleware;

use App\Services\BackupService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Az automatikus mentés esedékességét a kérések végén ellenőrzi. Így cron nélkül is lefut az ütemezett mentés
 * (az esedékes időpont utáni első kérésnél); a mentés a válasz elküldése után történik, nem lassítja a kiszolgálást.
 */
class RunDueBackups
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->terminating(function () {
            try {
                app(BackupService::class)->runIfDue();
            } catch (\Throwable $e) {
                Log::error('Automatikus mentés hiba: ' . $e->getMessage());
            }
        });

        return $next($request);
    }
}
