<?php

use App\Services\EmailTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Az új, szebb e-mail dizájn alapértelmezett szövegeinek bevezetése. Csak azokat a sablonokat cseréli,
     * amelyeket még senki nem szerkesztett (a tárolt szöveg megegyezik egy korábbi alapértelmezettel);
     * a kézzel módosított sablonok változatlanok maradnak (azok a levélkeretet és a kártyákat így is megkapják).
     */
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('email_templates')) {
            return;
        }

        $oldDefaults = [
            'reservation_received' => [
                "<p>Kedves {{nev}}!</p>\n<p>Köszönjük a foglalását! Az alábbi székeket tartjuk Önnek:</p>\n{{foglalas_tabla}}\n<p>A foglalás <b>fizetés nélkül</b> történt, a jegyeket az előadás előtt a mozi pénztárában tudja kifizetni és átvenni. Kérjük, érkezzen legalább 15 perccel az előadás kezdete előtt.</p>\n<p>Foglalás azonosítója: <b>{{foglalas_azonosito}}</b></p>\n<p>Kérdés esetén keressen minket: {{mozi_telefon}}, {{mozi_email}}</p>\n<p>Üdvözlettel,<br>{{mozi_neve}}</p>",
            ],
            'tickets_paid' => [
                "<p>Kedves {{nev}}!</p>\n<p>Köszönjük a vásárlást! A bankkártyás fizetés sikeres volt, az alábbiakban találja a jegyeit. Az előadásra a jegyszám megmutatásával (kinyomtatva vagy telefonon) léphet be.</p>\n{{jegyek}}\n<p>Fizetett összeg: <b>{{osszeg}}</b> ({{fizetesi_mod}})</p>\n<p>Jó szórakozást kívánunk!<br>{{mozi_neve}}</p>",
                "<p>Kedves {{nev}}!</p>\n<p>Köszönjük a vásárlást! A bankkártyás fizetés sikeres volt. A jegyeit PDF-ben csatoltuk a levélhez: nyomtassa ki, vagy mutassa meg telefonon az előadás előtt.</p>\n<p>A jegyek:</p>\n{{jegyek}}\n<p>Fizetett összeg: <b>{{osszeg}}</b> ({{fizetesi_mod}})</p>\n<p>Jó szórakozást kívánunk!<br>{{mozi_neve}}</p>",
            ],
        ];

        $normalize = fn(string $s) => preg_replace('/\s+/', ' ', trim(str_replace("\r", '', $s)));

        foreach ($oldDefaults as $key => $bodies) {
            $row = DB::table('email_templates')->where('key', $key)->first();
            if (!$row) {
                continue;
            }

            $isOldDefault = in_array($normalize((string) $row->body), array_map($normalize, $bodies), true);
            if ($isOldDefault) {
                DB::table('email_templates')->where('key', $key)->update([
                    'body' => EmailTemplateService::DEFAULTS[$key]['body'],
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Szándékosan üres: a sablonszövegek visszaállítása az admin felületen lehetséges.
    }
};
