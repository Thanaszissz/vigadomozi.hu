<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Számlázz.hu Számla Agent integráció: ÁFÁS számla kiállítása a helyszíni eladásokhoz.
 *
 * - A kérés egy xmlszamla XML (multipart, "action-xmlagentxmlfile" mező), a hitelesítés a Számla Agent kulccsal történik.
 * - valaszVerzio=2: a válasz XML (sikeres, hibakod, hibauzenet, szamlaszam, pdf), a PDF a szerveren tárolódik.
 * - A szamlaKulsoAzon (foglalásonként egyedi) megakadályozza a dupla számlázást akkor is, ha a kérés megismétlődik.
 * A kulcs titkosítva tárolódik, a felületre soha nem kerül vissza.
 */
class SzamlazzService
{
    public const ENDPOINT = 'https://www.szamlazz.hu/szamla/';

    /** A választható ÁFA kulcsok (a Számlázz.hu "afakulcs" értékei). */
    public const VAT_RATES = ['27', '18', '5', '0', 'AAM', 'TAM'];

    public const DEFAULT_EMAIL_SUBJECT = 'Számla a vásárlásról – Szabadság Mozi';
    public const DEFAULT_EMAIL_TEXT = "Tisztelt Vásárlónk!\n\nköszönjük a vásárlást, a számlát csatolva küldjük.\n\nÜdvözlettel,\nSzabadság Mozi";

    private ?array $config = null;

    // ------------------------------------------------------ beállítások

    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $get = fn(string $key) => Setting::find('szamlazz.' . $key)?->value;

        $key = null;
        if ($encrypted = $get('agent_key')) {
            try {
                $key = Crypt::decryptString($encrypted);
            } catch (\Throwable) {
                $key = null;
            }
        }

        $vat = (string) $get('vat_rate');

        return $this->config = [
            'enabled' => $get('enabled') === '1',
            'agent_key' => $key,
            'vat_rate' => in_array($vat, self::VAT_RATES, true) ? $vat : '5',
            'e_invoice' => ($get('e_invoice') ?? '1') === '1',
            'send_email' => ($get('send_email') ?? '1') === '1',
            'prefix' => trim((string) $get('prefix')),
            'email_subject' => (string) ($get('email_subject') ?: self::DEFAULT_EMAIL_SUBJECT),
            'email_text' => (string) ($get('email_text') ?: self::DEFAULT_EMAIL_TEXT),
        ];
    }

    public function save(array $data): void
    {
        $set = fn(string $key, string $value) => Setting::updateOrCreate(['key' => 'szamlazz.' . $key], ['value' => $value]);

        $set('enabled', $data['enabled'] ? '1' : '0');
        $set('vat_rate', $data['vat_rate']);
        $set('e_invoice', $data['e_invoice'] ? '1' : '0');
        $set('send_email', $data['send_email'] ? '1' : '0');
        $set('prefix', trim((string) $data['prefix']));
        $set('email_subject', trim((string) $data['email_subject']));
        $set('email_text', trim((string) $data['email_text']));

        // Üresen hagyott kulcs: a meglévő marad
        if (!empty($data['agent_key'])) {
            $set('agent_key', Crypt::encryptString($data['agent_key']));
        }

        $this->config = null;
    }

    /** Be van-e kapcsolva és meg van-e adva a kulcs (a pénztári számla gomb ezen múlik). */
    public function isEnabled(): bool
    {
        $c = $this->config();

        return $c['enabled'] && !empty($c['agent_key']);
    }

    // ------------------------------------------------------ kapcsolat-ellenőrzés

    /**
     * Előnézeti PDF kérése (elonezetpdf): a Számlázz.hu ellenőrzi a kulcsot és az XML-t, de számla nem készül.
     *
     * @return array{ok:bool,message:string}
     */
    public function testConnection(): array
    {
        $c = $this->config();
        if (empty($c['agent_key'])) {
            return ['ok' => false, 'message' => 'Még nincs megadva Számla Agent kulcs.'];
        }

        $buyer = ['name' => 'Teszt Vásárló', 'zip' => '1011', 'city' => 'Budapest', 'address' => 'Teszt utca 1.', 'tax_number' => null, 'email' => null];
        $lines = [['name' => 'Kapcsolat-ellenőrzés (előnézet, számla nem készül)', 'gross' => 1000]];

        try {
            $response = $this->send($this->buildXml($c, $buyer, $lines, 'cash', 'preview-test', true));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return $response['ok']
            ? ['ok' => true, 'message' => 'A kapcsolat rendben van, a Számla Agent kulcs érvényes (számla nem készült).']
            : ['ok' => false, 'message' => $response['message']];
    }

    // ------------------------------------------------------ számlakiállítás

    /** A foglaláshoz tartozó (nem sztornózott) jegyek és az ebből számolt összegek, a megerősítő ablakhoz és a számlához. */
    public function preview(Reservation $reservation): array
    {
        $lines = $this->lines($reservation);
        $rate = $this->config()['vat_rate'];

        return ['lines' => $lines] + $this->totals($lines, $rate) + ['vat_rate' => $rate];
    }

    /**
     * ÁFÁS számla kiállítása a kifizetett foglaláshoz. Ha már van számla, azt adja vissza (idempotens).
     *
     * @param array{name:string,zip:string,city:string,address:string,tax_number:?string,email:?string} $buyer
     * @throws \RuntimeException hibaüzenettel, ha a számla nem állítható ki
     */
    public function issue(Reservation $reservation, array $buyer, ?User $user): Invoice
    {
        $c = $this->config();
        if (!$this->isEnabled()) {
            throw new \RuntimeException('A Számlázz.hu integráció nincs bekapcsolva.');
        }
        if ($reservation->status !== 'PAID') {
            throw new \RuntimeException('Csak kifizetett foglaláshoz állítható ki számla.');
        }

        $lock = Cache::lock('szamlazz-invoice-' . $reservation->id, 60);
        if (!$lock->get()) {
            throw new \RuntimeException('A számla kiállítása már folyamatban van, kérlek várj egy pillanatot.');
        }

        try {
            if ($existing = Invoice::where('reservation_id', $reservation->id)->first()) {
                return $existing;
            }

            $lines = $this->lines($reservation);
            $method = $reservation->payment_provider === 'cash' ? 'cash' : 'card';
            $response = $this->send($this->buildXml($c, $buyer, $lines, $method, 'vigado-res-' . $reservation->id, false, $reservation->id));

            if (!$response['ok']) {
                throw new \RuntimeException($response['message']);
            }

            $number = $response['number'] ?: 'ismeretlen';
            $totals = $this->totals($lines, $c['vat_rate']);
            $pdfPath = null;
            if (!empty($response['pdf'])) {
                $pdfPath = 'invoices/' . $reservation->id . '-' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $number) . '.pdf';
                Storage::disk('local')->put($pdfPath, $response['pdf']);
            }

            return Invoice::create([
                'reservation_id' => $reservation->id,
                'invoice_number' => $number,
                'buyer_name' => $buyer['name'],
                'buyer_json' => json_encode($buyer, JSON_UNESCAPED_UNICODE),
                'payment_method' => $method,
                'vat_rate' => $c['vat_rate'],
                'net_amount' => $totals['net'],
                'vat_amount' => $totals['vat'],
                'gross_amount' => $totals['gross'],
                'pdf_path' => $pdfPath,
                'customer_url' => $response['customer_url'],
                'issued_by_user_id' => $user?->id,
                'issued_by_name' => $user?->name,
                'issued_at' => now(),
            ]);
        } finally {
            $lock->release();
        }
    }

    // ------------------------------------------------------ belső

    /** @return array<int,array{name:string,gross:int}> */
    private function lines(Reservation $reservation): array
    {
        $tickets = Ticket::where('reservation_id', $reservation->id)->orderBy('id')->get();
        if ($tickets->isEmpty()) {
            throw new \RuntimeException('A foglaláshoz nincs kiállított jegy, így számla sem készíthető.');
        }

        return $tickets->map(fn(Ticket $t) => [
            'name' => sprintf(
                'Mozijegy: %s, %s, %s',
                $t->movie_title,
                Carbon::parse($t->starts_at)->timezone('Europe/Budapest')->format('Y.m.d. H:i'),
                $t->seat_key
            ),
            'gross' => (int) round((float) $t->price_amount),
        ])->all();
    }

    private function isNumericRate(string $rate): bool
    {
        return ctype_digit($rate) && (int) $rate > 0;
    }

    /** Bruttó összegből tételenként kerekített nettó és ÁFA (a tételek összege adja a számla végösszegét). */
    private function split(int $gross, string $rate): array
    {
        if (!$this->isNumericRate($rate)) {
            return ['net' => $gross, 'vat' => 0];
        }
        $net = (int) round($gross / (1 + ((int) $rate) / 100));

        return ['net' => $net, 'vat' => $gross - $net];
    }

    private function totals(array $lines, string $rate): array
    {
        $net = $vat = $gross = 0;
        foreach ($lines as $line) {
            $s = $this->split($line['gross'], $rate);
            $net += $s['net'];
            $vat += $s['vat'];
            $gross += $line['gross'];
        }

        return ['net' => $net, 'vat' => $vat, 'gross' => $gross];
    }

    private function x(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function buildXml(array $c, array $buyer, array $lines, string $method, string $externalId, bool $preview, ?int $orderNo = null): string
    {
        $today = Carbon::now('Europe/Budapest')->format('Y-m-d');
        $email = trim((string) ($buyer['email'] ?? ''));
        $sendEmail = $c['send_email'] && $email !== '' && !$preview;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<xmlszamla xmlns="http://www.szamlazz.hu/xmlszamla">'
            . '<beallitasok>'
            . '<szamlaagentkulcs>' . $this->x($c['agent_key']) . '</szamlaagentkulcs>'
            . '<eszamla>' . ($c['e_invoice'] ? 'true' : 'false') . '</eszamla>'
            . '<szamlaLetoltes>true</szamlaLetoltes>'
            . '<valaszVerzio>2</valaszVerzio>'
            . ($preview ? '' : '<szamlaKulsoAzon>' . $this->x($externalId) . '</szamlaKulsoAzon>')
            . '</beallitasok>'
            . '<fejlec>'
            . '<keltDatum>' . $today . '</keltDatum>'
            . '<teljesitesDatum>' . $today . '</teljesitesDatum>'
            . '<fizetesiHataridoDatum>' . $today . '</fizetesiHataridoDatum>'
            . '<fizmod>' . ($method === 'cash' ? 'Készpénz' : 'Bankkártya') . '</fizmod>'
            . '<penznem>HUF</penznem>'
            . '<szamlaNyelve>hu</szamlaNyelve>'
            . ($orderNo ? '<rendelesSzam>' . $orderNo . '</rendelesSzam>' : '')
            . ($c['prefix'] !== '' ? '<szamlaszamElotag>' . $this->x($c['prefix']) . '</szamlaszamElotag>' : '')
            . '<fizetve>true</fizetve>'
            . ($preview ? '<elonezetpdf>true</elonezetpdf>' : '')
            . '</fejlec>'
            . '<elado>'
            . ($sendEmail ? '<emailTargy>' . $this->x($c['email_subject']) . '</emailTargy><emailSzoveg>' . $this->x($c['email_text']) . '</emailSzoveg>' : '')
            . '</elado>'
            . '<vevo>'
            . '<nev>' . $this->x($buyer['name']) . '</nev>'
            . '<orszag>Magyarország</orszag>'
            . '<irsz>' . $this->x($buyer['zip']) . '</irsz>'
            . '<telepules>' . $this->x($buyer['city']) . '</telepules>'
            . '<cim>' . $this->x($buyer['address']) . '</cim>'
            . ($email !== '' ? '<email>' . $this->x($email) . '</email>' : '')
            . '<sendEmail>' . ($sendEmail ? 'true' : 'false') . '</sendEmail>'
            . (!empty($buyer['tax_number']) ? '<adoszam>' . $this->x($buyer['tax_number']) . '</adoszam>' : '')
            . '</vevo>'
            . '<tetelek>';

        foreach ($lines as $line) {
            $s = $this->split($line['gross'], $c['vat_rate']);
            $xml .= '<tetel>'
                . '<megnevezes>' . $this->x($line['name']) . '</megnevezes>'
                . '<mennyiseg>1</mennyiseg>'
                . '<mennyisegiEgyseg>db</mennyisegiEgyseg>'
                . '<nettoEgysegar>' . $s['net'] . '</nettoEgysegar>'
                . '<afakulcs>' . $this->x($c['vat_rate']) . '</afakulcs>'
                . '<nettoErtek>' . $s['net'] . '</nettoErtek>'
                . '<afaErtek>' . $s['vat'] . '</afaErtek>'
                . '<bruttoErtek>' . $line['gross'] . '</bruttoErtek>'
                . '</tetel>';
        }

        return $xml . '</tetelek></xmlszamla>';
    }

    /** @return array{ok:bool,message:string,number:?string,pdf:?string,customer_url:?string} */
    private function send(string $xml): array
    {
        try {
            $response = Http::timeout(40)->attach('action-xmlagentxmlfile', $xml, 'szamla.xml')->post(self::ENDPOINT);
        } catch (\Throwable $e) {
            throw new \RuntimeException('A Számlázz.hu nem érhető el: ' . $e->getMessage());
        }

        $dom = new \DOMDocument();
        $body = ltrim($response->body(), "\xEF\xBB\xBF");
        if ($body === '' || !@$dom->loadXML($body)) {
            throw new \RuntimeException('A Számlázz.hu váratlan választ adott (HTTP ' . $response->status() . ').');
        }

        $node = function (string $name) use ($dom): ?string {
            $found = $dom->getElementsByTagNameNS('*', $name)->item(0) ?? $dom->getElementsByTagName($name)->item(0);

            return $found ? trim($found->textContent) : null;
        };

        $ok = strtolower((string) $node('sikeres')) === 'true';
        $pdf = $node('pdf');

        return [
            'ok' => $ok,
            'message' => $ok ? '' : trim(($node('hibauzenet') ?: 'Ismeretlen hiba a számla kiállításakor.') . ($node('hibakod') ? ' (hibakód: ' . $node('hibakod') . ')' : '')),
            'number' => $node('szamlaszam'),
            'pdf' => $pdf ? (base64_decode($pdf, true) ?: null) : null,
            'customer_url' => $node('vevoifiokurl'),
        ];
    }
}
