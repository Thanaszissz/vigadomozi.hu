<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A szék kulcsa a szint nevét is tartalmazza ("Emelet|1-05"), ezért hosszabb kulcs és sorjel kell.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['seat_locks', 'reservation_items', 'tickets'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('seat_key', 120)->change();
            });
        }

        foreach (['reservation_items', 'tickets'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('row_label', 40)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['seat_locks', 'reservation_items', 'tickets'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('seat_key', 32)->change();
            });
        }

        foreach (['reservation_items', 'tickets'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('row_label', 8)->change();
            });
        }
    }
};
