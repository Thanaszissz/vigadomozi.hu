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
        Schema::create('seat_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('showtime_id')->constrained('showtimes')->cascadeOnDelete();
            $table->string('seat_key', 32);
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['showtime_id', 'seat_key']);
            $table->index(['showtime_id', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seat_locks');
    }
};
