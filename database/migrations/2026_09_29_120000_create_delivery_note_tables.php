<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 100)->unique();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 30)->default('draft')->index();
            $table->date('delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['sales_order_id', 'status']);
        });

        Schema::create('delivery_note_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_note_id')->constrained('delivery_notes')->restrictOnDelete();
            $table->foreignId('sales_order_item_id')->constrained('sales_order_items')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('item_type', 20);
            $table->string('reference', 100)->nullable();
            $table->text('description');
            $table->string('unit_label', 100)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['delivery_note_id', 'position']);
            $table->index(['delivery_note_id', 'sales_order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_items');
        Schema::dropIfExists('delivery_notes');
    }
};
