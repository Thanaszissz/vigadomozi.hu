<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Az előadásoknál / programoknál nincs korhatár-besorolás: a korábban felvett értékeket töröljük (az oszlop megmarad). */
    public function up(): void
    {
        DB::table('theater_shows')->update(['rating' => null]);
    }

    public function down(): void
    {
        // a törölt korhatár-értékek nem állíthatók vissza
    }
};
