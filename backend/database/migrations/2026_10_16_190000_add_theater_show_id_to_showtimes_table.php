<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Az előadások (színház, koncert stb.) időpontjai is a showtimes táblába kerülnek: film VAGY előadás tartozik hozzájuk. */
    public function up(): void
    {
        Schema::table('showtimes', function (Blueprint $table) {
            $table->unsignedBigInteger('movie_id')->nullable()->change();
            $table->foreignId('theater_show_id')->nullable()->after('movie_id')->constrained('theater_shows')->cascadeOnDelete();
            $table->index('theater_show_id');
        });
    }

    public function down(): void
    {
        Schema::table('showtimes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('theater_show_id');
        });
    }
};
