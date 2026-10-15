<?php

namespace App\Services;

use App\Exceptions\NavException;
use App\Models\NavSubmission;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * NAV nyugtaadat-szolgáltatás (eNyugta / KOBAK) M2M integráció, a NAV "Nyugta-adatszolgáltatás interfész specifikáció" (v1.3) szerint.
 *
 * - Naponta egy aggregált adatszolgáltatás: a tárgynap eladott jegyei (minden jegy egy számítógépes nyugta) ÁFA kategória szerint
 *   összesítve, a nap első nyugtájának sorszámával.
 * - Hitelesítés: technikai felhasználó (login, SHA-512 jelszó-hash, SHA3-512 kérés-aláírás az aláírókulccsal) → 5 perces token (/auth/token),
 *   a tokent minden beküldés előtt újra kell kérni.
 * - Beküldés: /receipt/create; utólagos javítás: /receipt/modify (egy rekord csak egyszer módosítható).
 *
 * A beállítások az adatbázisban vannak (nav.* kulcsok); a jelszó és az aláírókulcs titkosítva tárolódik.
 */
class NavReceiptService
{
    public const URLS = [
        'test' => 'https://bv-receipt-if.enyugta.nav.gov.hu/v1',
        'live' => 'https://receipt-if.enyugta.nav.gov.hu/v1',
    ];

    public const DEFAULT_SOFTWARE = 'Vigado Jegy Jegyfoglalo';
    public const NS_RECEIPT = 'http://schemas.nav.gov.hu/NTCA/1.0/receipt';
    public const NS_SERVICE = 'http://schemas.nav.gov.hu/NTCA/2.0/common/service';
    public const NS_AUTH = 'http://schemas.nav.gov.hu/NTCA/2.0/common/authservice';
    public const TZ = 'Europe/Budapest';

    private const ERROR_TEXTS = [
        'EPGP0002' => 'A kiállító szoftver neve nincs regisztrálva a NAV-nál. Regisztráld a „Szoftver regisztrálása” gombbal.',
        'EPGP0009' => 'Az adatszolgáltatás módosítása nem engedélyezett.',
        'EPGP0011' => 'A módosítani kívánt adatszolgáltatás nem létezik a NAV-nál.',
        'EPGP0013' => 'Ismeretlen pénznem.',
        'EPGP0017' => 'A megadott ÁFA kategória nem létezik a NAV rendszerében. Válassz érvényes kategóriát.',
        'EPGP0020' => 'Az ÁFA kategória többször szerepel az adatszolgáltatásban.',
        'EPGP0046' => 'A kérésben és a tokenben szereplő adószám nem egyezik.',
        'EPGP0051' => 'Ehhez az adatközléshez már létezik helyesbítő adatszolgáltatás.',
        'EPGP9000' => 'A NAV rendszerében belső hiba történt, próbáld később.',
        'EPGP9001' => 'A NAV elutasította a kérést: a kérés szerkezete vagy tartalma nem felel meg a szabályoknak.',
    ];

    private ?array $config = null;

    // ------------------------------------------------------ beállítások

    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $get = fn(string $key) => Setting::find('nav.' . $key)?->value;
        $decrypt = function (?string $value): ?string {
            if (!$value) {
                return null;
            }
            try {
                return Crypt::decryptString($value);
            } catch (\Throwable) {
                return null;
            }
        };

        $time = (string) $get('auto_time');

        return $this->config = [
            'enabled' => $get('enabled') === '1',
            'environment' => $get('environment') === 'live' ? 'live' : 'test',
            'tax_number' => preg_replace('/\D/', '', (string) $get('tax_number')),
            'login' => (string) $get('login'),
            'password' => $decrypt($get('password')),
            'signature_key' => $decrypt($get('signature_key')),
            'software_name' => (string) ($get('software_name') ?: self::DEFAULT_SOFTWARE),
            'vat_category' => (string) ($get('vat_category') ?: '5%'),
            'auto_enabled' => $get('auto_enabled') === '1',
            'auto_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '02:00',
            'catchup_days' => max(0, min(30, (int) ($get('catchup_days') ?? 7))),
            'auto_last_run' => $get('auto_last_run'),
            'auto_last_attempt' => $get('auto_last_attempt'),
            'auto_attempts' => (int) $get('auto_attempts'),
            'auto_last_status' => $get('auto_last_status') ? json_decode((string) $get('auto_last_status'), true) : null,
        ];
    }

    private function put(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => 'nav.' . $key], ['value' => $value]);
        $this->config = null;
    }

    /** @param array<string,mixed> $data */
    public function saveConnection(array $data): void
    {
        $this->put('enabled', $data['enabled'] ? '1' : '0');
        $this->put('environment', $data['environment']);
        $this->put('tax_number', $data['tax_number']);
        $this->put('login', $data['login']);
        $this->put('software_name', $data['software_name']);
        $this->put('vat_category', $data['vat_category']);

        // Üresen hagyott jelszó / kulcs: a meglévő marad
        if (!empty($data['password'])) {
            $this->put('password', Crypt::encryptString($data['password']));
        }
        if (!empty($data['signature_key'])) {
            $this->put('signature_key', Crypt::encryptString($data['signature_key']));
        }
    }

    public function saveSchedule(bool $enabled, string $time, int $catchupDays): void
    {
        $this->put('auto_enabled', $enabled ? '1' : '0');
        $this->put('auto_time', $time);
        $this->put('catchup_days', (string) $catchupDays);
        // Az ütemezés módosítása után az aznapi futás újra esedékes lehet
        $this->put('auto_attempts', '0');
        $this->put('auto_last_attempt', null);
    }

    public function isConfigured(): bool
    {
        $c = $this->config();

        return strlen($c['tax_number']) === 8 && $c['login'] !== '' && !empty($c['password']) && !empty($c['signature_key']);
    }

    /** Az integráció aktiválva van és teljesen be van állítva. */
    public function isActive(): bool
    {
        return $this->config()['enabled'] && $this->isConfigured();
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('services.nav.base_url_override'), '/') ?: self::URLS[$this->config()['environment']];
    }

    // -------------------------------------------------- napi adatok

    /** @return array{0:Carbon,1:Carbon} a nap (budapesti idő szerint) UTC határai */
    public function dayRange(string $date): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $date, self::TZ)->startOfDay();

        return [$start->copy()->utc(), $start->copy()->endOfDay()->utc()];
    }

    /**
     * A tárgynap eladott jegyeinek összesítése a nyugtaadat-szolgáltatáshoz.
     * Minden jegy egy számítógépes nyugta; módosító vagy érvénytelenítő bizonylat nincs (a jegyek nem visszaválthatók).
     */
    public function dayData(string $date): array
    {
        [$from, $to] = $this->dayRange($date);

        $tickets = Ticket::query()
            ->whereBetween('sold_at', [$from, $to])
            ->orderBy('sold_at')
            ->orderBy('id')
            ->get(['id', 'ticket_number', 'price_amount', 'payment_method', 'sold_at']);

        $total = (int) $tickets->sum('price_amount');
        $c = $this->config();

        return [
            'date' => $date,
            'count' => $tickets->count(),
            'total' => $total,
            'serial' => (string) ($tickets->first()->ticket_number ?? ''),
            'last_serial' => (string) ($tickets->last()->ticket_number ?? ''),
            'currency' => 'HUF',
            'vat' => [['name' => $c['vat_category'], 'sale' => $total, 'modifying' => 0]],
            'by_method' => $tickets->groupBy('payment_method')->map(fn($g, $m) => ['method' => $m, 'tickets' => $g->count(), 'amount' => (int) $g->sum('price_amount')])->values()->all(),
        ];
    }

    /** Ujjlenyomat az adatokról: a megerősítés és a beküldés között megváltozott adatok kiszűrésére. */
    public function fingerprint(array $data): string
    {
        return hash('sha256', json_encode([$data['count'], $data['total'], $data['serial'], $data['vat']]));
    }

    /** A hónap utolsó napjai közül azok, ahol volt eladás, de még nincs sikeres beküldés (a mai nap nélkül). @return array<int,string> */
    public function unsentDays(int $lookbackDays): array
    {
        $env = $this->config()['environment'];
        $today = Carbon::now(self::TZ)->startOfDay();
        $since = $today->copy()->subDays($lookbackDays);

        $days = Ticket::query()
            ->where('sold_at', '>=', $since->copy()->utc())
            ->where('sold_at', '<', $today->copy()->utc())
            ->pluck('sold_at')
            ->map(fn($t) => Carbon::parse($t)->timezone(self::TZ)->toDateString())
            ->unique()
            ->sort()
            ->values();

        $sent = NavSubmission::where('environment', $env)->where('status', 'SENT')
            ->whereIn('applicable_date', $days->all())->pluck('applicable_date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())->all();

        return $days->reject(fn($d) => in_array($d, $sent, true))->values()->all();
    }

    // ---------------------------------------------------------- XML

    private function x(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function timestamp(?float $at = null): string
    {
        $at ??= microtime(true);

        return gmdate('Y-m-d\TH:i:s', (int) $at) . '.' . sprintf('%03d', (int) (($at - floor($at)) * 1000)) . 'Z';
    }

    private function money(float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function contextXml(string $requestId, string $timestamp): string
    {
        return '<s:context><s:requestId>' . $this->x($requestId) . '</s:requestId><s:timestamp>' . $timestamp . '</s:timestamp></s:context>';
    }

    private function vatItemsXml(array $data): string
    {
        $xml = '<vatCategoryItems>';
        foreach ($data['vat'] as $row) {
            $xml .= '<vatCategory><vat>' . $this->x($row['name']) . '</vat><saleDocument>' . $this->money($row['sale'])
                . '</saleDocument><modifyingDocument>' . $this->money($row['modifying']) . '</modifyingDocument></vatCategory>';
        }

        return $xml . '</vatCategoryItems>';
    }

    private function head(string $root): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<' . $root . ' xmlns="' . self::NS_RECEIPT . '" xmlns:s="' . self::NS_SERVICE . '">';
    }

    public function buildCreateXml(array $data, string $requestId, ?string $timestamp = null): string
    {
        $c = $this->config();

        return $this->head('CreateReceiptRequest')
            . $this->contextXml($requestId, $timestamp ?? $this->timestamp())
            . '<taxPayerId>' . $c['tax_number'] . '</taxPayerId>'
            . '<issuingSoftware><name>' . $this->x($c['software_name']) . '</name></issuingSoftware>'
            . '<applicableDate>' . $data['date'] . '</applicableDate>'
            . '<serialNumber>' . $this->x($data['serial']) . '</serialNumber>'
            . '<currency>' . $data['currency'] . '</currency>'
            . '<exchangeRate xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:nil="true"/>'
            . $this->vatItemsXml($data)
            . '<total>' . $this->money($data['total']) . '</total>'
            . '<numberOfSaleDocument>' . $data['count'] . '</numberOfSaleDocument>'
            . '<numberOfModifyingDocument>0</numberOfModifyingDocument>'
            . '</CreateReceiptRequest>';
    }

    public function buildModifyXml(string $navId, array $data, string $requestId, ?string $timestamp = null): string
    {
        $c = $this->config();

        return $this->head('ModifyReceiptRequest')
            . $this->contextXml($requestId, $timestamp ?? $this->timestamp())
            . '<id>' . $this->x($navId) . '</id>'
            . '<taxPayerId>' . $c['tax_number'] . '</taxPayerId>'
            . '<issuingSoftware><name>' . $this->x($c['software_name']) . '</name></issuingSoftware>'
            . '<applicableDate>' . $data['date'] . '</applicableDate>'
            . '<currency>' . $data['currency'] . '</currency>'
            . '<exchangeRate xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:nil="true"/>'
            . $this->vatItemsXml($data)
            . '<total>' . $this->money($data['total']) . '</total>'
            . '<numberOfSaleDocument>' . $data['count'] . '</numberOfSaleDocument>'
            . '<numberOfModifyingDocument>0</numberOfModifyingDocument>'
            . '</ModifyReceiptRequest>';
    }

    /** SHA3-512 kérés-aláírás: requestId + időbélyeg (yyyyMMddHHmmss, UTC) + aláírókulcs, nagybetűs hex. */
    public function requestSignature(string $requestId, string $timestamp, string $signatureKey): string
    {
        $compact = Carbon::parse($timestamp)->utc()->format('YmdHis');

        return strtoupper(hash('sha3-512', $requestId . $compact . $signatureKey));
    }

    public function buildAuthXml(string $requestId, string $timestamp): string
    {
        $c = $this->config();

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<AuthTokenRequest xmlns="' . self::NS_RECEIPT . '" xmlns:auth="' . self::NS_AUTH . '" xmlns:srv="' . self::NS_SERVICE . '">'
            . '<srv:context><srv:requestId>' . $this->x($requestId) . '</srv:requestId><srv:timestamp>' . $timestamp . '</srv:timestamp></srv:context>'
            . '<auth:auth>'
            . '<auth:login>' . $this->x($c['login']) . '</auth:login>'
            . '<auth:passwordHash cryptoType="SHA-512">' . strtoupper(hash('sha512', (string) $c['password'])) . '</auth:passwordHash>'
            . '<auth:taxNumber>' . $c['tax_number'] . '</auth:taxNumber>'
            . '<auth:requestSignature cryptoType="SHA3-512">' . $this->requestSignature($requestId, $timestamp, (string) $c['signature_key']) . '</auth:requestSignature>'
            . '</auth:auth>'
            . '<auth:requestVersion>1.0</auth:requestVersion>'
            . '<auth:headerVersion>1.0</auth:headerVersion>'
            . '</AuthTokenRequest>';
    }

    // ------------------------------------------------- kommunikáció

    /**
     * Technikai felhasználóval token igénylése. A token 5 percig érvényes, ezért beküldésenként kérjük.
     *
     * @throws NavException
     */
    public function token(): string
    {
        if (!$this->isConfigured()) {
            throw new NavException('A NAV kapcsolati adatok hiányosak (adószám, felhasználónév, jelszó, aláírókulcs).');
        }

        $requestId = 'VIG' . strtoupper(Str::random(20));
        $xml = $this->buildAuthXml($requestId, $this->timestamp());
        $res = $this->post('/auth/token', $xml, null);

        // A sikeres AuthTokenResponse a specifikáció szerint csak a tokent és a lejáratot tartalmazza (resultCode nélkül)
        $token = $this->node($res['dom'], 'token');
        if (!$token || ($res['result'] !== null && $res['result'] !== 'SUCCESS')) {
            throw $this->errorFrom($res, 'A NAV hitelesítés sikertelen');
        }

        return $token;
    }

    /**
     * @return array{status:int,body:string,dom:?\DOMDocument,result:?string}
     * @throws NavException
     */
    private function post(string $path, string $xml, ?string $token): array
    {
        $headers = ['Accept' => 'application/xml'];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $response = Http::timeout(30)->withHeaders($headers)->withBody($xml, 'application/xml; charset=UTF-8')->post($this->baseUrl() . $path);
        } catch (\Throwable $e) {
            throw new NavException('A NAV szerver nem érhető el: ' . $e->getMessage(), 502);
        }

        $body = $response->body();
        $dom = null;
        if (trim($body) !== '') {
            $prev = libxml_use_internal_errors(true);
            $doc = new \DOMDocument();
            $doc->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $dom = $doc->documentElement ? $doc : null;
        }

        return [
            'status' => $response->status(),
            'body' => $body,
            'dom' => $dom,
            'result' => $dom ? $this->node($dom, 'resultCode') : null,
        ];
    }

    private function node(?\DOMDocument $dom, string $localName): ?string
    {
        if (!$dom) {
            return null;
        }
        $xp = new \DOMXPath($dom);
        $n = $xp->query("//*[local-name()='{$localName}']")->item(0);

        return $n ? trim($n->textContent) : null;
    }

    /** @return array<int,string> */
    private function nodes(?\DOMDocument $dom, string $xpath): array
    {
        if (!$dom) {
            return [];
        }
        $xp = new \DOMXPath($dom);
        $out = [];
        foreach ($xp->query($xpath) as $n) {
            $out[] = trim($n->textContent);
        }

        return $out;
    }

    /** A NAV hibaválaszából érthető kivétel. */
    private function errorFrom(array $res, string $prefix): NavException
    {
        $code = $this->node($res['dom'], 'errorCode');
        $message = $this->node($res['dom'], 'message');
        $fields = [];
        if ($res['dom']) {
            $xp = new \DOMXPath($res['dom']);
            foreach ($xp->query("//*[local-name()='error'][*[local-name()='field']]") as $err) {
                $f = $xp->query("*[local-name()='field']", $err)->item(0)?->textContent;
                $m = $xp->query("*[local-name()='error']", $err)->item(0)?->textContent;
                $fields[] = trim($f . ': ' . $m);
            }
        }

        $text = ($code && isset(self::ERROR_TEXTS[$code])) ? self::ERROR_TEXTS[$code] : ($message ?: 'ismeretlen hiba');
        $detail = $fields ? ' (' . implode('; ', array_slice($fields, 0, 4)) . ')' : '';

        if ($res['dom'] === null) {
            $text = 'HTTP ' . $res['status'] . ': ' . mb_substr(strip_tags($res['body']), 0, 200);
        }

        return new NavException("{$prefix}: {$text}{$detail}" . ($code ? " [{$code}]" : ''), 422, $code);
    }

    // ------------------------------------------------------- tesztek

    /**
     * Kapcsolat-ellenőrzés: hitelesítés, ÁFA kategóriák, regisztrált szoftverek lekérése.
     *
     * @return array{ok:bool,steps:array,vat_categories:array,software_registered:?bool}
     */
    public function testConnection(): array
    {
        $steps = [];
        $vat = [];
        $registered = null;
        $c = $this->config();

        try {
            $token = $this->token();
            $steps[] = ['name' => 'Hitelesítés (technikai felhasználó)', 'ok' => true, 'message' => 'Sikeres, a NAV tokent adott.'];
        } catch (NavException $e) {
            return ['ok' => false, 'steps' => [['name' => 'Hitelesítés (technikai felhasználó)', 'ok' => false, 'message' => $e->getMessage()]], 'vat_categories' => [], 'software_registered' => null];
        }

        try {
            $res = $this->post('/vat-category/list', $this->head('VatCategoryRequest') . $this->contextXml((string) Str::uuid(), $this->timestamp()) . '</VatCategoryRequest>', $token);
            if ($res['result'] !== 'SUCCESS') {
                throw $this->errorFrom($res, 'ÁFA kategóriák lekérése sikertelen');
            }
            $vat = $this->nodes($res['dom'], "//*[local-name()='category']/*[local-name()='name']");
            $ok = in_array($c['vat_category'], $vat, true);
            $steps[] = [
                'name' => 'ÁFA kategóriák',
                'ok' => $ok,
                'message' => $ok ? 'A beállított ÁFA kategória (' . $c['vat_category'] . ') érvényes.' : 'A beállított ÁFA kategória (' . $c['vat_category'] . ') nincs a NAV listájában: ' . implode(', ', $vat),
            ];
        } catch (NavException $e) {
            $steps[] = ['name' => 'ÁFA kategóriák', 'ok' => false, 'message' => $e->getMessage()];
        }

        try {
            $names = $this->softwareNames($token);
            $registered = in_array($c['software_name'], $names, true);
            $steps[] = [
                'name' => 'Kiállító szoftver',
                'ok' => $registered,
                'message' => $registered ? '„' . $c['software_name'] . '” regisztrálva van a NAV-nál.' : '„' . $c['software_name'] . '” még nincs regisztrálva a NAV-nál. Használd a „Szoftver regisztrálása” gombot.',
            ];
        } catch (NavException $e) {
            $steps[] = ['name' => 'Kiállító szoftver', 'ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => collect($steps)->every(fn($s) => $s['ok']), 'steps' => $steps, 'vat_categories' => $vat, 'software_registered' => $registered];
    }

    /** @return array<int,string> @throws NavException */
    private function softwareNames(string $token): array
    {
        $c = $this->config();
        $xml = $this->head('IssuingSoftwareListRequest')
            . $this->contextXml((string) Str::uuid(), $this->timestamp())
            . '<taxPayerId>' . $c['tax_number'] . '</taxPayerId></IssuingSoftwareListRequest>';
        $res = $this->post('/issuing-software/list', $xml, $token);

        if ($res['result'] !== 'SUCCESS') {
            throw $this->errorFrom($res, 'A szoftverlista lekérése sikertelen');
        }

        return $this->nodes($res['dom'], "//*[local-name()='software']/*[local-name()='name']");
    }

    /** A kiállító szoftver nevének rögzítése a NAV-nál (ha még nincs). @throws NavException */
    public function registerSoftware(): string
    {
        $c = $this->config();
        $token = $this->token();

        if (in_array($c['software_name'], $this->softwareNames($token), true)) {
            return 'A(z) „' . $c['software_name'] . '” szoftver már regisztrálva van a NAV-nál.';
        }

        $xml = $this->head('CreateIssuingSoftwareRequest')
            . $this->contextXml((string) Str::uuid(), $this->timestamp())
            . '<taxPayerId>' . $c['tax_number'] . '</taxPayerId><name>' . $this->x($c['software_name']) . '</name></CreateIssuingSoftwareRequest>';
        $res = $this->post('/issuing-software/create', $xml, $token);

        if ($res['result'] !== 'SUCCESS') {
            throw $this->errorFrom($res, 'A szoftver regisztrálása sikertelen');
        }

        return 'A(z) „' . $c['software_name'] . '” szoftver regisztrálva a NAV-nál.';
    }

    // ------------------------------------------------------ beküldés

    /** Mehet-e a beküldés a megadott napra, és milyen művelettel. @return array{operation:string,previous:?NavSubmission} @throws NavException */
    public function plan(string $date, bool $allowModify = false): array
    {
        $c = $this->config();

        if (!$this->isConfigured()) {
            throw new NavException('A NAV kapcsolati adatok hiányosak, előbb add meg és mentsd el őket.');
        }
        if (!$c['enabled'] && $c['environment'] === 'live') {
            throw new NavException('Az integráció nincs aktiválva, éles környezetbe nem küldhető be adat. Aktiváld a beállításokban.');
        }
        if (Carbon::createFromFormat('Y-m-d', $date, self::TZ)->startOfDay()->gt(Carbon::now(self::TZ))) {
            throw new NavException('Jövőbeli napra nem küldhető adatszolgáltatás.');
        }

        $data = $this->dayData($date);
        if ($data['count'] === 0) {
            throw new NavException('Erre a napra nincs eladott jegy, nincs mit beküldeni.');
        }

        $previous = NavSubmission::where('applicable_date', $date)->where('environment', $c['environment'])->where('status', 'SENT')->orderByDesc('id')->first();

        if ($previous && !$allowModify) {
            throw new NavException('Erre a napra már van sikeres beküldés (' . $previous->nav_id . '). Módosításhoz használd a helyesbítés lehetőséget.');
        }
        if ($previous && $previous->sale_count === $data['count'] && $previous->total_amount === $data['total']) {
            throw new NavException('A beküldött adatok megegyeznek a jelenlegiekkel, nincs mit helyesbíteni.');
        }

        return ['operation' => $previous ? 'modify' : 'create', 'previous' => $previous];
    }

    /**
     * Egy nap adatszolgáltatásának beküldése. A művelet zár alatt fut; minden kísérlet naplózódik.
     *
     * @throws NavException
     */
    public function send(string $date, string $trigger, ?User $user = null, bool $allowModify = false): NavSubmission
    {
        $lock = Cache::lock('nav-send', 120);
        if (!$lock->get()) {
            throw new NavException('Másik NAV beküldés van folyamatban, próbáld újra később.', 409);
        }

        try {
            $plan = $this->plan($date, $allowModify);
            $data = $this->dayData($date);
            $c = $this->config();
            $requestId = (string) Str::uuid();
            $isModify = $plan['operation'] === 'modify';

            $xml = $isModify
                ? $this->buildModifyXml((string) $plan['previous']->nav_id, $data, $requestId)
                : $this->buildCreateXml($data, $requestId);

            $submission = NavSubmission::create([
                'applicable_date' => $date,
                'environment' => $c['environment'],
                'operation' => $plan['operation'],
                'trigger' => $trigger,
                'status' => 'PENDING',
                'replaces_id' => $plan['previous']?->id,
                'serial_number' => $data['serial'],
                'sale_count' => $data['count'],
                'total_amount' => $data['total'],
                'vat_category' => $c['vat_category'],
                'request_id' => $requestId,
                'sent_by' => $user?->name ?? 'Automatikus',
                'request_xml' => $xml,
            ]);

            try {
                $token = $this->token();
                $res = $this->post($isModify ? '/receipt/modify' : '/receipt/create', $xml, $token);
                $id = $this->node($res['dom'], 'id');

                if ($res['result'] !== 'SUCCESS' || !$id) {
                    throw $this->errorFrom($res, 'A NAV elutasította az adatszolgáltatást');
                }

                $submission->update(['status' => 'SENT', 'nav_id' => $id, 'sent_at' => now(), 'response_xml' => $res['body']]);
                if ($plan['previous']) {
                    $plan['previous']->update(['status' => 'REPLACED']);
                }
            } catch (NavException $e) {
                $submission->update(['status' => 'FAILED', 'error_code' => $e->errorCode, 'error_message' => mb_substr($e->getMessage(), 0, 1000)]);
                throw $e;
            }

            return $submission->fresh();
        } finally {
            $lock->release();
        }
    }

    // -------------------------------------------- automatikus küldés

    /** Következő automatikus futás szövegesen (a felülethez). */
    public function nextAutoRun(): ?string
    {
        $c = $this->config();
        if (!$c['auto_enabled']) {
            return null;
        }

        $now = Carbon::now(self::TZ);
        [$h, $m] = array_map('intval', explode(':', $c['auto_time']));
        $due = $now->copy()->setTime($h, $m, 0);

        if ($c['auto_last_run'] === $now->toDateString() || $c['auto_attempts'] >= 6) {
            return $due->copy()->addDay()->toIso8601String();
        }

        return $due->toIso8601String();
    }

    /**
     * Ha esedékes az automatikus beküldés (aktív integráció + bekapcsolt ütemezés + elmúlt az időpont), a még be nem küldött
     * lezárt napokat beküldi. Sikertelen próbálkozásnál 30 percenként újra próbálja, naponta legfeljebb 6 alkalommal.
     */
    public function runDue(): ?array
    {
        $c = $this->config();
        if (!$this->isActive() || !$c['auto_enabled']) {
            return null;
        }

        $now = Carbon::now(self::TZ);
        [$h, $m] = array_map('intval', explode(':', $c['auto_time']));
        $today = $now->toDateString();

        if ($now->lt($now->copy()->setTime($h, $m, 0)) || $c['auto_last_run'] === $today) {
            return null;
        }
        if ($c['auto_attempts'] >= 6 && $c['auto_last_attempt'] && Carbon::parse($c['auto_last_attempt'])->timezone(self::TZ)->toDateString() === $today) {
            return null;
        }
        if ($c['auto_last_attempt'] && Carbon::parse($c['auto_last_attempt'])->gt(now()->subMinutes(30)) && $c['auto_attempts'] > 0) {
            return null;
        }

        $lock = Cache::lock('nav-auto-run', 300);
        if (!$lock->get()) {
            return null;
        }

        try {
            $attempts = (Carbon::parse($c['auto_last_attempt'] ?? '2000-01-01')->timezone(self::TZ)->toDateString() === $today ? $c['auto_attempts'] : 0) + 1;
            $this->put('auto_attempts', (string) $attempts);
            $this->put('auto_last_attempt', now()->toIso8601String());

            $results = [];
            $failed = false;
            foreach ($this->unsentDays($c['catchup_days'] + 1) as $day) {
                try {
                    $s = $this->send($day, 'auto');
                    $results[] = ['date' => $day, 'ok' => true, 'nav_id' => $s->nav_id];
                    ActivityLogger::log('nav.sent', "NAV adatszolgáltatás beküldve (automatikus): {$day}, {$s->sale_count} jegy, {$s->total_amount} Ft", null, ['nav_id' => $s->nav_id, 'date' => $day], user: null, subjectLabel: $day);
                } catch (NavException $e) {
                    $failed = true;
                    $results[] = ['date' => $day, 'ok' => false, 'error' => $e->getMessage()];
                    ActivityLogger::log('nav.failed', "NAV adatszolgáltatás sikertelen (automatikus): {$day}: " . $e->getMessage(), null, ['date' => $day], user: null, subjectLabel: $day);
                }
            }

            $status = ['at' => now()->toIso8601String(), 'ok' => !$failed, 'sent' => collect($results)->where('ok', true)->count(), 'failed' => collect($results)->where('ok', false)->count(),
                'message' => $failed ? (string) collect($results)->firstWhere('ok', false)['error'] : (count($results) ? count($results) . ' nap beküldve' : 'Nem volt beküldendő nap')];
            $this->put('auto_last_status', json_encode($status, JSON_UNESCAPED_UNICODE));

            if (!$failed) {
                $this->put('auto_last_run', $today);
            }

            return $results;
        } finally {
            $lock->release();
        }
    }
}
