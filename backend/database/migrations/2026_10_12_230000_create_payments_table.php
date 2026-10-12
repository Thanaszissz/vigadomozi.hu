<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Online fizetési kísérletek (minden indított fizetés külön rekord, egyedi rendelés-azonosítóval)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('order_ref', 64)->unique();
            $table->string('transaction_id', 64)->nullable();
            $table->string('status', 20)->default('INIT');
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('HUF');
            $table->string('environment', 10)->nullable();
            $table->string('last_event', 20)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();

            $table->index('reservation_id');
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
