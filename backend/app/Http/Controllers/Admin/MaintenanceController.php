<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Karbantartás mód: bekapcsolva a nyilvános oldal nem érhető el és online vásárlás sem indítható (az admin felület igen). */
class MaintenanceController extends Controller
{
    public const KEY = 'site.maintenance';
    public const MESSAGE_KEY = 'site.maintenance_message';
    public const DEFAULT_MESSAGE = 'Rendszerfrissítés miatt az oldal átmenetileg nem érhető el. Kérjük, nézzen vissza hamarosan!';

    public static function isEnabled(): bool
    {
        return Setting::find(self::KEY)?->value === '1';
    }

    public static function message(): string
    {
        $message = trim((string) Setting::find(self::MESSAGE_KEY)?->value);

        return $message !== '' ? $message : self::DEFAULT_MESSAGE;
    }

    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'maintenance' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $enabled = (bool) $data['maintenance'];
        $message = trim((string) ($data['message'] ?? ''));
        $changed = $enabled !== self::isEnabled();

        Setting::updateOrCreate(['key' => self::KEY], ['value' => $enabled ? '1' : '0']);
        Setting::updateOrCreate(['key' => self::MESSAGE_KEY], ['value' => $message]);

        if ($changed) {
            ActivityLogger::log(
                'settings.maintenance_changed',
                'Karbantartás mód ' . ($enabled ? 'bekapcsolva' : 'kikapcsolva'),
                null,
                ['maintenance' => $enabled]
            );
        }

        return response()->json($this->state());
    }

    private function state(): array
    {
        return [
            'maintenance' => self::isEnabled(),
            'message' => trim((string) Setting::find(self::MESSAGE_KEY)?->value),
            'default_message' => self::DEFAULT_MESSAGE,
        ];
    }
}
