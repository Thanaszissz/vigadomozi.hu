<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BotGuard;
use Illuminate\Http\JsonResponse;

class BotChallengeController extends Controller
{
    public function show(BotGuard $guard): JsonResponse
    {
        return response()->json($guard->issue())->header('Cache-Control', 'no-store');
    }
}
