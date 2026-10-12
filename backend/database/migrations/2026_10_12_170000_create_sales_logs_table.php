<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Minden jegyeladás naplója: ki, mikor, mit és hogyan adott el
        Schema::create('sales_logs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('channel', 16);
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('showtime_id')->nullable()->constrained('showtimes')->nullOnDelete();
            $table->string('movie_title')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->unsignedInteger('ticket_count');
            $table->integer('total_amount');
            $table->string('payment_method', 16);
            $table->text('ticket_numbers');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('occurred_at');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_logs');
    }
};
