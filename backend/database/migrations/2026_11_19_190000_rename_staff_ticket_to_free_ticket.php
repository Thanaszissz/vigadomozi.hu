<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('extra_services')
            ->where('kind', 'staff_ticket')
            ->where('name', 'Dolgozó jegy')
            ->update(['name' => 'Ingyenes jegy']);
    }

    public function down(): void
    {
        DB::table('extra_services')
            ->where('kind', 'staff_ticket')
            ->where('name', 'Ingyenes jegy')
            ->update(['name' => 'Dolgozó jegy']);
    }
};
