<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A NAV-nak beküldött napi nyugtaadat-szolgáltatások naplója (minden beküldési kísérlet külön sor)
        Schema::create('nav_submissions', function (Blueprint $table) {
            $table->id();
            $table->date('applicable_date');
            $table->string('environment', 10);
            $table->string('operation', 10)->default('create');
            $table->string('trigger', 10)->default('manual');
            $table->string('status', 12)->default('PENDING');
            $table->string('nav_id', 50)->nullable();
            $table->unsignedBigInteger('replaces_id')->nullable();
            $table->string('serial_number', 50);
            $table->unsignedInteger('sale_count')->default(0);
            $table->bigInteger('total_amount')->default(0);
            $table->string('vat_category', 50)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('sent_by', 255)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->longText('request_xml')->nullable();
            $table->longText('response_xml')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['applicable_date', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_submissions');
    }
};
