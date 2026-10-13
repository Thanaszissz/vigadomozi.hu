<?php

namespace App\Services;

use App\Exceptions\SimplePayException;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OTP SimplePay v2.1 integráció (kártyás fizetés átirányítással).
 *
 * - Minden kérést HMAC-SHA384 aláírással hitelesítünk (Signature fejléc, base64), a válaszok és az értesítések aláírását ellenőrizzük.
 * - Fizetés indítása: POST {api}/v2/start, a válaszban kapott paymentUrl-re irányítjuk a vásárlót.
 * - A vásárló a "url" címre tér vissza (r és s paraméter: aláírt eredmény), a végleges értesítés az IPN (a SimplePay
 *   kereskedői felületén megadott címre érkezik). A kifizetés mindkét úton jóváírható, a feldolgozás idempotens.
 * A beállítások (azonosító, titkos kulcs, környezet) az adatbázisban vannak; a titkos kulcs titkosítva tárolódik.
 */
class SimplePayService
{
    public const SDK_VERSION = 'VigadoMozi_Laravel_1.0';
    public const PROVIDER = 'simplepay';

    public const API = [
        'sandbox' => 'https://sandbox.simplepay.hu/payment',
        'live' => 'https://secure.simplepay.hu/payment',
    ];

    public const LANGUAGES = ['HU', 'EN', 'DE', 'IT', 'FR', 'ES', 'RU', 'PL', 'CZ', 'SK', 'RO'];

    private ?array $config = null;

    // ------------------------------------------------------ beállítások

    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $get = fn(string $key) => Setting::find('simplepay.' . $key)?->value;

        $secret = null;
        if ($encrypted = $get('secret_key')) {
            try {
                $secret = Crypt::decryptString($encrypted);
            } catch (\Throwable) {
                $secret = null;
            }
        }

        $environment = $get('environment');
        $language = $get('language');

        return $this->config = [
            'enabled' => $get('enabled') === '1',
            'environment' => in_array($environment, ['sandbox', 'live'], true) ? $environment : 'sandbox',
            'merchant' => trim((string) $get('merchant')),
            'secret_key' => $secret,
            'language' => in_array($language, self::LANGUAGES, true) ? $language : 'HU',
            'timeout_minutes' => max(5, min(120, (int) ($get('timeout_minutes') ?: 15))),
            'site_url' => rtrim((string) ($get('site_url') ?: config('app.url')), '/'),
        ];
    }

    /** @param array{enabled:bool,environment:string,merchant:string,secret_key:?string,language:string,timeout_minutes:int,site_url:string} $data */
    public function save(array $data): void
    {
        $set = fn(string $key, string $value) => Setting::updateOrCreate(['key' => 'simplepay.' . $key], ['value' => $value]);

        $set('enabled', $data['enabled'] ? '1' : '0');
        $set('environment', $data['environment']);
        $set('merchant', trim($data['merchant']));
        $set('language', $data['language']);
        $set('timeout_minutes', (string) $data['timeout_minutes']);
        $set('site_url', rtrim($data['site_url'], '/'));

        // Üresen hagyott kulcs: a meglévő marad
        if (!empty($data['secret_key'])) {
            $set('secret_key', Crypt::encryptString($data['secret_key']));
        }

        $this->config = null;
    }

    /** Be van-e kapcsolva és teljesen be van-e állítva (az online fizetés ezen múlik). */
    public function isEnabled(): bool
    {
        $c = $this->config();

        return $c['enabled'] && $c['merchant'] !== '' && !empty($c['secret_key']);
    }

    /** Az API nyilvános címe (a SimplePay erre tér vissza és erre küld értesítést). */
    public function siteUrl(): string
    {
        return $this->config()['site_url'];
    }

    /** A vásárló fizetés utáni oldalai: külön frontend domain esetén a FRONTEND_URL, egyébként ugyanaz a cím. */
    public function frontendUrl(): string
    {
        return rtrim((string) (config('app.frontend_url') ?: $this->siteUrl()), '/');
    }

    /** A SimplePay kereskedői felületén megadandó és a kérésekben küldött címek. */
    public function urls(): array
    {
        $site = $this->siteUrl();

        return [
            'ipn' => $site . '/api/webhooks/simplepay',
            'return' => $site . '/api/payments/simplepay/return',
            'api' => self::API[$this->config()['environment']] . '/v2/start',
        ];
    }

    // ------------------------------------------------------- aláírás

    public function sign(string $body): string
    {
        return base64_encode(hash_hmac('sha384', $body, (string) $this->config()['secret_key'], true));
    }

    public function verify(string $body, ?string $signature): bool
    {
        if (!$this->config()['secret_key'] || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals($this->sign($body), $signature);
    }

    // ------------------------------------------------- fizetés indítása

    /**
     * Fizetés indítása egy foglaláshoz. A foglalás székeinek zárolása a fizetési időkorlátig meghosszabbodik.
     *
     * @return string a SimplePay fizetési oldalának címe
     * @throws SimplePayException
     */
    public function start(Reservation $reservation): string
    {
        if (!$this->isEnabled()) {
            throw new SimplePayException('Az online fizetés nincs bekapcsolva.', 503);
        }

        $c = $this->config();
        $amount = (int) $reservation->total_amount;
        if ($amount <= 0) {
            throw new SimplePayException('A foglalás végösszege érvénytelen.', 422);
        }

        $timeoutAt = Carbon::now()->addMinutes($c['timeout_minutes']);
        $orderRef = 'VIG-' . $reservation->id . '-' . strtoupper(Str::random(8));

        $payment = Payment::create([
            'reservation_id' => $reservation->id,
            'provider' => self::PROVIDER,
            'order_ref' => $orderRef,
            'status' => 'INIT',
            'amount' => $amount,
            'currency' => 'HUF',
            'environment' => $c['environment'],
        ]);

        $payload = [
            'salt' => $this->salt(),
            'merchant' => $c['merchant'],
            'orderRef' => $orderRef,
            'currency' => 'HUF',
            'customerEmail' => $reservation->customer_email,
            'language' => $c['language'],
            'sdkVersion' => self::SDK_VERSION,
            'methods' => ['CARD'],
            'total' => (string) $amount,
            'timeout' => $timeoutAt->format('c'),
            'url' => $this->urls()['return'],
        ];

        try {
            $result = $this->call('start', $payload);
        } catch (SimplePayException $e) {
            $payment->update(['status' => 'ERROR', 'details' => ['error' => $e->getMessage()]]);
            throw $e;
        }

        $body = $result['body'];
        if (empty($body['paymentUrl']) || empty($body['transactionId'])) {
            $payment->update(['status' => 'ERROR', 'details' => ['response' => $body]]);
            throw new SimplePayException('A SimplePay nem adott fizetési címet.');
        }

        $payment->update(['transaction_id' => (string) $body['transactionId']]);

        // A székek a fizetési időkorlát végéig (+ pár perc az értesítés beérkezéséig) zárolva maradnak
        app(SeatClaimService::class)->adjustHold($reservation, $timeoutAt->copy()->addMinutes(2));

        ActivityLogger::log(
            'payment.simplepay_started',
            sprintf('SimplePay fizetés indítva: #%d foglalás, %d Ft (%s)', $reservation->id, $amount, $orderRef),
            $reservation,
            ['order_ref' => $orderRef, 'transaction_id' => $body['transactionId'], 'environment' => $c['environment']],
            user: null,
            subjectLabel: 'Foglalás #' . $reservation->id
        );

        return (string) $body['paymentUrl'];
    }

    // ----------------------------------------- visszatérés és értesítés

    /**
     * A vásárló böngészőjének visszatérése (r: base64 JSON, s: aláírás a dekódolt JSON-ról).
     *
     * @return array{o:string,t:?string,e:string,m:string,r:mixed}
     * @throws SimplePayException
     */
    public function parseBack(string $r, string $s): array
    {
        $json = base64_decode($r, true);
        if ($json === false || !$this->verify($json, $s)) {
            throw new SimplePayException('Érvénytelen visszatérési aláírás.', 400);
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['o']) || empty($data['e'])) {
            throw new SimplePayException('Hibás visszatérési adatok.', 400);
        }
        if (($data['m'] ?? null) !== $this->config()['merchant']) {
            throw new SimplePayException('A visszatérés más kereskedőhöz tartozik.', 400);
        }

        return [
            'o' => (string) $data['o'],
            't' => isset($data['t']) ? (string) $data['t'] : null,
            'e' => strtoupper((string) $data['e']),
            'm' => (string) $data['m'],
            'r' => $data['r'] ?? null,
        ];
    }

    /**
     * IPN ellenőrzése.
     *
     * @return array|null az értesítés tartalma, ha az aláírás érvényes
     */
    public function parseIpn(string $body, ?string $signature): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !$this->verify($body, $signature)) {
            return null;
        }

        if (isset($data['merchant']) && $data['merchant'] !== $this->config()['merchant']) {
            return null;
        }

        return $data;
    }

    /** Az IPN nyugtázása: az eredeti tartalom + receiveDate, aláírva. @return array{0:string,1:string} [törzs, aláírás] */
    public function ipnConfirmation(array $content): array
    {
        $content['receiveDate'] = date('c');
        $body = json_encode($content);

        return [$body, $this->sign($body)];
    }

    // ------------------------------------------------- lekérdezés, teszt

    /**
     * Tranzakciók lekérdezése rendelés-azonosító alapján.
     *
     * @throws SimplePayException
     */
    public function query(array $orderRefs): array
    {
        return $this->call('query', [
            'salt' => $this->salt(),
            'merchant' => $this->config()['merchant'],
            'orderRefs' => array_values($orderRefs),
            'detailed' => false,
            'refunds' => false,
        ], true)['body'];
    }

    /**
     * Kapcsolat-ellenőrzés: aláírt lekérdezés egy nem létező rendelésre. Ha a SimplePay válasza a mi kulcsunkkal
     * érvényesen aláírt, az azonosító és a titkos kulcs egyezik a SimplePay-nél lévővel.
     *
     * @return array{ok:bool,message:string,http_status:?int,error_codes:array,signature_valid:?bool}
     */
    public function testConnection(): array
    {
        $c = $this->config();
        if ($c['merchant'] === '' || empty($c['secret_key'])) {
            return ['ok' => false, 'message' => 'Előbb add meg és mentsd el a kereskedő azonosítót és a titkos kulcsot.', 'http_status' => null, 'error_codes' => [], 'signature_valid' => null];
        }

        try {
            $result = $this->call('query', [
                'salt' => $this->salt(),
                'merchant' => $c['merchant'],
                'orderRefs' => ['VIG-KAPCSOLATTESZT-' . strtoupper(Str::random(6))],
                'detailed' => false,
                'refunds' => false,
            ], true);
        } catch (SimplePayException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'http_status' => null, 'error_codes' => [], 'signature_valid' => null];
        }

        $codes = array_map('strval', (array) ($result['body']['errorCodes'] ?? []));

        if ($result['signature_valid']) {
            return [
                'ok' => true,
                'message' => 'A kapcsolat rendben: a SimplePay felismerte a kereskedőt, a válasz aláírása érvényes (' . ($c['environment'] === 'live' ? 'éles' : 'teszt') . ' környezet).',
                'http_status' => $result['status'],
                'error_codes' => $codes,
                'signature_valid' => true,
            ];
        }

        return [
            'ok' => false,
            'message' => 'A SimplePay nem fogadta el a kérést vagy a válasz aláírása nem érvényes' . ($codes ? ' (hibakódok: ' . implode(', ', $codes) . ')' : '')
                . '. Ellenőrizd a kereskedő azonosítót, a titkos kulcsot és a környezetet.',
            'http_status' => $result['status'],
            'error_codes' => $codes,
            'signature_valid' => false,
        ];
    }

    // ------------------------------------------------------ segédek

    /**
     * Aláírt kérés küldése a SimplePay-nek.
     *
     * @return array{status:int,body:array,signature_valid:bool}
     * @throws SimplePayException
     */
    private function call(string $endpoint, array $payload, bool $lenient = false): array
    {
        $c = $this->config();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $url = self::API[$c['environment']] . '/v2/' . $endpoint;

        try {
            $response = Http::timeout(25)
                ->withHeaders(['Signature' => $this->sign($json), 'Accept' => 'application/json'])
                ->withBody($json, 'application/json')
                ->post($url);
        } catch (\Throwable $e) {
            throw new SimplePayException('A SimplePay szerver nem érhető el: ' . $e->getMessage());
        }

        $raw = $response->body();
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw new SimplePayException('A SimplePay válasza értelmezhetetlen (HTTP ' . $response->status() . ').');
        }

        $valid = $this->verify($raw, $response->header('Signature'));

        if (!$lenient) {
            if (!empty($body['errorCodes'])) {
                throw new SimplePayException('A SimplePay hibát jelzett (kód: ' . implode(', ', (array) $body['errorCodes']) . ').');
            }
            if (!$valid) {
                throw new SimplePayException('A SimplePay válaszának aláírása érvénytelen.');
            }
        }

        return ['status' => $response->status(), 'body' => $body, 'signature_valid' => $valid];
    }

    private function salt(): string
    {
        return bin2hex(random_bytes(16));
    }
}
