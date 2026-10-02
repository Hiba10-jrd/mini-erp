<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 100)->nullable();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('supplier_invoice_number', 100);
            $table->string('supplier_name');
            $table->string('supplier_trade_name')->nullable();
            $table->text('supplier_address')->nullable();
            $table->string('supplier_city', 100)->nullable();
            $table->string('supplier_country', 100)->nullable();
            $table->string('supplier_email')->nullable();
            $table->string('supplier_phone', 50)->nullable();
            $table->string('supplier_ice', 100)->nullable();
            $table->string('supplier_tax_id', 100)->nullable();
            $table->string('supplier_commercial_register', 100)->nullable();
            $table->string('payment_term_label')->nullable();
            $table->unsignedInteger('payment_term_days')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->date('invoice_date')->index();
            $table->date('due_date')->nullable()->index();
            $table->text('notes')->nullable();
            $table->decimal('subtotal_ht', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total_ttc', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique('number', 'si_number_unique');
            $table->unique(['supplier_id', 'supplier_invoice_number'], 'si_supplier_ref_unique');
            $table->index(['purchase_order_id', 'status'], 'si_order_status_idx');
            $table->index(['supplier_id', 'invoice_date'], 'si_supplier_date_idx');
        });

        Schema::create('supplier_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->foreignId('goods_receipt_item_id')->constrained('goods_receipt_items')->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
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

            $table->unique(['supplier_invoice_id', 'goods_receipt_item_id'], 'sii_invoice_receipt_uq');
            $table->unique(['supplier_invoice_id', 'position'], 'sii_invoice_position_uq');
            $table->index('goods_receipt_item_id', 'sii_receipt_item_idx');
            $table->index('purchase_order_item_id', 'sii_order_item_idx');
        });

        Schema::create('supplier_invoice_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->string('event', 50);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['supplier_invoice_id', 'created_at'], 'sih_invoice_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_histories');
        Schema::dropIfExists('supplier_invoice_items');
        Schema::dropIfExists('supplier_invoices');
    }
};
