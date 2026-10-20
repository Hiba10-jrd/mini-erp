<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(0);
        });
        Schema::create('supplier_invoice_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('attachment_id')->constrained('attachments')->restrictOnDelete();
            $table->decimal('net_ht', 15, 2);
            $table->decimal('tax_total', 15, 2);
            $table->decimal('total_ttc', 15, 2);
            $table->json('breakdown');
            $table->text('difference_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_reconciliations');
        Schema::table('supplier_invoices', fn (Blueprint $table) => $table->dropColumn('lock_version'));
    }
};
