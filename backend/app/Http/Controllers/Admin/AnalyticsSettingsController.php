<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\AnalyticsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsSettingsController extends Controller
{
    public function __construct(private AnalyticsSettings $analytics)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'gtm_id' => ['nullable', 'string', 'max:20', 'regex:/^GTM-[A-Za-z0-9]{4,12}$/'],
            'tax_rate' => ['nullable', 'integer', 'between:0,100'],
            'variant' => ['required', 'string', 'max:60'],
            'transaction_id_mode' => ['required', Rule::in(AnalyticsSettings::MODES)],
            'transaction_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'item_prefix_movie' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'item_prefix_show' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'send_postal_code' => ['required', 'boolean'],
        ], [
            'gtm_id.regex' => 'A Tag Manager tároló azonosítója GTM-XXXXXXX alakú (pl. GTM-ABC1234).',
            'tax_rate.between' => 'Az ÁFA-kulcs 0 és 100 között lehet.',
            '*.regex' => 'Csak betű, szám, kötőjel és aláhúzás használható.',
        ]);

        if ($data['enabled'] && empty($data['gtm_id'])) {
            return response()->json(['message' => 'A bekapcsoláshoz meg kell adni a Tag Manager tároló azonosítóját (GTM-…).'], 422);
        }

        $this->analytics->save([
            'enabled' => (bool) $data['enabled'],
            'gtm_id' => (string) ($data['gtm_id'] ?? ''),
            'tax_rate' => $data['tax_rate'] ?? null,
            'variant' => $data['variant'],
            'transaction_id_mode' => $data['transaction_id_mode'],
            'transaction_prefix' => $data['transaction_prefix'],
            'item_prefix_movie' => $data['item_prefix_movie'],
            'item_prefix_show' => $data['item_prefix_show'],
            'send_postal_code' => (bool) $data['send_postal_code'],
        ]);

        ActivityLogger::log(
            'settings.analytics_changed',
            'Webes mérés (Tag Manager) beállítások módosítva',
            null,
            ['enabled' => (bool) $data['enabled'], 'gtm_id' => (string) ($data['gtm_id'] ?? '')]
        );

        return response()->json($this->state());
    }

    private function state(): array
    {
        $c = $this->analytics->config();

        return $c + ['active' => $this->analytics->isActive()];
    }
}
