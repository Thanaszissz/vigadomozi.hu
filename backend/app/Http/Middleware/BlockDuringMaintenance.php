<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Admin\MaintenanceController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Karbantartás módban a nyilvános vásárlási műveletek (székzárolás, foglalás, fizetés indítása) nem hívhatók. */
class BlockDuringMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (MaintenanceController::isEnabled()) {
            return response()->json([
                'message' => MaintenanceController::message(),
                'maintenance' => true,
            ], 503)->header('Retry-After', '600');
        }

        return $next($request);
    }
}
