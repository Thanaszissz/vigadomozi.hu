<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sztornózott jegyek: a jegy sora megmarad (a jegyszám és a sorszám sosem adható ki újra, és a naplózás is ezen alapul),
     * de minden statisztikából, archívumból és NAV-összesítésből kimarad.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dateTime('voided_at')->nullable()->after('sold_at');
            $table->foreignId('voided_by_user_id')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('voided_by_name')->nullable()->after('voided_by_user_id');
            $table->string('void_reason', 255)->nullable()->after('voided_by_name');

            $table->index(['showtime_id', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['showtime_id', 'voided_at']);
            $table->dropConstrainedForeignId('voided_by_user_id');
            $table->dropColumn(['voided_at', 'voided_by_name', 'void_reason']);
        });
    }
};
