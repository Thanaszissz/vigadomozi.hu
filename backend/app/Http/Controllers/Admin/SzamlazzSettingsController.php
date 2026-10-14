<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\SzamlazzService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SzamlazzSettingsController extends Controller
{
    use ConfirmsPassword;

    public function __construct(private SzamlazzService $szamlazz)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    public function update(Request $request): JsonResponse
    {
        $current = $this->szamlazz->config();

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'agent_key' => ['nullable', 'string', 'min:8', 'max:128', 'regex:/^\S+$/'],
            'vat_rate' => ['required', Rule::in(SzamlazzService::VAT_RATES)],
            'e_invoice' => ['required', 'boolean'],
            'send_email' => ['required', 'boolean'],
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]*$/'],
            'email_subject' => ['nullable', 'string', 'max:200'],
            'email_text' => ['nullable', 'string', 'max:2000'],
        ], [
            'agent_key.regex' => 'A Számla Agent kulcs nem tartalmazhat szóközt.',
            'agent_key.min' => 'A Számla Agent kulcs túl rövid.',
            'prefix.regex' => 'A számlaszám előtag csak betűt és számot tartalmazhat.',
        ]);

        $hasKey = !empty($data['agent_key']) || !empty($current['agent_key']);
        if ($data['enabled'] && !$hasKey) {
            return response()->json(['message' => 'A bekapcsoláshoz meg kell adni a Számla Agent kulcsot.'], 422);
        }

        $this->confirmPassword($request, 'szamlazz', 'A Számlázz.hu beállítások módosítása');

        $changed = array_keys(array_filter([
            'enabled' => $current['enabled'] !== (bool) $data['enabled'],
            'agent_key' => !empty($data['agent_key']),
            'vat_rate' => $current['vat_rate'] !== $data['vat_rate'],
            'e_invoice' => $current['e_invoice'] !== (bool) $data['e_invoice'],
            'send_email' => $current['send_email'] !== (bool) $data['send_email'],
            'prefix' => $current['prefix'] !== trim((string) ($data['prefix'] ?? '')),
        ]));

        $this->szamlazz->save([
            'enabled' => (bool) $data['enabled'],
            'agent_key' => $data['agent_key'] ?? null,
            'vat_rate' => $data['vat_rate'],
            'e_invoice' => (bool) $data['e_invoice'],
            'send_email' => (bool) $data['send_email'],
            'prefix' => $data['prefix'] ?? '',
            'email_subject' => $data['email_subject'] ?? '',
            'email_text' => $data['email_text'] ?? '',
        ]);

        // A kulcs értéke a naplóba sem kerül, csak a ténye, hogy módosult
        ActivityLogger::log(
            'settings.szamlazz_changed',
            'Számlázz.hu beállítások módosítva' . ($changed ? ' (' . implode(', ', $changed) . ')' : ''),
            null,
            ['changed' => $changed, 'enabled' => (bool) $data['enabled'], 'vat_rate' => $data['vat_rate']]
        );

        return response()->json($this->state());
    }

    /** Kapcsolat-ellenőrzés a mentett kulccsal (előnézeti PDF kérése, számla nem készül). */
    public function test(): JsonResponse
    {
        $result = $this->szamlazz->testConnection();

        ActivityLogger::log(
            'settings.szamlazz_tested',
            'Számlázz.hu kapcsolat-ellenőrzés: ' . ($result['ok'] ? 'sikeres' : 'sikertelen'),
            null,
            ['ok' => $result['ok']]
        );

        return response()->json($result);
    }

    private function state(): array
    {
        $c = $this->szamlazz->config();
        $key = (string) $c['agent_key'];

        return [
            'enabled' => $c['enabled'],
            'ready' => $this->szamlazz->isEnabled(),
            // A kulcsot soha nem küldjük vissza, csak a tényét és az utolsó négy karakterét
            'has_key' => $key !== '',
            'key_hint' => $key !== '' ? '••••••••' . substr($key, -4) : null,
            'vat_rate' => $c['vat_rate'],
            'vat_rates' => SzamlazzService::VAT_RATES,
            'e_invoice' => $c['e_invoice'],
            'send_email' => $c['send_email'],
            'prefix' => $c['prefix'],
            'email_subject' => $c['email_subject'],
            'email_text' => $c['email_text'],
            'recent_invoices' => \App\Models\Invoice::orderByDesc('id')->limit(15)
                ->get(['id', 'reservation_id', 'invoice_number', 'buyer_name', 'gross_amount', 'vat_rate', 'issued_by_name', 'issued_at']),
        ];
    }
}
