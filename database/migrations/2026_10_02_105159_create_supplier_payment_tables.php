<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->foreignId('payment_method_id')
                ->constrained('payment_methods')
                ->restrictOnDelete();

            $table->date('payment_date')->index();

            $table->decimal('amount', 15, 2);

            $table->string('reference', 150)->nullable();

            $table->json('details')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['supplier_id', 'payment_date'],
                'sp_supplier_date_idx'
            );

            $table->index(
                ['payment_method_id', 'payment_date'],
                'sp_method_date_idx'
            );
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('supplier_payment_id')
                ->constrained('supplier_payments')
                ->restrictOnDelete();

            $table->foreignId('supplier_invoice_id')
                ->constrained('supplier_invoices')
                ->restrictOnDelete();

            $table->decimal('amount', 15, 2);

            $table->timestamps();

            $table->unique(
                ['supplier_payment_id', 'supplier_invoice_id'],
                'spa_payment_invoice_uq'
            );

            $table->index(
                'supplier_invoice_id',
                'spa_invoice_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
    }
};
