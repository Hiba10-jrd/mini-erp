<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_inventory_sequences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        Schema::create('stock_inventories', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 100)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('stock_inventory_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_inventory_id')->constrained('stock_inventories')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('theoretical_quantity', 15, 3);
            $table->decimal('actual_quantity', 15, 3)->nullable();
            $table->decimal('difference', 15, 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['stock_inventory_id', 'product_id']);
            $table->index(['product_id', 'difference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_inventory_lines');
        Schema::dropIfExists('stock_inventories');
        Schema::dropIfExists('stock_inventory_sequences');
    }
};
