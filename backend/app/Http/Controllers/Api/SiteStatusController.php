<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Admin\TestModeController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** A nyilvános oldal állapota (pl. teszt üzem jelzés). */
class SiteStatusController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['test_mode' => TestModeController::isEnabled()])
            ->header('Cache-Control', 'no-store');
    }
}
