<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // A sorszámláló előtagonként külön számol: egy előtaggal soha nem adható ki ugyanaz a szám kétszer
        Schema::create('ticket_counters', function (Blueprint $table) {
            $table->string('prefix', 12)->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('ticket_prefix', 12)->default('')->after('ticket_year');
        });

        // A meglévő jegyek előtagja a jegyszámukból származik (pl. "2026-000012" -> "2026")
        DB::table('tickets')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('tickets')->where('id', $row->id)->update([
                    'ticket_prefix' => explode('-', $row->ticket_number)[0],
                ]);
            }
        });

        $now = now();
        if (Schema::hasTable('ticket_sequences')) {
            foreach (DB::table('ticket_sequences')->get() as $row) {
                DB::table('ticket_counters')->insertOrIgnore([
                    'prefix' => (string) $row->year,
                    'last_number' => $row->last_number,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Védelem: a számláló soha ne legyen kisebb a már kiadott legnagyobb sorszámnál
        $maxima = DB::table('tickets')->selectRaw('ticket_prefix, max(ticket_seq) as max_seq')->groupBy('ticket_prefix')->get();
        foreach ($maxima as $row) {
            $existing = DB::table('ticket_counters')->where('prefix', $row->ticket_prefix)->first();
            if (!$existing) {
                DB::table('ticket_counters')->insert([
                    'prefix' => $row->ticket_prefix, 'last_number' => $row->max_seq, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } elseif ($existing->last_number < $row->max_seq) {
                DB::table('ticket_counters')->where('prefix', $row->ticket_prefix)->update(['last_number' => $row->max_seq]);
            }
        }

        // Alapértelmezett előtag: a legutóbb kiadott jegy előtagja, különben az aktuális év
        $latest = DB::table('tickets')->orderByDesc('id')->value('ticket_prefix');
        DB::table('settings')->insert([
            'key' => 'ticket_prefix',
            'value' => $latest ?: (string) now('Europe/Budapest')->year,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('tickets', function (Blueprint $table) {
            $table->unique(['ticket_prefix', 'ticket_seq']);
            $table->dropUnique(['ticket_year', 'ticket_seq']);
        });

        Schema::dropIfExists('ticket_sequences');
    }

    public function down(): void
    {
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['ticket_prefix', 'ticket_seq']);
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->unique(['ticket_year', 'ticket_seq']);
            $table->dropColumn('ticket_prefix');
        });

        Schema::dropIfExists('ticket_counters');
        Schema::dropIfExists('settings');
    }
};
