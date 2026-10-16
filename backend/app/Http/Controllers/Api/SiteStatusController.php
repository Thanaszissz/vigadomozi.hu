<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\TestModeController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** A nyilvános oldal állapota (teszt üzem jelzés, karbantartás mód). */
class SiteStatusController extends Controller
{
    public function show(): JsonResponse
    {
        $maintenance = MaintenanceController::isEnabled();

        return response()->json([
            'test_mode' => TestModeController::isEnabled(),
            'maintenance' => $maintenance,
            'maintenance_message' => $maintenance ? MaintenanceController::message() : null,
        ])->header('Cache-Control', 'no-store');
    }
}
