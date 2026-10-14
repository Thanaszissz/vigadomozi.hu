<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jegykategóriák: normál (a terem ára), dolgozó jegy, élménykártya (székhez kötött, 0 Ft-os kategóriák) és
     * egyéb szolgáltatás (székhez nem kötött nyugta). A szolgáltatások között a Dolgozó jegy székhez kötött tétel.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('category', 20)->default('standard')->after('channel');
            $table->index(['ticket_year', 'category']);
        });

        Schema::table('reservation_items', function (Blueprint $table) {
            $table->string('category', 20)->default('standard')->after('price_amount');
        });

        Schema::table('extra_services', function (Blueprint $table) {
            $table->string('kind', 20)->default('service')->after('price');
        });

        DB::table('extra_services')->where('name', 'Dolgozó jegy')->update(['kind' => 'staff_ticket']);
    }

    public function down(): void
    {
        Schema::table('extra_services', fn(Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('reservation_items', fn(Blueprint $table) => $table->dropColumn('category'));
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['ticket_year', 'category']);
            $table->dropColumn('category');
        });
    }
};
