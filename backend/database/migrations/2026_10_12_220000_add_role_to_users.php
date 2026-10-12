<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Az új felhasználók alapértelmezett szerepköre a legkisebb jogú (pénztáros)
            $table->string('role', 20)->default('cashier')->after('password');
        });

        // A meglévő felhasználók eddig teljes hozzáféréssel rendelkeztek: adminisztrátorok maradnak
        DB::table('users')->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
