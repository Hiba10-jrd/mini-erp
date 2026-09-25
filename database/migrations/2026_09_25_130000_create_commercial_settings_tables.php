<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('currency_code', 3);
            $table->string('currency_name');
            $table->boolean('singleton')->default(true)->unique();
            $table->timestamps();
        });

        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->decimal('rate', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_terms', function (Blueprint $table): void {
            $table->id();
            $table->string('label')->unique();
            $table->unsignedInteger('due_days')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 40);
            $table->string('prefix', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('counter')->default(0);
            $table->string('number_format', 100);
            $table->timestamps();
            $table->unique(['document_type', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('payment_terms');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('commercial_settings');
    }
};
