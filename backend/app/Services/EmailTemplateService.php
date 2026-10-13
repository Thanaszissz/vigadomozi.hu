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
<h2>Foglalását rögzítettük</h2>
<p>Kedves {{nev}}!</p>
<p>Köszönjük a foglalását! Az alábbi székeket tartjuk Önnek:</p>
{{foglalas_tabla}}
<div style="background:#fbf3e4;border-left:4px solid #d4a574;border-radius:4px;padding:14px 18px;margin:0 0 20px;font-size:15px;line-height:1.55;color:#4a3a2a;"><b>A foglalás fizetés nélkül történt.</b> A jegyeket az előadás előtt a mozi pénztárában tudja kifizetni és átvenni. Kérjük, érkezzen legalább 15 perccel az előadás kezdete előtt.</div>
<p>Foglalás azonosítója: <b>{{foglalas_azonosito}}</b></p>
<p>Kérdés esetén keressen minket: {{mozi_telefon}}, {{mozi_email}}</p>
<p style="margin:24px 0 0;font-size:16px;line-height:1.6;color:#2b2018;">Üdvözlettel,<br><b>{{mozi_neve}}</b></p>
HTML,
        ],
        'tickets_paid' => [
            'name' => 'Jegyek – sikeres kártyás fizetés',
            'description' => 'Sikeres online bankkártyás fizetés után megy ki, a megvásárolt jegyekkel.',
            'subject' => 'Jegyei – {{film}} ({{idopont}})',
            'body' => <<<'HTML'
<h2>Sikeres fizetés – itt vannak a jegyei</h2>
<p>Kedves {{nev}}!</p>
<p>Köszönjük a vásárlást! A bankkártyás fizetés sikeres volt. A jegyeit PDF-ben csatoltuk a levélhez: nyomtassa ki, vagy mutassa meg telefonon az előadás előtt.</p>
{{foglalas_tabla}}
<h3>Jegyei</h3>
{{jegyek}}
<p>Fizetett összeg: <b>{{osszeg}}</b> ({{fizetesi_mod}})</p>
<div style="background:#fbf3e4;border-left:4px solid #d4a574;border-radius:4px;padding:14px 18px;margin:0 0 20px;font-size:15px;line-height:1.55;color:#4a3a2a;">Kérjük, érkezzen legalább <b>15 perccel</b> az előadás kezdete előtt, és a bejáratnál mutassa meg a jegyet (kinyomtatva vagy telefonon).</div>
<p style="margin:24px 0 0;font-size:16px;line-height:1.6;color:#2b2018;">Jó szórakozást kívánunk!<br><b>{{mozi_neve}}</b></p>
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
        $bodyHtml = $this->styleBody($this->fill($body, $vars, true));

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
        $site = rtrim((string) config('app.frontend_url'), '/');
        $logo = e($site . '/assets/email-logo.png');
        $siteLabel = e(preg_replace('#^https?://#', '', $site));
        $name = e($c['mozi_neve']);
        $address = e($c['mozi_cim']);
        $phone = e($c['mozi_telefon']);
        $phoneLink = e(preg_replace('/[^0-9+]/', '', $c['mozi_telefon']));
        $mail = e($c['mozi_email']);
        $year = date('Y');

        return <<<HTML
<!doctype html>
<html lang="hu" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light only">
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#efe7dc;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#efe7dc;font-size:1px;line-height:1px;">{$title}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#efe7dc" style="background:#efe7dc;">
<tr><td align="center" style="padding:28px 12px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-radius:12px;overflow:hidden;background:#ffffff;box-shadow:0 6px 24px rgba(44,24,16,0.18);">
    <tr><td align="center" bgcolor="#2c1810" style="background:#2c1810;padding:34px 24px 26px;">
      <a href="{$site}/" style="text-decoration:none;"><img src="{$logo}" width="150" alt="{$name} – Kisújszállás" style="display:block;margin:0 auto;width:150px;max-width:150px;height:auto;border:0;font-family:Georgia,'Times New Roman',serif;font-size:22px;color:#e8d5b7;text-align:center;"></a>
      <div style="margin-top:14px;font-family:Arial,Helvetica,sans-serif;font-size:11px;letter-spacing:4px;text-transform:uppercase;color:#d4a574;">Film &amp; Színház</div>
    </td></tr>
    <tr><td height="4" bgcolor="#d4a574" style="background:#d4a574;font-size:0;line-height:0;height:4px;">&nbsp;</td></tr>
    <tr><td bgcolor="#ffffff" style="background:#ffffff;padding:36px 38px 30px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.6;color:#2b2018;">{$bodyHtml}</td></tr>
    <tr><td bgcolor="#3d2817" align="center" style="background:#3d2817;padding:26px 24px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.7;color:#cdb596;">
      <div style="font-family:Georgia,'Times New Roman',serif;font-size:17px;color:#e8d5b7;letter-spacing:1px;margin-bottom:6px;">{$name}</div>
      <div>{$address}</div>
      <div><a href="tel:{$phoneLink}" style="color:#d4a574;text-decoration:none;">{$phone}</a> &nbsp;•&nbsp; <a href="mailto:{$mail}" style="color:#d4a574;text-decoration:none;">{$mail}</a></div>
      <div style="margin-top:10px;"><a href="{$site}/" style="color:#e8d5b7;text-decoration:underline;">{$siteLabel}</a></div>
      <div style="margin-top:14px;font-size:11px;color:#a08a70;">&copy; {$year} {$name}, Kisújszállás</div>
    </td></tr>
  </table>
</td></tr></table>
</body></html>
HTML;
    }

    /** A sablon sima HTML-elemeire (p, h2, a, ul…) beágyazott stílust tesz, hogy a levelezők is egységesen jelenítsék meg. */
    private function styleBody(string $html): string
    {
        $styles = [
            'h1' => "margin:0 0 18px;font-family:Georgia,'Times New Roman',serif;font-size:26px;line-height:1.25;font-weight:700;color:#3d2817;",
            'h2' => "margin:0 0 18px;font-family:Georgia,'Times New Roman',serif;font-size:24px;line-height:1.25;font-weight:700;color:#3d2817;",
            'h3' => "margin:0 0 12px;font-family:Georgia,'Times New Roman',serif;font-size:18px;line-height:1.3;font-weight:700;color:#3d2817;",
            'p' => 'margin:0 0 16px;font-size:16px;line-height:1.6;color:#2b2018;',
            'ul' => 'margin:0 0 16px;padding-left:22px;font-size:16px;line-height:1.6;color:#2b2018;',
            'ol' => 'margin:0 0 16px;padding-left:22px;font-size:16px;line-height:1.6;color:#2b2018;',
            'li' => 'margin:0 0 6px;',
            'a' => 'color:#9a5b1f;text-decoration:underline;',
            'hr' => 'border:0;border-top:1px solid #eadfce;margin:26px 0;',
        ];

        return preg_replace_callback('/<(h1|h2|h3|p|ul|ol|li|a|hr)(\s[^>]*)?(\/?)>/i', function ($m) use ($styles) {
            $attrs = $m[2] ?? '';
            if (stripos($attrs, 'style=') !== false) {
                return $m[0];
            }

            return '<' . $m[1] . $attrs . ' style="' . $styles[strtolower($m[1])] . '"' . $m[3] . '>';
        }, $html);
    }

    private function toText(string $html): string
    {
        $text = preg_replace('/<\/t[dh]>/i', ' ', $html);
        $text = preg_replace('/<\s*(br|\/p|\/div|\/tr|\/h[1-6])\b[^>]*>/i', "\n", $text);
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

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:6px 0 22px;border:1px solid #eadfce;border-left:5px solid #d4a574;border-radius:8px;background:#fbf7f0;border-collapse:separate;font-family:Arial,Helvetica,sans-serif;">';
        $last = array_key_last($rows);
        foreach ($rows as $label => $value) {
            $border = $label === $last ? '' : 'border-bottom:1px solid #eadfce;';
            $big = $label === 'Film' ? 'font-size:18px;font-family:Georgia,\'Times New Roman\',serif;color:#3d2817;' : 'font-size:15px;color:#2b2018;';
            $html .= '<tr>'
                . '<td width="110" valign="middle" style="' . $border . 'padding:11px 8px 11px 18px;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#8a7258;">' . e($label) . '</td>'
                . '<td valign="middle" style="' . $border . 'padding:10px 18px 10px 0;font-weight:700;' . $big . '">' . e($value) . '</td>'
                . '</tr>';
        }

        return new HtmlString($html . '</table>');
    }

    /**
     * A jegyek felsorolása a levél szövegében kártyákon (maguk a jegyek a csatolt PDF-ben vannak).
     *
     * @param Collection<int, mixed> $tickets Ticket modellek vagy tömbök
     */
    private function ticketCards(Collection $tickets): string
    {
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:6px 0 20px;border-collapse:separate;font-family:Arial,Helvetica,sans-serif;">';
        foreach ($tickets as $t) {
            $get = fn(string $k) => data_get($t, $k);
            $seat = (string) ($get('seat_number') ?? '—');
            $price = $get('price_amount') !== null ? $this->money((int) $get('price_amount')) : '';

            $html .= '<tr><td style="padding:0 0 10px;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border:1px solid #eadfce;border-left:5px solid #3d2817;border-radius:8px;background:#fbf7f0;border-collapse:separate;"><tr>'
                . '<td valign="middle" style="padding:13px 8px 13px 18px;">'
                . '<div style="font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#8a7258;">Jegyszám</div>'
                . '<div style="font-family:\'Courier New\',monospace;font-size:18px;font-weight:700;color:#2c1810;letter-spacing:0.5px;">' . e((string) $get('ticket_number')) . '</div>'
                . '</td>'
                . '<td valign="middle" align="right" style="padding:13px 18px 13px 8px;">'
                . '<div style="font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#8a7258;">Hely</div>'
                . '<div style="font-size:15px;font-weight:700;color:#2b2018;">' . e((string) $get('row_label')) . '. sor · ' . e($seat) . '. szék</div>'
                . ($price !== '' ? '<div style="font-size:12px;color:#8a7258;">' . e($price) . '</div>' : '')
                . '</td>'
                . '</tr></table></td></tr>';
        }

        return $html . '</table>';
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
