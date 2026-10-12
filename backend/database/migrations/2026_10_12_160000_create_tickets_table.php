<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Évenkénti, folyamatos jegysorszám számláló
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        // Eladott jegyek. A jegy adatai pillanatképként is tárolva vannak, hogy az archívum
        // akkor is megmaradjon, ha a film vagy az előadás később törlődik.
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('ticket_year');
            $table->unsignedInteger('ticket_seq');
            $table->string('ticket_number', 20)->unique();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('showtime_id')->nullable()->constrained('showtimes')->nullOnDelete();
            $table->string('movie_title');
            $table->dateTime('starts_at');
            $table->string('auditorium_name')->nullable();
            $table->string('seat_key', 32);
            $table->string('row_label', 8);
            $table->integer('seat_number')->nullable();
            $table->integer('price_amount');
            $table->string('currency', 3)->default('HUF');
            $table->string('payment_method', 16);
            $table->string('channel', 16)->default('pos');
            $table->foreignId('sold_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sold_by_name')->nullable();
            $table->dateTime('sold_at');
            $table->timestamps();

            $table->unique(['ticket_year', 'ticket_seq']);
            $table->index(['ticket_year', 'sold_at']);
            $table->index(['showtime_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_sequences');
    }
};
