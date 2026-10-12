<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Csak hozzáfűzhető tevékenységnapló: a felület nem töröl és nem módosít sorokat
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // A név és az e-mail pillanatképként is tárolva van, így törölt felhasználónál is olvasható marad
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('category', 32);
            $table->string('action', 48);
            $table->string('description', 500);
            $table->string('subject_type', 48)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index('occurred_at');
            $table->index(['user_id', 'occurred_at']);
            $table->index(['category', 'occurred_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
