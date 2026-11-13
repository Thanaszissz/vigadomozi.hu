<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsSettings;
use Illuminate\Http\JsonResponse;

/** A nyilvános oldal mérési beállításai (Tag Manager azonosító és a dataLayer események paraméterei). */
class AnalyticsConfigController extends Controller
{
    public function show(AnalyticsSettings $analytics): JsonResponse
    {
        if (!$analytics->isActive()) {
            return response()->json(['enabled' => false])->header('Cache-Control', 'no-store');
        }

        $c = $analytics->config();

        return response()->json([
            'enabled' => true,
            'gtm_id' => $c['gtm_id'],
            'variant' => $c['variant'],
            'item_prefix_movie' => $c['item_prefix_movie'],
            'item_prefix_show' => $c['item_prefix_show'],
        ])->header('Cache-Control', 'no-store');
    }
}
