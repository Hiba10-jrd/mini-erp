<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->decimal('initial_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('expense_category_id')
                ->constrained('expense_categories')
                ->restrictOnDelete();

            $table->foreignId('payment_method_id')
                ->constrained('payment_methods')
                ->restrictOnDelete();

            $table->foreignId('cash_register_id')
                ->nullable()
                ->constrained('cash_registers')
                ->restrictOnDelete();

            $table->date('expense_date');

            /*
             * amount = montant total de la dépense.
             * tax_amount = part de TVA comprise dans ce montant.
             */
            $table->decimal('amount', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);

            $table->string('reference', 120)->nullable();
            $table->text('description')->nullable();

            /*
             * Chemin du justificatif stocké hors base de données.
             */
            $table->string('receipt_path')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('expense_date');
            $table->index(['expense_category_id', 'expense_date']);
            $table->index(['payment_method_id', 'expense_date']);
        });

        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cash_register_id')
                ->constrained('cash_registers')
                ->restrictOnDelete();

            /*
             * Nullable because a cash transaction may be manual.
             */
            $table->foreignId('expense_id')
                ->nullable()
                ->constrained('expenses')
                ->restrictOnDelete();

            $table->date('transaction_date');

            /*
             * entry = cash inflow
             * exit  = cash outflow
             */
            $table->enum('type', ['entry', 'exit']);

            $table->decimal('amount', 15, 2);

            $table->string('reference', 120)->nullable();
            $table->text('description')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique('expense_id');
            $table->index('transaction_date');
            $table->index(['cash_register_id', 'transaction_date']);
            $table->index(['cash_register_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('cash_registers');
        Schema::dropIfExists('expense_categories');
    }
};
