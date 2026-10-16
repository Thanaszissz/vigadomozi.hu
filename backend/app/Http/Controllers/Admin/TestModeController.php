<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Teszt üzem jelzése: bekapcsolva a nyilvános oldal tetején figyelmeztetés jelenik meg (a vásárlás nincs letiltva). */
class TestModeController extends Controller
{
    public const KEY = 'site.test_mode';

    public static function isEnabled(): bool
    {
        return Setting::find(self::KEY)?->value === '1';
    }

    public function show(): JsonResponse
    {
        return response()->json(['test_mode' => self::isEnabled()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['test_mode' => ['required', 'boolean']]);
        $enabled = (bool) $data['test_mode'];

        $changed = $enabled !== self::isEnabled();
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $enabled ? '1' : '0']);

        if ($changed) {
            ActivityLogger::log(
                'settings.test_mode_changed',
                'Teszt üzem jelzés ' . ($enabled ? 'bekapcsolva' : 'kikapcsolva'),
                null,
                ['test_mode' => $enabled]
            );
        }

        return response()->json(['test_mode' => $enabled]);
    }
}
