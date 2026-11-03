<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Külső jegyértékesítő: az előadásra nem nálunk lehet jegyet venni, hanem a megadott oldalon
        Schema::table('showtimes', function (Blueprint $table) {
            $table->boolean('is_external')->default(false);
            $table->string('external_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('showtimes', function (Blueprint $table) {
            $table->dropColumn(['is_external', 'external_url']);
        });
    }
};
