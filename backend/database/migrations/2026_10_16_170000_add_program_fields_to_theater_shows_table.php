<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theater_shows', function (Blueprint $table) {
            $table->string('category')->nullable()->after('title');
            $table->string('performer')->nullable()->after('category');
            $table->string('venue')->nullable()->after('performer');
            $table->string('show_info')->nullable()->after('venue');
            $table->json('badges')->nullable()->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('theater_shows', function (Blueprint $table) {
            $table->dropColumn(['category', 'performer', 'venue', 'show_info', 'badges']);
        });
    }
};
