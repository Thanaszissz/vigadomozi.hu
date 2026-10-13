<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * DKIM-kulcspár létrehozása az alkalmazásból történő levélaláíráshoz.
 * A privát kulcs (base64) a MAIL_DKIM_PRIVATE_KEY változóba, a publikus kulcs a DNS-be kerül.
 */
class MailDkimGenerate extends Command
{
    protected $signature = 'mail:dkim-generate {--selector=vigado : A DKIM kijelölő (selector)} {--domain=vigadomozi.hu : A feladó domain} {--bits=2048}';

    protected $description = 'DKIM-kulcspár generálása (privát kulcs az .env-be, publikus kulcs a DNS-be)';

    public function handle(): int
    {
        $selector = (string) $this->option('selector');
        $domain = (string) $this->option('domain');

        $key = $this->generate();
        if (!$key || !openssl_pkey_export($key, $privatePem, null, $this->opensslOptions())) {
            $this->error('A kulcsgenerálás nem sikerült (az OpenSSL PHP-bővítmény szükséges; Windowson állítsd be az OPENSSL_CONF-ot): ' . openssl_error_string());

            return self::FAILURE;
        }

        $public = openssl_pkey_get_details($key)['key'];
        $publicB64 = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $public);

        $this->newLine();
        $this->info('1) A szerver .env fájljába (Plesk → Laravel Toolkit → Environment variables):');
        $this->line("MAIL_DKIM_DOMAIN={$domain}");
        $this->line("MAIL_DKIM_SELECTOR={$selector}");
        $this->line('MAIL_DKIM_PRIVATE_KEY=' . base64_encode($privatePem));
        $this->newLine();
        $this->info('2) A DNS-be (a domain DNS-kezelőjében) egy TXT rekord:');
        $this->line("Név:   {$selector}._domainkey.{$domain}");
        $this->line('Érték: v=DKIM1; k=rsa; p=' . $publicB64);
        $this->newLine();
        $this->warn('A privát kulcsot ne oszd meg és ne commitold. A DNS-rekord életbe lépése után (akár órák) a "php artisan mail:check" ellenőrzi.');

        return self::SUCCESS;
    }

    private function generate()
    {
        return openssl_pkey_new($this->opensslOptions() + ['private_key_bits' => (int) $this->option('bits'), 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    /** Windowson (XAMPP) az OpenSSL konfigurációs fájlját külön meg kell adni, ha az OPENSSL_CONF nincs beállítva. */
    private function opensslOptions(): array
    {
        if (getenv('OPENSSL_CONF')) {
            return [];
        }

        foreach ([dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', dirname(PHP_BINARY) . '/extras/openssl/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf'] as $path) {
            if (is_file($path)) {
                return ['config' => $path];
            }
        }

        return [];
    }
}
