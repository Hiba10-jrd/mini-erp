<?php

namespace Tests\Support;

use App\Models\DocumentSequence;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\QuoteCalculator;
use Illuminate\Support\Facades\DB;

trait SupplierInvoiceSourceFixtures
{
    /** @param array<int, array<string, string>> $lines */
    private function createOrder(array $lines, ?Supplier $supplier = null): PurchaseOrder
    {
        $supplier ??= $this->createSupplier();
        $order = PurchaseOrder::query()->forceCreate([
            'number' => 'BCF-'.str()->upper(str()->random(8)),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'supplier_trade_name' => $supplier->trade_name,
            'supplier_address' => $supplier->address,
            'supplier_city' => $supplier->city,
            'supplier_country' => $supplier->country,
            'supplier_email' => $supplier->email,
            'supplier_phone' => $supplier->phone,
            'payment_term_label' => 'Net 30',
            'payment_term_days' => 30,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => today(),
            'subtotal_ht' => '100.00',
            'discount_total' => '0.00',
            'tax_total' => '20.00',
            'total_ttc' => '120.00',
        ]);
        foreach ($lines as $position => $line) {
            $product = $this->createProduct();
            $quantity = $line['quantity'];
            $unitPrice = $line['unit_price'] ?? '10.00';
            $discount = $line['discount_percent'] ?? '0.00';
            $tax = $line['tax_rate_percent'] ?? '0.00';
            $calculated = app(QuoteCalculator::class)->line($quantity, $unitPrice, $discount, $tax);
            $order->items()->create([
                'product_id' => $product->id,
                'item_type' => 'product',
                'reference' => $product->reference,
                'description' => $product->name,
                'unit_label' => $product->unit->symbol,
                'quantity' => $quantity,
                'unit_price' => $calculated['unit_price'],
                'discount_percent' => $calculated['discount_percent'],
                'discount_amount' => $calculated['discount_amount'],
                'tax_rate_percent' => $calculated['tax_rate_percent'],
                'subtotal_ht' => $calculated['subtotal_ht'],
                'tax_amount' => $calculated['tax_amount'],
                'total_ttc' => $calculated['total_ttc'],
                'position' => $position + 1,
            ]);
        }
        DB::table('purchase_orders')->where('id', $order->id)->update([
            'status' => PurchaseOrder::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);

        return $order->fresh('items.product.unit');
    }

    private function createReceipt(PurchaseOrder $order, string $quantity, bool $validated = true): GoodsReceipt
    {
        $warehouse = Warehouse::query()->first() ?? Warehouse::query()->forceCreate([
            'code' => 'DEP-TEST', 'name' => 'Dépôt test', 'is_active' => true,
        ]);
        $item = $order->items->firstOrFail();
        $receipt = GoodsReceipt::query()->forceCreate([
            'number' => 'BRF-'.str()->upper(str()->random(8)),
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => today(),
            'status' => GoodsReceipt::STATUS_DRAFT,
        ]);
        $receipt->items()->create([
            'purchase_order_item_id' => $item->id,
            'product_id' => $item->product_id,
            'item_type' => $item->item_type,
            'reference' => $item->reference,
            'description' => $item->description,
            'unit_label' => $item->unit_label,
            'quantity' => $quantity,
            'position' => 1,
        ]);
        if ($validated) {
            DB::table('goods_receipts')->where('id', $receipt->id)->update([
                'status' => GoodsReceipt::STATUS_VALIDATED,
                'validated_at' => now(),
            ]);
        }

        return $receipt->fresh(['items', 'warehouse']);
    }

    private function createSupplier(): Supplier
    {
        return Supplier::query()->forceCreate([
            'code' => 'FOU-'.str()->upper(str()->random(8)),
            'name' => 'Fournisseur test',
            'trade_name' => 'Fournisseur snapshot',
            'address' => 'Adresse fournisseur',
            'city' => 'Casablanca',
            'country' => 'Maroc',
            'email' => str()->random(8).'@example.test',
            'phone' => '0500000000',
            'status' => 'active',
        ]);
    }

    private function createProduct(): Product
    {
        $unit = Unit::query()->firstOrCreate(['symbol' => 'pce'], ['name' => 'Pièce']);

        return Product::query()->forceCreate([
            'type' => 'product',
            'reference' => 'PRD-'.str()->upper(str()->random(8)),
            'name' => 'Article test',
            'unit_id' => $unit->id,
            'purchase_price' => '10.00',
            'selling_price' => '15.00',
            'is_active' => true,
        ]);
    }

    private function createSequence(): DocumentSequence
    {
        return DocumentSequence::query()->create([
            'document_type' => 'supplier_invoice',
            'prefix' => 'FAF',
            'year' => today()->year,
            'counter' => 0,
            'number_format' => '{prefix}-{year}-{counter:05d}',
        ]);
    }
}
