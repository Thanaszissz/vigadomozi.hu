<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\NavException;
use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Controller;
use App\Models\NavSubmission;
use App\Services\ActivityLogger;
use App\Services\NavReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NavController extends Controller
{
    use ConfirmsPassword;

    private const CONFIRM_TTL_SECONDS = 300;
    private const VAT_OPTIONS = ['0%', '5%', '18%', '27%', 'Alanyi adómentes', 'Egyéb'];

    public function __construct(private NavReceiptService $nav)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    /** Kapcsolati adatok és aktiválás (jelszóval megerősítve). */
    public function update(Request $request): JsonResponse
    {
        $current = $this->nav->config();

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'environment' => ['required', Rule::in(['test', 'live'])],
            'tax_number' => ['nullable', 'string', 'max:20'],
            'login' => ['nullable', 'string', 'max:50'],
            'tech_password' => ['nullable', 'string', 'max:128'],
            'signature_key' => ['nullable', 'string', 'max:128'],
            'software_name' => ['required', 'string', 'max:100', 'regex:/^[A-ZÁÉÍÓÖŐÚÜŰa-záéíóöőúüű0-9.\s\-@+_]+$/u'],
            'vat_category' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9áéíóöőúüűÁÉÍÓÖŐÚÜŰ% ]+$/u'],
        ], [
            'software_name.regex' => 'A szoftver neve csak betűt, számot, szóközt és a . - @ + _ jeleket tartalmazhat.',
            'vat_category.regex' => 'Az ÁFA kategória neve érvénytelen karaktert tartalmaz.',
        ]);

        // Az adószám 11 jegyű alakban (12345678-1-23) is megadható, a NAV-nak a 8 jegyű törzsszám kell
        $digits = preg_replace('/\D/', '', (string) ($data['tax_number'] ?? ''));
        if ($digits !== '' && strlen($digits) < 8) {
            return response()->json(['message' => 'Az adószám törzsszáma 8 számjegy.'], 422);
        }
        $taxNumber = $digits !== '' ? substr($digits, 0, 8) : '';

        $login = trim((string) ($data['login'] ?? ''));
        $hasPassword = !empty($data['tech_password']) || !empty($current['password']);
        $hasKey = !empty($data['signature_key']) || !empty($current['signature_key']);

        if ($data['enabled'] && ($taxNumber === '' || $login === '' || !$hasPassword || !$hasKey)) {
            return response()->json(['message' => 'Az aktiváláshoz meg kell adni az adószámot, a technikai felhasználó nevét, jelszavát és az aláírókulcsot.'], 422);
        }

        $this->confirmPassword($request, 'nav', 'A NAV beállítások módosítása');

        $changed = array_keys(array_filter([
            'enabled' => $current['enabled'] !== (bool) $data['enabled'],
            'environment' => $current['environment'] !== $data['environment'],
            'tax_number' => $current['tax_number'] !== $taxNumber,
            'login' => $current['login'] !== $login,
            'password' => !empty($data['tech_password']),
            'signature_key' => !empty($data['signature_key']),
            'software_name' => $current['software_name'] !== $data['software_name'],
            'vat_category' => $current['vat_category'] !== $data['vat_category'],
        ]));

        $this->nav->saveConnection([
            'enabled' => (bool) $data['enabled'],
            'environment' => $data['environment'],
            'tax_number' => $taxNumber,
            'login' => $login,
            'password' => $data['tech_password'] ?? null,
            'signature_key' => $data['signature_key'] ?? null,
            'software_name' => trim($data['software_name']),
            'vat_category' => trim($data['vat_category']),
        ]);

        // A jelszó és az aláírókulcs értéke a naplóba sem kerül
        ActivityLogger::log(
            'settings.nav_changed',
            'NAV kapcsolat beállításai módosítva' . ($changed ? ' (' . implode(', ', $changed) . ')' : ''),
            null,
            ['changed' => $changed, 'enabled' => (bool) $data['enabled'], 'environment' => $data['environment'], 'tax_number' => $taxNumber]
        );

        return response()->json($this->state());
    }

    /** Automatikus napi beküldés időzítése. */
    public function updateSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auto_enabled' => ['required', 'boolean'],
            'auto_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'catchup_days' => ['required', 'integer', 'between:0,30'],
        ]);

        $this->nav->saveSchedule((bool) $data['auto_enabled'], $data['auto_time'], (int) $data['catchup_days']);

        ActivityLogger::log(
            'settings.nav_schedule_changed',
            $data['auto_enabled'] ? "Automatikus NAV beküldés beállítva: naponta {$data['auto_time']}" : 'Automatikus NAV beküldés kikapcsolva',
            null,
            $data
        );

        return response()->json($this->state());
    }

    public function test(): JsonResponse
    {
        $result = $this->nav->testConnection();

        ActivityLogger::log('nav.tested', 'NAV kapcsolat-ellenőrzés: ' . ($result['ok'] ? 'sikeres' : 'sikertelen'), null, ['ok' => $result['ok'], 'steps' => collect($result['steps'])->map(fn($s) => $s['name'] . ': ' . ($s['ok'] ? 'ok' : 'hiba'))->all()]);

        return response()->json($result);
    }

    public function registerSoftware(): JsonResponse
    {
        try {
            $message = $this->nav->registerSoftware();
        } catch (NavException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        ActivityLogger::log('nav.software_registered', $message);

        return response()->json(['message' => $message]);
    }

    /** Egy nap adatai és a beküldendő XML (nem küld semmit a NAV-nak). */
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $date = $request->query('date');

        $data = $this->nav->dayData($date);
        $c = $this->nav->config();
        $blocker = null;
        $operation = 'create';

        try {
            $plan = $this->nav->plan($date, true);
            $operation = $plan['operation'];
        } catch (NavException $e) {
            $blocker = $e->getMessage();
        }

        $previous = NavSubmission::where('applicable_date', $date)->where('environment', $c['environment'])->where('status', 'SENT')->orderByDesc('id')->first();
        $xml = $data['count'] > 0
            ? ($operation === 'modify' && $previous
                ? $this->nav->buildModifyXml((string) $previous->nav_id, $data, '(kérés-azonosító)')
                : $this->nav->buildCreateXml($data, '(kérés-azonosító)'))
            : null;

        return response()->json([
            'data' => $data,
            'operation' => $operation,
            'blocker' => $blocker,
            'sent' => $previous ? ['nav_id' => $previous->nav_id, 'sent_at' => $previous->sent_at, 'sale_count' => $previous->sale_count, 'total_amount' => $previous->total_amount] : null,
            'xml' => $xml,
            'is_today' => $date === Carbon::now(NavReceiptService::TZ)->toDateString(),
        ]);
    }

    /** Kézi beküldés 1. lépés: jelszó, ellenőrzés, összefoglaló, megerősítő kód. */
    public function prepareSend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'modify' => ['sometimes', 'boolean'],
        ]);
        $modify = (bool) ($data['modify'] ?? false);

        $this->confirmPassword($request, 'nav-send', 'A NAV beküldés');

        try {
            $plan = $this->nav->plan($data['date'], $modify);
        } catch (NavException $e) {
            ActivityLogger::log('nav.send_denied', "NAV beküldés elutasítva ({$data['date']}): " . $e->getMessage(), null, ['date' => $data['date']], subjectLabel: $data['date']);

            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $day = $this->nav->dayData($data['date']);
        $c = $this->nav->config();
        $env = $c['environment'];
        $isToday = $data['date'] === Carbon::now(NavReceiptService::TZ)->toDateString();
        $token = Str::random(40);

        Cache::put('nav-send:' . $token, [
            'user_id' => $request->user()->id,
            'date' => $data['date'],
            'modify' => $modify,
            'fingerprint' => $this->nav->fingerprint($day),
            'environment' => $env,
        ], now()->addSeconds(self::CONFIRM_TTL_SECONDS));

        $warnings = [];
        if ($env === 'live') {
            $warnings[] = 'ÉLES környezet: az adatszolgáltatás a NAV éles rendszerébe kerül. Egy rekord csak egyszer módosítható.';
        }
        if ($isToday) {
            $warnings[] = 'A mai nap még nem zárult le: a később eladott jegyek nem szerepelnek a beküldésben, azokat helyesbítéssel (módosítással) lehet utólag pótolni.';
        }
        if ($plan['operation'] === 'modify') {
            $warnings[] = 'Helyesbítés: a korábbi adatszolgáltatás (' . $plan['previous']->nav_id . ') érvénytelenítésre kerül, és új rekord jön létre.';
        }
        if (!$c['enabled']) {
            $warnings[] = 'Az integráció nincs aktiválva; teszt környezetbe a beküldés így is lehetséges.';
        }

        return response()->json([
            'token' => $token,
            'expires_in' => self::CONFIRM_TTL_SECONDS,
            'phrase' => ($env === 'live' ? 'NAV ÉLES ' : 'NAV TESZT ') . $data['date'],
            'environment' => $env,
            'operation' => $plan['operation'],
            'data' => $day,
            'warnings' => $warnings,
            'summary' => [
                'Tárgynap: ' . $data['date'],
                'Beküldendő nyugták (jegyek) száma: ' . $day['count'] . ' db, bruttó összeg: ' . number_format($day['total'], 0, ',', ' ') . ' Ft',
                'ÁFA kategória: ' . $c['vat_category'] . ', kezdő sorszám: ' . $day['serial'],
                'Művelet: ' . ($plan['operation'] === 'modify' ? 'helyesbítés (módosítás)' : 'új adatszolgáltatás'),
            ],
        ]);
    }

    /** Kézi beküldés 2. lépés: kód + megerősítő szöveg + tudomásulvétel + jelszó. */
    public function applySend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'phrase' => ['required', 'string', 'max:100'],
            'acknowledge' => ['accepted'],
        ], ['acknowledge.accepted' => 'A beküldés következményeit tudomásul kell venni.']);

        $this->confirmPassword($request, 'nav-send', 'A NAV beküldés');

        $payload = Cache::get('nav-send:' . $data['token']);
        if (!$payload || (int) $payload['user_id'] !== (int) $request->user()->id) {
            return response()->json(['message' => 'A megerősítő kód lejárt vagy érvénytelen. Kezdd újra az előkészítéssel.'], 422);
        }

        $c = $this->nav->config();
        $expected = ($payload['environment'] === 'live' ? 'NAV ÉLES ' : 'NAV TESZT ') . $payload['date'];
        if (trim($data['phrase']) !== $expected) {
            return response()->json(['message' => 'A megerősítő szöveg nem egyezik.'], 422);
        }
        if ($payload['environment'] !== $c['environment']) {
            Cache::forget('nav-send:' . $data['token']);

            return response()->json(['message' => 'A környezet közben megváltozott. Kezdd újra az előkészítéssel.'], 422);
        }
        if ($this->nav->fingerprint($this->nav->dayData($payload['date'])) !== $payload['fingerprint']) {
            Cache::forget('nav-send:' . $data['token']);

            return response()->json(['message' => 'A nap adatai közben megváltoztak (új jegy fogyott). Kezdd újra az előkészítéssel.'], 422);
        }

        Cache::forget('nav-send:' . $data['token']);

        try {
            $submission = $this->nav->send($payload['date'], 'manual', $request->user(), (bool) $payload['modify']);
        } catch (NavException $e) {
            ActivityLogger::log('nav.failed', "NAV beküldés sikertelen ({$payload['date']}): " . $e->getMessage(), null, ['date' => $payload['date']], subjectLabel: $payload['date']);

            return response()->json(['message' => $e->getMessage()], $e->status >= 500 ? 502 : 422);
        }

        ActivityLogger::log(
            'nav.sent',
            "NAV adatszolgáltatás beküldve (kézi): {$payload['date']}, {$submission->sale_count} jegy, {$submission->total_amount} Ft [{$submission->environment}]",
            null,
            ['nav_id' => $submission->nav_id, 'date' => $payload['date'], 'operation' => $submission->operation],
            subjectLabel: $payload['date']
        );

        return response()->json(['status' => 'sent', 'submission' => $submission, 'state' => $this->state()]);
    }

    private function state(): array
    {
        $c = $this->nav->config();
        $hint = fn(?string $v) => $v ? '••••' . substr($v, -2) : null;

        return [
            'enabled' => $c['enabled'],
            'configured' => $this->nav->isConfigured(),
            'active' => $this->nav->isActive(),
            'environment' => $c['environment'],
            'tax_number' => $c['tax_number'],
            'login' => $c['login'],
            'has_password' => !empty($c['password']),
            'has_signature_key' => !empty($c['signature_key']),
            'signature_key_hint' => $hint($c['signature_key']),
            'software_name' => $c['software_name'],
            'vat_category' => $c['vat_category'],
            'vat_options' => self::VAT_OPTIONS,
            'base_url' => $this->nav->baseUrl(),
            'schedule' => [
                'auto_enabled' => $c['auto_enabled'],
                'auto_time' => $c['auto_time'],
                'catchup_days' => $c['catchup_days'],
                'next_run' => $this->nav->nextAutoRun(),
                'last_run' => $c['auto_last_run'],
                'last_status' => $c['auto_last_status'],
            ],
            'unsent_days' => $this->nav->unsentDays(14),
            'today' => Carbon::now(NavReceiptService::TZ)->toDateString(),
            'yesterday' => Carbon::now(NavReceiptService::TZ)->subDay()->toDateString(),
            'submissions' => NavSubmission::orderByDesc('id')->limit(40)->get(),
        ];
    }
}
