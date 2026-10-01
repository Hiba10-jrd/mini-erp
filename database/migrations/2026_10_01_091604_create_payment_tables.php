<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->restrictOnDelete();

            $table->foreignId('payment_method_id')
                ->constrained('payment_methods')
                ->restrictOnDelete();

            $table->date('payment_date')->index();
            $table->decimal('amount', 15, 2);
            $table->string('reference', 150)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['customer_id', 'payment_date']);
            $table->index(['payment_method_id', 'payment_date']);
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('payments')
                ->restrictOnDelete();

            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->restrictOnDelete();

            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(
                ['payment_id', 'invoice_id'],
                'payment_allocations_payment_invoice_unique'
            );

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
