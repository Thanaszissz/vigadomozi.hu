<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\ActivityLogger;
use App\Services\SimplePayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SimplePaySettingsController extends Controller
{
    use ConfirmsPassword;

    public function __construct(private SimplePayService $simplePay)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    public function update(Request $request): JsonResponse
    {
        $current = $this->simplePay->config();

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'environment' => ['required', Rule::in(['sandbox', 'live'])],
            'merchant' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'secret_key' => ['nullable', 'string', 'min:8', 'max:128', 'regex:/^\S+$/'],
            'language' => ['required', Rule::in(SimplePayService::LANGUAGES)],
            'timeout_minutes' => ['required', 'integer', 'between:5,120'],
            'site_url' => ['required', 'url:http,https', 'max:255'],
        ], [
            'merchant.regex' => 'A kereskedő azonosító csak betűt, számot, kötőjelet és aláhúzást tartalmazhat.',
            'secret_key.regex' => 'A titkos kulcs nem tartalmazhat szóközt.',
            'secret_key.min' => 'A titkos kulcs túl rövid.',
            'site_url.url' => 'A webhely címe érvénytelen (pl. https://mozi.example.hu).',
            'timeout_minutes.between' => 'Az időkorlát 5 és 120 perc között lehet.',
        ]);

        $merchant = trim((string) ($data['merchant'] ?? ''));
        $hasSecret = !empty($data['secret_key']) || !empty($current['secret_key']);
        $siteUrl = rtrim($data['site_url'], '/');
        $parts = parse_url($siteUrl);

        if (!empty($parts['path']) || !empty($parts['query'])) {
            return response()->json(['message' => 'A webhely címe csak a gyökércím lehet (pl. https://mozi.example.hu), útvonal nélkül.'], 422);
        }
        if ($data['enabled'] && ($merchant === '' || !$hasSecret)) {
            return response()->json(['message' => 'A bekapcsoláshoz meg kell adni a kereskedő azonosítót és a titkos kulcsot.'], 422);
        }
        if ($data['enabled'] && $data['environment'] === 'live' && ($parts['scheme'] ?? '') !== 'https') {
            return response()->json(['message' => 'Éles működéshez a webhely címének https-sel kell kezdődnie.'], 422);
        }

        $this->confirmPassword($request, 'simplepay', 'A SimplePay beállítások módosítása');

        $changed = array_keys(array_filter([
            'enabled' => $current['enabled'] !== (bool) $data['enabled'],
            'environment' => $current['environment'] !== $data['environment'],
            'merchant' => $current['merchant'] !== $merchant,
            'secret_key' => !empty($data['secret_key']),
            'language' => $current['language'] !== $data['language'],
            'timeout_minutes' => $current['timeout_minutes'] !== (int) $data['timeout_minutes'],
            'site_url' => $current['site_url'] !== $siteUrl,
        ]));

        $this->simplePay->save([
            'enabled' => (bool) $data['enabled'],
            'environment' => $data['environment'],
            'merchant' => $merchant,
            'secret_key' => $data['secret_key'] ?? null,
            'language' => $data['language'],
            'timeout_minutes' => (int) $data['timeout_minutes'],
            'site_url' => $siteUrl,
        ]);

        // A titkos kulcs értéke a naplóba sem kerül, csak a ténye, hogy módosult
        ActivityLogger::log(
            'settings.simplepay_changed',
            'OTP SimplePay beállítások módosítva' . ($changed ? ' (' . implode(', ', $changed) . ')' : ''),
            null,
            ['changed' => $changed, 'enabled' => (bool) $data['enabled'], 'environment' => $data['environment'], 'merchant' => $merchant]
        );

        return response()->json($this->state());
    }

    /** Kapcsolat-ellenőrzés a mentett adatokkal. */
    public function test(): JsonResponse
    {
        $result = $this->simplePay->testConnection();

        ActivityLogger::log(
            'settings.simplepay_tested',
            'OTP SimplePay kapcsolat-ellenőrzés: ' . ($result['ok'] ? 'sikeres' : 'sikertelen'),
            null,
            ['ok' => $result['ok'], 'http_status' => $result['http_status'], 'error_codes' => $result['error_codes']]
        );

        return response()->json($result);
    }

    private function state(): array
    {
        $c = $this->simplePay->config();
        $secret = (string) $c['secret_key'];

        return [
            'enabled' => $c['enabled'],
            'ready' => $this->simplePay->isEnabled(),
            'environment' => $c['environment'],
            'merchant' => $c['merchant'],
            // A titkos kulcsot soha nem küldjük vissza, csak a tényét és az utolsó négy karakterét
            'has_secret' => $secret !== '',
            'secret_hint' => $secret !== '' ? '••••••••' . substr($secret, -4) : null,
            'language' => $c['language'],
            'languages' => SimplePayService::LANGUAGES,
            'timeout_minutes' => $c['timeout_minutes'],
            'site_url' => $c['site_url'],
            'urls' => $this->simplePay->urls(),
            'recent_payments' => Payment::where('provider', SimplePayService::PROVIDER)
                ->orderByDesc('id')
                ->limit(15)
                ->get(['id', 'reservation_id', 'order_ref', 'transaction_id', 'status', 'amount', 'environment', 'created_at', 'completed_at']),
        ];
    }
}
