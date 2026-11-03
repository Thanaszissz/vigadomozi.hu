<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Részfizetés (több fizetési mód): a vegyes jegyek módonkénti összegei
        Schema::table('tickets', function (Blueprint $table) {
            $table->json('payment_split')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('payment_split');
        });
    }
};
