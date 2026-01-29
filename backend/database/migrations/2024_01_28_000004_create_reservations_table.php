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
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('showtime_id')->constrained('showtimes')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('customer_email');
            $table->enum('status', ['PENDING', 'PAID', 'CANCELLED', 'EXPIRED'])->default('PENDING');
            $table->integer('total_amount'); // HUF-ban
            $table->string('currency')->default('HUF');
            $table->string('payment_provider')->nullable();
            $table->string('payment_ref')->nullable(); // session id / intent id
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['showtime_id']);
            $table->index(['status']);
            $table->index(['expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
