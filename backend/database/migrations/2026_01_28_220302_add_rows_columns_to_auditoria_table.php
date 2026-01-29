<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('auditoria', function (Blueprint $table) {
            $table->integer('rows')->default(8)->after('name');
            $table->integer('columns')->default(10)->after('rows');
            $table->integer('total_seats')->default(80)->after('columns');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auditoria', function (Blueprint $table) {
            $table->dropColumn(['rows', 'columns', 'total_seats']);
        });
    }
};
