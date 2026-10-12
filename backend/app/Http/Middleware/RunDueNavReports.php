<?php

namespace App\Http\Middleware;

use App\Services\NavReceiptService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Az automatikus NAV beküldés esedékességét a kérések végén ellenőrzi (cron nélkül is működik).
 * Az ellenőrzés legfeljebb percenként egyszer fut, a beküldés a válasz elküldése után történik.
 */
class RunDueNavReports
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->terminating(function () {
            try {
                $marker = storage_path('framework/nav-check');
                if (is_file($marker) && time() - (int) @filemtime($marker) < 60) {
                    return;
                }
                @touch($marker);

                app(NavReceiptService::class)->runDue();
            } catch (\Throwable $e) {
                Log::error('Automatikus NAV beküldés hiba: ' . $e->getMessage());
            }
        });

        return $next($request);
    }
}
