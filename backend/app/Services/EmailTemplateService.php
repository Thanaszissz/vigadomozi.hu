<?php

namespace App\Services;

use App\Models\Reservation;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Az e-mail sablonok alapértelmezései, a használható változók és a sablon kitöltése.
 * A sablonban csak {{valtozo}} helyettesítés van (kód nem futtatható), az értékek HTML-escape-eltek.
 */
class EmailTemplateService
{
    public const CINEMA = [
        'mozi_neve' => 'Vigadó Mozi',
        'mozi_cim' => '5310 Kisújszállás, Szabadság tér 7.',
        'mozi_telefon' => '+36-59-328-083',
        'mozi_email' => 'info@vigadomozi.hu',
    ];

    public const PLACEHOLDERS = [
        'nev' => 'A vásárló neve',
        'email' => 'A vásárló e-mail címe',
        'telefon' => 'A vásárló telefonszáma',
        'film' => 'A film címe',
        'idopont' => 'Az előadás időpontja (pl. 2026.10.15. 19:00)',
        'terem' => 'A terem neve',
        'szekek' => 'A székek felsorolása (pl. A-04, A-05)',
        'osszeg' => 'A végösszeg (pl. 4 000 Ft)',
        'foglalas_azonosito' => 'A foglalás azonosítója (pl. #123)',
        'fizetesi_mod' => 'A fizetés módja',
        'foglalas_tabla' => 'A foglalás adatai táblázatban (film, időpont, terem, székek, összeg)',
        'jegyek' => 'A megvásárolt jegyek, jegyszámmal (a sikeres fizetés levelében)',
        'mozi_neve' => 'A mozi neve',
        'mozi_cim' => 'A mozi címe',
        'mozi_telefon' => 'A mozi telefonszáma',
        'mozi_email' => 'A mozi e-mail címe',
    ];

    public const DEFAULTS = [
        'reservation_received' => [
            'name' => 'Foglalás visszaigazolása',
            'description' => 'A publikus felületen leadott (fizetés nélküli) foglalás után megy ki a megadott e-mail címre.',
            'subject' => 'Foglalás visszaigazolás – {{film}} ({{idopont}})',
            'body' => <<<'HTML'
<p>Kedves {{nev}}!</p>
<p>Köszönjük a foglalását! Az alábbi székeket tartjuk Önnek:</p>
{{foglalas_tabla}}
<p>A foglalás <b>fizetés nélkül</b> történt, a jegyeket az előadás előtt a mozi pénztárában tudja kifizetni és átvenni. Kérjük, érkezzen legalább 15 perccel az előadás kezdete előtt.</p>
<p>Foglalás azonosítója: <b>{{foglalas_azonosito}}</b></p>
<p>Kérdés esetén keressen minket: {{mozi_telefon}}, {{mozi_email}}</p>
<p>Üdvözlettel,<br>{{mozi_neve}}</p>
HTML,
        ],
        'tickets_paid' => [
            'name' => 'Jegyek – sikeres kártyás fizetés',
            'description' => 'Sikeres online bankkártyás fizetés után megy ki, a megvásárolt jegyekkel.',
            'subject' => 'Jegyei – {{film}} ({{idopont}})',
            'body' => <<<'HTML'
<p>Kedves {{nev}}!</p>
<p>Köszönjük a vásárlást! A bankkártyás fizetés sikeres volt, az alábbiakban találja a jegyeit. Az előadásra a jegyszám megmutatásával (kinyomtatva vagy telefonon) léphet be.</p>
{{jegyek}}
<p>Fizetett összeg: <b>{{osszeg}}</b> ({{fizetesi_mod}})</p>
<p>Jó szórakozást kívánunk!<br>{{mozi_neve}}</p>
HTML,
        ],
    ];

    /**
     * @return array<string, string|HtmlString>
     */
    public function variablesFor(Reservation $reservation, ?Collection $tickets = null): array
    {
        $reservation->loadMissing('items', 'showtime.movie', 'showtime.auditorium');
        $showtime = $reservation->showtime;

        $seats = $reservation->items
            ->sortBy(fn($i) => sprintf('%s-%05d', $i->row_label, (int) $i->seat_number))
            ->pluck('seat_key')
            ->implode(', ');

        $providers = ['stripe' => 'Bankkártya (online)', 'simplepay' => 'Bankkártya (online)', 'card' => 'Bankkártya', 'cash' => 'Készpénz'];

        $vars = [
            'nev' => $reservation->customer_name ?: 'Vásárló',
            'email' => (string) $reservation->customer_email,
            'telefon' => (string) $reservation->customer_phone,
            'film' => $showtime?->movie?->title ?? '',
            'idopont' => $this->formatTime($showtime?->starts_at),
            'terem' => $showtime?->auditorium?->name ?? '',
            'szekek' => $seats,
            'osszeg' => $this->money((int) $reservation->total_amount),
            'foglalas_azonosito' => '#' . $reservation->id,
            'fizetesi_mod' => $providers[$reservation->payment_provider] ?? 'Fizetés a pénztárban',
        ] + self::CINEMA;

        $vars['foglalas_tabla'] = $this->reservationTable($vars);
        $vars['jegyek'] = new HtmlString($tickets ? $this->ticketCards($tickets) : '');

        return $vars;
    }

    /** Minta adatok az előnézethez és a teszt e-mailhez. */
    public function sampleVariables(): array
    {
        $when = '2026.10.15. 19:00';
        $vars = [
            'nev' => 'Minta Anna',
            'email' => 'minta.anna@example.com',
            'telefon' => '+36 30 123 4567',
            'film' => 'Digger',
            'idopont' => $when,
            'terem' => 'A terem',
            'szekek' => 'E-05, E-06',
            'osszeg' => $this->money(4000),
            'foglalas_azonosito' => '#123',
            'fizetesi_mod' => 'Bankkártya (online)',
        ] + self::CINEMA;

        $vars['foglalas_tabla'] = $this->reservationTable($vars);

        $sample = collect([['E', 5], ['E', 6]])->map(fn($s, $i) => [
            'ticket_number' => sprintf('2026-%06d', 101 + $i),
            'movie_title' => 'Digger',
            'starts_at' => $when,
            'auditorium_name' => 'A terem',
            'row_label' => $s[0],
            'seat_number' => $s[1],
            'price_amount' => 2000,
        ]);
        $vars['jegyek'] = new HtmlString($this->ticketCards($sample));

        return $vars;
    }

    /**
     * @return array{0:string,1:string,2:string} [tárgy, teljes HTML, sima szöveges változat]
     */
    public function compose(string $subject, string $body, array $vars): array
    {
        $subjectText = trim(preg_replace('/\s+/', ' ', $this->fill($subject, $vars, false)));
        $bodyHtml = $this->fill($body, $vars, true);

        return [$subjectText, $this->wrap($bodyHtml, $subjectText), $this->toText($bodyHtml)];
    }

    /** A sablonban használt, de ismeretlen változók. */
    public function unknownPlaceholders(string ...$texts): array
    {
        $unknown = [];
        foreach ($texts as $text) {
            preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/', $text, $m);
            foreach ($m[1] as $name) {
                if (!array_key_exists(strtolower($name), self::PLACEHOLDERS)) {
                    $unknown[$name] = true;
                }
            }
        }

        return array_keys($unknown);
    }

    /** Hibás jelölés: nyitó vagy záró kapcsos zárójel, amely nem alkot teljes {{változó}} párt. */
    public function hasMalformedBraces(string ...$texts): bool
    {
        foreach ($texts as $text) {
            $rest = preg_replace('/\{\{\s*[^{}]*?\s*\}\}/', '', $text);
            if (str_contains($rest, '{{') || str_contains($rest, '}}')) {
                return true;
            }
        }

        return false;
    }

    private function fill(string $template, array $vars, bool $asHtml): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($vars, $asHtml) {
            $value = $vars[strtolower($m[1])] ?? '';

            if ($value instanceof HtmlString) {
                return $asHtml ? (string) $value : strip_tags((string) $value);
            }

            return $asHtml ? e((string) $value) : (string) $value;
        }, $template);
    }

    private function wrap(string $bodyHtml, string $title): string
    {
        $c = self::CINEMA;
        $title = e($title);

        return <<<HTML
<!doctype html>
<html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{$title}</title></head>
<body style="margin:0;padding:0;background:#f1ebe3;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1ebe3;"><tr><td align="center" style="padding:24px 12px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
    <tr><td style="background:#3d2817;padding:18px 24px;text-align:center;font-family:Arial,Helvetica,sans-serif;color:#e8d5b7;font-size:22px;font-weight:800;letter-spacing:4px;">{$this->e($c['mozi_neve'])}</td></tr>
    <tr><td style="padding:24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#222222;">{$bodyHtml}</td></tr>
    <tr><td style="background:#f7f2ea;padding:14px 24px;text-align:center;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#6b5a48;">{$this->e($c['mozi_neve'])} • {$this->e($c['mozi_cim'])}<br>{$this->e($c['mozi_telefon'])} • {$this->e($c['mozi_email'])}</td></tr>
  </table>
</td></tr></table>
</body></html>
HTML;
    }

    private function toText(string $html): string
    {
        $text = preg_replace('/<\s*(br|\/p|\/div|\/tr|\/h[1-6])\b[^>]*>/i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/[ \t]+\n|\n{3,}/", "\n\n", $text));
    }

    private function reservationTable(array $v): HtmlString
    {
        $rows = [
            'Film' => $v['film'],
            'Időpont' => $v['idopont'],
            'Terem' => $v['terem'],
            'Székek' => $v['szekek'],
            'Összeg' => $v['osszeg'],
        ];

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:12px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td style="padding:6px 16px 6px 0;color:#6b5a48;">' . e($label) . '</td><td style="padding:6px 0;font-weight:700;">' . e($value) . '</td></tr>';
        }

        return new HtmlString($html . '</table>');
    }

    /** @param Collection<int, mixed> $tickets Ticket modellek vagy tömbök */
    private function ticketCards(Collection $tickets): string
    {
        $cards = '';
        foreach ($tickets as $t) {
            $get = fn(string $k) => data_get($t, $k);
            $when = $get('starts_at');
            $when = is_string($when) ? $when : $this->formatTime($when);
            $title = (string) $get('movie_title');
            $seat = (string) ($get('seat_number') ?? '—');
            $hall = (string) ($get('auditorium_name') ?: '—');
            $row = (string) $get('row_label');
            $money = $this->money((int) $get('price_amount'));
            $len = mb_strlen($title);
            $titleSize = $len <= 20 ? 24 : ($len <= 30 ? 20 : ($len <= 44 ? 16 : 14));

            $serif = "font-family:Georgia,'Times New Roman',serif;color:#111111;";
            $fact = fn(string $label, string $value, int $w) => '<td width="' . $w . '" align="center" valign="top" style="width:' . $w . 'px;' . $serif . '"><div style="font-family:Arial,Helvetica,sans-serif;font-size:8px;letter-spacing:1px;text-transform:uppercase;color:#555555;line-height:11px;">' . e($label) . '</div><div style="font-size:16px;font-weight:700;line-height:20px;">' . e($value) . '</div></td>';

            // A 210 x 100 mm-es jegysablon (ticket-base.png) a háttér, a szöveg a sablon üres részeire kerül
            $main = '<table role="presentation" width="414" cellpadding="0" cellspacing="0" style="width:414px;">'
                . '<tr><td height="89" style="height:89px;line-height:89px;font-size:1px;">&nbsp;</td></tr>'
                . '<tr><td height="40" align="center" valign="middle" style="height:40px;font-size:' . $titleSize . 'px;font-weight:700;line-height:1.1;' . $serif . '">' . e($title) . '</td></tr>'
                . '<tr><td height="24" align="center" valign="middle" style="height:24px;font-size:15px;' . $serif . '">' . e((string) $when) . '</td></tr>'
                . '<tr><td><table role="presentation" cellpadding="0" cellspacing="0" style="margin-left:25px;"><tr>'
                . $fact('Terem', $hall, 80) . $fact('Sor', $row, 75)
                . '<td width="62" style="width:62px;font-size:1px;">&nbsp;</td>'
                . $fact('Szék', $seat, 75) . $fact('Ár', $money, 80)
                . '</tr></table></td></tr></table>';

            $stub = '<table role="presentation" width="134" cellpadding="0" cellspacing="0" style="width:134px;">'
                . '<tr><td height="122" style="height:122px;line-height:122px;font-size:1px;">&nbsp;</td></tr>'
                . '<tr><td align="center" style="' . $serif . '">'
                . '<div style="font-size:11px;font-weight:700;line-height:12px;max-height:24px;overflow:hidden;">' . e($title) . '</div>'
                . '<div style="font-size:10px;line-height:13px;margin-top:2px;">' . e((string) $when) . '</div>'
                . '<div style="font-size:10px;line-height:13px;">' . e($hall) . ' • <b>' . e($row) . '. sor ' . e($seat) . '. szék</b></div>'
                . '<div style="font-size:11px;font-weight:700;letter-spacing:.5px;font-family:\'Courier New\',monospace;margin-top:4px;">' . e((string) $get('ticket_number')) . '</div>'
                . '<div style="font-size:11px;font-weight:700;margin-top:1px;">' . e($money) . '</div>'
                . '</td></tr></table>';

            $image = $this->ticketBaseUrl();
            $cards .= '<table role="presentation" width="552" cellpadding="0" cellspacing="0" style="width:552px;max-width:100%;margin:0 0 14px;"><tr>'
                . '<td width="552" height="263" valign="top" background="' . e($image) . '" bgcolor="#ffffff" style="width:552px;height:263px;background:#ffffff url(\'' . e($image) . '\') no-repeat 0 0;background-size:552px 263px;">'
                . '<table role="presentation" width="552" cellpadding="0" cellspacing="0" style="width:552px;"><tr>'
                . '<td width="414" valign="top" style="width:414px;">' . $main . '</td>'
                . '<td width="138" valign="top" style="width:138px;padding-left:4px;">' . $stub . '</td>'
                . '</tr></table></td></tr></table>';
        }

        return $cards;
    }

    private function ticketBaseUrl(): string
    {
        return rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/') . '/assets/ticket-base.png';
    }

    private function formatTime($value): string
    {
        return $value ? $value->copy()->timezone('Europe/Budapest')->format('Y.m.d. H:i') : '';
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ') . ' Ft';
    }

    // A heredoc-ban hívható escape-segéd
    public function e(string $value): string
    {
        return e($value);
    }
}
