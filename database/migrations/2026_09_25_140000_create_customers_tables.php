<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_code_sequences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->boolean('singleton')->default(true)->unique();
            $table->timestamps();
        });

        DB::table('customer_code_sequences')->insert([
            'next_number' => 1,
            'singleton' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('customer_type', 20);
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('ice', 100)->nullable();
            $table->string('tax_id', 100)->nullable();
            $table->string('commercial_register', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('job_title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['customer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_code_sequences');
    }
};
