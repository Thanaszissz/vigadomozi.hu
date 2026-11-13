<?php

use App\Services\EmailTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A sikeres fizetés e-mail új szövegezése („Megérkeztek a jegyek”). Csak a még nem szerkesztett
     * sablont cseréli; a kézzel módosítottakhoz nem nyúl.
     */
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('email_templates')) {
            return;
        }

        $row = DB::table('email_templates')->where('key', 'tickets_paid')->first();
        if (!$row) {
            return;
        }

        $normalize = fn(string $s) => preg_replace('/\s+/', ' ', trim(str_replace("\r", '', $s)));

        $oldBody = str_replace(
            ['Sikeres fizetés – Megérkeztek a jegyek', 'A jegyeket PDF-ben', '<h3>Jegyek</h3>'],
            ['Sikeres fizetés – itt vannak a jegyei', 'A jegyeit PDF-ben', '<h3>Jegyei</h3>'],
            EmailTemplateService::DEFAULTS['tickets_paid']['body']
        );

        $update = [];
        if ($normalize((string) $row->body) === $normalize($oldBody)) {
            $update['body'] = EmailTemplateService::DEFAULTS['tickets_paid']['body'];
        }
        if (trim((string) $row->subject) === 'Jegyei – {{film}} ({{idopont}})') {
            $update['subject'] = EmailTemplateService::DEFAULTS['tickets_paid']['subject'];
        }

        if ($update) {
            $update['updated_at'] = now();
            DB::table('email_templates')->where('key', 'tickets_paid')->update($update);
        }
    }

    public function down(): void
    {
        // Szándékosan üres: a sablonszövegek az admin felületen szerkeszthetők.
    }
};
