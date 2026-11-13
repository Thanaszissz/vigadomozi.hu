<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A purchase mérési esemény egyszeri elküldésének jelzője (az oldal frissítésekor ne számolódjon kétszer)
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('analytics_sent_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('analytics_sent_at');
        });
    }
};
