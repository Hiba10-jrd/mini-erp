<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 100)->nullable()->unique();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->date('invoice_date')->index();
            $table->date('due_date')->nullable()->index();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->string('customer_name');
            $table->string('customer_trade_name')->nullable();
            $table->text('customer_address')->nullable();
            $table->string('customer_city', 100)->nullable();
            $table->string('customer_country', 100)->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->string('customer_ice', 100)->nullable();
            $table->string('customer_tax_id', 100)->nullable();
            $table->string('customer_commercial_register', 100)->nullable();

            $table->string('payment_term_label')->nullable();
            $table->unsignedInteger('payment_term_days')->nullable();

            $table->string('company_legal_name');
            $table->string('company_trade_name')->nullable();
            $table->text('company_address')->nullable();
            $table->string('company_city', 100)->nullable();
            $table->string('company_country', 100)->nullable();
            $table->string('company_ice', 100)->nullable();
            $table->string('company_tax_id', 100)->nullable();
            $table->string('company_commercial_register', 100)->nullable();
            $table->string('company_phone', 50)->nullable();
            $table->string('company_email')->nullable();

            $table->decimal('subtotal_ht', 15, 2);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total_ttc', 15, 2);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['sales_order_id', 'status']);
            $table->index(['customer_id', 'invoice_date']);
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('sales_order_item_id')->constrained('sales_order_items')->restrictOnDelete();
            $table->foreignId('delivery_note_item_id')->constrained('delivery_note_items')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->string('item_type', 20);
            $table->string('reference', 100)->nullable();
            $table->text('description');
            $table->string('unit_label', 100)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            $table->decimal('subtotal_ht', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_ttc', 15, 2);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['invoice_id', 'position']);
            $table->index(['invoice_id', 'delivery_note_item_id']);
        });

        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 100)->nullable()->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->date('credit_date')->index();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();

            $table->string('customer_name');
            $table->string('customer_trade_name')->nullable();
            $table->text('customer_address')->nullable();
            $table->string('customer_city', 100)->nullable();
            $table->string('customer_country', 100)->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->string('customer_ice', 100)->nullable();
            $table->string('customer_tax_id', 100)->nullable();
            $table->string('customer_commercial_register', 100)->nullable();

            $table->decimal('subtotal_ht', 15, 2);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total_ttc', 15, 2);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_note_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->restrictOnDelete();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->string('item_type', 20);
            $table->string('reference', 100)->nullable();
            $table->text('description');
            $table->string('unit_label', 100)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            $table->decimal('subtotal_ht', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_ttc', 15, 2);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['credit_note_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_items');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');

    }
};
