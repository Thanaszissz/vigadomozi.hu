<?php

namespace App\Console\Commands;

use App\Services\EmailTemplateService;
use App\Services\ReservationMailer;
use Illuminate\Console\Command;

/**
 * A levélküldés kézbesíthetőségi ellenőrzése: beállítások, SPF, DKIM, DMARC, és opcionálisan egy teszt levél.
 */
class MailCheck extends Command
{
    protected $signature = 'mail:check {--send= : Teszt levél küldése erre a címre}';

    protected $description = 'Levélküldés ellenőrzése (SPF, DKIM, DMARC, feladó beállítások)';

    private int $problems = 0;

    public function handle(): int
    {
        $from = (string) config('mail.from.address');
        $domain = strtolower((string) substr(strrchr($from, '@') ?: '@', 1));

        $this->line('Küldő: ' . config('mail.default') . ' | Feladó: ' . $from . ' | Válaszcím: ' . config('mail.reply_to.address'));

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->bad('A levélküldés nincs bekapcsolva (MAIL_MAILER=' . config('mail.default') . '), a levelek nem mennek ki.');
        }
        if ($domain === '' || in_array($domain, ['example.com', 'localhost'], true)) {
            $this->bad('A MAIL_FROM_ADDRESS érvénytelen vagy alapértelmezett (' . $from . '). Valós, a saját domainhez tartozó cím kell.');

            return self::FAILURE;
        }
        if (str_starts_with(strtolower($from), 'noreply@') || str_starts_with(strtolower($from), 'no-reply@')) {
            $this->warn('  ! A no-reply feladó rontja a megítélést; ajánlott valós postafiók (pl. info@' . $domain . ') és ugyanez válaszcímként.');
        }

        $this->checkSpf($domain);
        $dkimOk = $this->checkDkim($domain);
        $this->checkDmarc($domain);

        if ($to = $this->option('send')) {
            $template = EmailTemplateService::DEFAULTS['tickets_paid'];
            try {
                app(ReservationMailer::class)->sendTest($template['subject'], $template['body'], (string) $to);
                $this->info("Teszt levél elküldve: {$to}. Gmailben a ⋮ → \"Eredeti üzenet megjelenítése\" mutatja az SPF/DKIM/DMARC eredményt.");
            } catch (\Throwable $e) {
                $this->bad('A teszt levél küldése sikertelen: ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->{$this->problems ? 'warn' : 'info'}($this->problems ? "{$this->problems} javítandó dolog van, lásd fent." : 'Minden ellenőrzött beállítás rendben.');

        return $this->problems ? self::FAILURE : self::SUCCESS;
    }

    private function checkSpf(string $domain): void
    {
        $spf = collect($this->txt($domain))->first(fn($r) => str_starts_with(strtolower($r), 'v=spf1'));
        if (!$spf) {
            $this->bad("SPF: nincs SPF rekord a(z) {$domain} domainen. Adj hozzá egy TXT rekordot: v=spf1 +a +mx ~all (és a küldő szerver IP-jét: ip4:...).");

            return;
        }
        $this->ok("SPF: {$spf}");
        if (!str_contains($spf, 'all')) {
            $this->bad('SPF: hiányzik a záró "all" mechanizmus.');
        }
    }

    private function checkDkim(string $domain): bool
    {
        $cfg = config('mail.dkim');
        $selector = (string) ($cfg['selector'] ?: 'vigado');
        $keyEnv = (string) ($cfg['private_key'] ?? '');

        if ((string) ($cfg['domain'] ?? '') === '' || $keyEnv === '') {
            $this->warn('DKIM: az alkalmazás nem ír alá (MAIL_DKIM_* nincs beállítva). Csak akkor jó, ha a levelezőszerver (Plesk) aláírja a kimenő leveleket. Biztosabb: php artisan mail:dkim-generate');

            return false;
        }

        $pem = str_contains($keyEnv, 'BEGIN') ? $keyEnv : (string) base64_decode($keyEnv, true);
        $private = @openssl_pkey_get_private($pem);
        if (!$private) {
            $this->bad('DKIM: a MAIL_DKIM_PRIVATE_KEY érvénytelen (base64-kódolt PEM kell).');

            return false;
        }

        $publicB64 = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', openssl_pkey_get_details($private)['key']);
        $record = collect($this->txt("{$selector}._domainkey.{$cfg['domain']}"))->first(fn($r) => str_contains($r, 'v=DKIM1'));

        if (!$record) {
            $this->bad("DKIM: nincs {$selector}._domainkey.{$cfg['domain']} TXT rekord a DNS-ben (vagy még nem terjedt el).");

            return false;
        }

        $dnsKey = preg_replace('/\s+/', '', (string) (preg_match('/p=([A-Za-z0-9+\/=]+)/', str_replace(['" "', '"'], '', $record), $m) ? $m[1] : ''));
        if ($dnsKey !== $publicB64) {
            $this->bad("DKIM: a DNS-ben lévő publikus kulcs NEM egyezik a MAIL_DKIM_PRIVATE_KEY kulccsal ({$selector}).");

            return false;
        }

        if (strtolower((string) $cfg['domain']) !== strtolower($domain)) {
            $this->bad("DKIM: a MAIL_DKIM_DOMAIN ({$cfg['domain']}) nem egyezik a feladó domainjével ({$domain}), így a DMARC nem teljesül.");

            return false;
        }

        $this->ok("DKIM: rendben ({$selector}._domainkey.{$cfg['domain']}, a kulcs egyezik).");

        return true;
    }

    private function checkDmarc(string $domain): void
    {
        $dmarc = collect($this->txt("_dmarc.{$domain}"))->first(fn($r) => str_starts_with(strtolower($r), 'v=dmarc1'));
        if (!$dmarc) {
            $this->bad("DMARC: nincs _dmarc.{$domain} rekord. Adj hozzá egy TXT rekordot: v=DMARC1; p=none; rua=mailto:info@{$domain}; adkim=r; aspf=r");

            return;
        }
        $this->ok("DMARC: {$dmarc}");
    }

    /** @return array<int,string> */
    private function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT) ?: [];

        return array_map(fn($r) => (string) ($r['txt'] ?? implode('', $r['entries'] ?? [])), $records);
    }

    private function ok(string $message): void
    {
        $this->line('  <info>✔</info> ' . $message);
    }

    private function bad(string $message): void
    {
        $this->problems++;
        $this->line('  <error>✖</error> ' . $message);
    }
}
