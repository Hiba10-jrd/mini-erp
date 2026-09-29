<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 100)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
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
            $table->string('status', 20)->default('draft')->index();
            $table->date('quote_date')->index();
            $table->date('valid_until')->nullable()->index();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            // Convention: gross HT before line discounts.
            $table->decimal('subtotal_ht', 15, 2);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total_ttc', 15, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->index(['customer_id', 'quote_date']);
        });

        Schema::create('quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('item_type', 20);
            $table->string('reference', 100)->nullable();
            $table->text('description');
            $table->string('unit_label', 100)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            // Convention: net HT after the line discount.
            $table->decimal('subtotal_ht', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_ttc', 15, 2);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['quote_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
    }
};
