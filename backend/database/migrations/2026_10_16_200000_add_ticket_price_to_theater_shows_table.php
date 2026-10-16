<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theater_shows', function (Blueprint $table) {
            $table->unsignedInteger('ticket_price')->nullable()->after('show_info');
        });
    }

    public function down(): void
    {
        Schema::table('theater_shows', function (Blueprint $table) {
            $table->dropColumn('ticket_price');
        });
    }
};
