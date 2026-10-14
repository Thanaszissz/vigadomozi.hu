<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Számlázz.hu-n kiállított számlák (foglalásonként legfeljebb egy)
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id')->unique();
            $table->string('provider', 20)->default('szamlazz');
            $table->string('invoice_number', 60);
            $table->string('buyer_name');
            $table->text('buyer_json');
            $table->string('payment_method', 20)->nullable();
            $table->string('vat_rate', 10);
            $table->bigInteger('net_amount')->default(0);
            $table->bigInteger('vat_amount')->default(0);
            $table->bigInteger('gross_amount')->default(0);
            $table->string('pdf_path')->nullable();
            $table->string('customer_url', 500)->nullable();
            $table->unsignedBigInteger('issued_by_user_id')->nullable();
            $table->string('issued_by_name')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
