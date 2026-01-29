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
        Schema::create('showtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained('movies')->cascadeOnDelete();
            $table->foreignId('auditorium_id')->constrained('auditoria')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('sales_open_at')->nullable();
            $table->dateTime('sales_close_at')->nullable();
            $table->json('pricing_override_json')->nullable();
            $table->enum('status', ['active', 'cancelled', 'completed'])->default('active');
            $table->timestamps();

            $table->index(['movie_id']);
            $table->index(['auditorium_id']);
            $table->index(['starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('showtimes');
    }
};
