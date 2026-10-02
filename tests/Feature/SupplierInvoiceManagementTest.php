<?php

namespace Tests\Feature;

use App\Models\DocumentSequence;
use App\Models\GoodsReceipt;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\QuoteCalculator;
use App\Services\SupplierInvoiceManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierInvoiceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->actingAs($this->userWithPermissions(['purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete']));
    }

    public function test_draft_uses_validated_receipt_items_and_order_financial_snapshots(): void
    {
        $order = $this->createOrder([[
            'quantity' => '10.000',
            'unit_price' => '0.05',
            'discount_percent' => '10.00',
            'tax_rate_percent' => '20.00',
        ]]);
        $source = $this->createReceipt($order, '1.000')->items->firstOrFail();

        $invoice = $this->service()->createDraft($order, $this->attributes($source, '  FOURN-2026-001  ', '1.000', [
            'invoice_date' => '2026-10-01',
        ]));
        $line = $invoice->items->firstOrFail();

        $this->assertNull($invoice->number);
        $this->assertSame('FOURN-2026-001', $invoice->supplier_invoice_number);
        $this->assertSame(SupplierInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame($source->id, $line->goods_receipt_item_id);
        $this->assertSame($order->items->firstOrFail()->id, $line->purchase_order_item_id);
        $this->assertSame('0.05', $line->unit_price);
        $this->assertSame('10.00', $line->discount_percent);
        $this->assertSame('20.00', $line->tax_rate_percent);
        $this->assertSame('0.01', $line->discount_amount);
        $this->assertSame('0.04', $line->subtotal_ht);
        $this->assertSame('0.01', $line->tax_amount);
        $this->assertSame('0.05', $line->total_ttc);
        $this->assertSame('Fournisseur test', $invoice->supplier_name);
        $this->assertSame('Net 30', $invoice->payment_term_label);
        $this->assertSame('2026-10-31', $invoice->due_date->toDateString());
        $this->assertDatabaseHas('supplier_invoice_histories', ['supplier_invoice_id' => $invoice->id, 'event' => 'created']);
        $this->assertDatabaseHas('purchase_order_histories', ['purchase_order_id' => $order->id, 'event' => 'supplier_invoice_created']);
    }

    public function test_all_non_cancelled_invoices_reserve_receipt_quantity_and_edit_excludes_current_invoice(): void
    {
        $order = $this->createOrder([['quantity' => '10.000']]);
        $source = $this->createReceipt($order, '10.000')->items->firstOrFail();
        $service = $this->service();
        $first = $service->createDraft($order, $this->attributes($source, 'F-001', '4.000'));
        $second = $service->createDraft($order, $this->attributes($source, 'F-002', '3.000'));
        $this->createSequence();
        $second = $service->validate($second);

        $general = $service->availableItemsForOrder($order);
        $this->assertSame('3.000', $general[0]['available_quantity']);
        $this->assertSame('4.000', $general[0]['reserved_by_other_drafts']);
        $this->assertSame('3.000', $general[0]['validated_invoiced_quantity']);

        $editing = $service->availableItemsForOrder($order, $first);
        $this->assertSame('7.000', $editing[0]['available_quantity']);
        $this->assertSame('0.000', $editing[0]['reserved_by_other_drafts']);
        $this->assertSame('3.000', $editing[0]['validated_invoiced_quantity']);
        $first = $service->updateDraft($first, $this->attributes($source, 'F-001', '7.000'));
        $this->assertSame('7.000', $first->items->firstOrFail()->quantity);

        $service->cancelDraft($first);
        $released = $service->availableItemsForOrder($order);
        $this->assertSame('7.000', $released[0]['available_quantity']);
        $this->assertSame('0.000', $released[0]['reserved_by_other_drafts']);
        $this->assertSame(SupplierInvoice::STATUS_VALIDATED, $second->status);
    }

    public function test_supplier_reference_is_required_unique_per_supplier_and_stays_reserved_after_cancellation(): void
    {
        $supplier = $this->createSupplier();
        $firstOrder = $this->createOrder([['quantity' => '2.000']], $supplier);
        $secondOrder = $this->createOrder([['quantity' => '2.000']], $supplier);
        $firstSource = $this->createReceipt($firstOrder, '2.000')->items->firstOrFail();
        $secondSource = $this->createReceipt($secondOrder, '2.000')->items->firstOrFail();
        $service = $this->service();

        $this->expectValidationMessage(fn () => $service->createDraft($firstOrder, $this->attributes($firstSource, '   ', '1.000')), 'supplier_invoice_number');
        $first = $service->createDraft($firstOrder, $this->attributes($firstSource, 'REF-UNIQUE', '1.000'));
        $service->cancelDraft($first);

        try {
            $service->createDraft($secondOrder, $this->attributes($secondSource, 'REF-UNIQUE', '1.000'));
            $this->fail('The supplier reference must remain reserved after cancellation.');
        } catch (ValidationException $exception) {
            $this->assertSame('Cette référence de facture existe déjà pour ce fournisseur.', $exception->errors()['supplier_invoice_number'][0]);
        }

        $otherOrder = $this->createOrder([['quantity' => '1.000']], $this->createSupplier());
        $otherSource = $this->createReceipt($otherOrder, '1.000')->items->firstOrFail();
        $other = $service->createDraft($otherOrder, $this->attributes($otherSource, 'REF-UNIQUE', '1.000'));
        $this->assertSame('REF-UNIQUE', $other->supplier_invoice_number);
    }

    public function test_validation_rebuilds_snapshots_allocates_faf_once_and_writes_histories(): void
    {
        $order = $this->createOrder([[
            'quantity' => '2.000',
            'unit_price' => '100.00',
            'discount_percent' => '10.00',
            'tax_rate_percent' => '20.00',
        ]]);
        $source = $this->createReceipt($order, '2.000')->items->firstOrFail();
        $sequence = $this->createSequence();
        $service = $this->service();
        $draft = $service->createDraft($order, $this->attributes($source, 'SUP-777', '1.000'));

        DB::table('supplier_invoice_items')->where('supplier_invoice_id', $draft->id)->update([
            'unit_price' => '0.01', 'discount_percent' => '0.00', 'discount_amount' => '0.00',
            'tax_rate_percent' => '0.00', 'subtotal_ht' => '0.01', 'tax_amount' => '0.00', 'total_ttc' => '0.01',
        ]);
        DB::table('supplier_invoices')->where('id', $draft->id)->update([
            'subtotal_ht' => '0.01', 'discount_total' => '0.00', 'tax_total' => '0.00', 'total_ttc' => '0.01',
        ]);

        $validated = $service->validate($draft->fresh());
        $this->assertSame('FAF-'.today()->year.'-00001', $validated->number);
        $this->assertSame(SupplierInvoice::STATUS_VALIDATED, $validated->status);
        $this->assertSame('100.00', $validated->items->firstOrFail()->unit_price);
        $this->assertSame('100.00', $validated->subtotal_ht);
        $this->assertSame('10.00', $validated->discount_total);
        $this->assertSame('18.00', $validated->tax_total);
        $this->assertSame('108.00', $validated->total_ttc);
        $this->assertEqualsCanonicalizing(['created', 'validated'], $validated->histories->pluck('event')->all());
        $this->assertDatabaseHas('purchase_order_histories', ['purchase_order_id' => $order->id, 'event' => 'supplier_invoice_validated']);

        $historyCount = $validated->histories()->count();
        $orderHistoryCount = $order->histories()->count();
        $this->expectValidationMessage(fn () => $service->validate($validated), 'status');
        $this->assertSame(1, $sequence->fresh()->counter);
        $this->assertSame($historyCount, $validated->histories()->count());
        $this->assertSame($orderHistoryCount, $order->histories()->count());
        $this->assertSame('FAF-'.today()->year.'-00001', $validated->fresh()->number);
    }

    public function test_validation_rechecks_availability_and_rolls_back_without_consuming_a_number(): void
    {
        $order = $this->createOrder([['quantity' => '5.000']]);
        $source = $this->createReceipt($order, '5.000')->items->firstOrFail();
        $sequence = $this->createSequence();
        $service = $this->service();
        $draft = $service->createDraft($order, $this->attributes($source, 'FIRST', '5.000'));

        $competing = SupplierInvoice::query()->forceCreate([
            'purchase_order_id' => $order->id, 'supplier_id' => $order->supplier_id,
            'supplier_invoice_number' => 'COMPETING', 'supplier_name' => $order->supplier_name,
            'status' => SupplierInvoice::STATUS_DRAFT, 'invoice_date' => today(),
            'subtotal_ht' => '10.00', 'discount_total' => '0.00', 'tax_total' => '0.00', 'total_ttc' => '10.00',
        ]);
        $competing->items()->create([
            'goods_receipt_item_id' => $source->id, 'purchase_order_item_id' => $source->purchase_order_item_id,
            'product_id' => $source->product_id, 'item_type' => $source->item_type, 'reference' => $source->reference,
            'description' => $source->description, 'unit_label' => $source->unit_label, 'quantity' => '1.000',
            'unit_price' => '10.00', 'discount_percent' => '0.00', 'discount_amount' => '0.00',
            'tax_rate_percent' => '0.00', 'subtotal_ht' => '10.00', 'tax_amount' => '0.00', 'total_ttc' => '10.00', 'position' => 1,
        ]);

        $this->expectValidationMessage(fn () => $service->validate($draft), 'items.0.quantity');
        $this->assertSame(SupplierInvoice::STATUS_DRAFT, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->number);
        $this->assertSame(0, $sequence->fresh()->counter);
        $this->assertSame(['created'], $draft->histories()->pluck('event')->all());
        $this->assertFalse($order->histories()->where('event', 'supplier_invoice_validated')->exists());
    }

    public function test_supplier_invoice_never_changes_stock_or_creates_movements(): void
    {
        $order = $this->createOrder([['quantity' => '2.000']]);
        $source = $this->createReceipt($order, '2.000')->items->firstOrFail();
        $warehouse = $source->goodsReceipt->warehouse;
        WarehouseStock::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $source->product_id,
            'quantity' => '12.000',
        ]);
        $this->createSequence();
        $movementCount = StockMovement::query()->count();
        $service = $this->service();
        $draft = $service->createDraft($order, $this->attributes($source, 'NO-STOCK', '2.000'));
        $beforeValidation = WarehouseStock::query()->where('product_id', $source->product_id)->value('quantity');
        $service->validate($draft);

        $this->assertSame($beforeValidation, WarehouseStock::query()->where('product_id', $source->product_id)->value('quantity'));
        $this->assertSame($movementCount, StockMovement::query()->count());
    }

    public function test_draft_or_foreign_receipt_items_cannot_be_invoiced(): void
    {
        $order = $this->createOrder([['quantity' => '5.000']]);
        $validatedSource = $this->createReceipt($order, '2.000')->items->firstOrFail();
        $draftSource = $this->createReceipt($order, '3.000', false)->items->firstOrFail();
        $otherOrder = $this->createOrder([['quantity' => '1.000']]);
        $foreignSource = $this->createReceipt($otherOrder, '1.000')->items->firstOrFail();
        $service = $this->service();

        $available = $service->availableItemsForOrder($order);
        $this->assertCount(1, $available);
        $this->assertSame($validatedSource->id, $available[0]['goods_receipt_item_id']);
        $this->expectValidationMessage(fn () => $service->createDraft($order, $this->attributes($draftSource, 'DRAFT-SOURCE', '1.000')), 'items.0.goods_receipt_item_id');
        $this->expectValidationMessage(fn () => $service->createDraft($order, $this->attributes($foreignSource, 'FOREIGN-SOURCE', '1.000')), 'items.0.goods_receipt_item_id');
    }

    private function service(): SupplierInvoiceManagementService
    {
        return app(SupplierInvoiceManagementService::class);
    }

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

    /** @return array<string, mixed> */
    private function attributes($source, string $reference, string $quantity, array $overrides = []): array
    {
        return array_merge([
            'supplier_invoice_number' => $reference,
            'invoice_date' => today()->toDateString(),
            'notes' => null,
            'items' => [[
                'goods_receipt_item_id' => $source->id,
                'quantity' => $quantity,
            ]],
        ], $overrides);
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'Supplier invoice test '.$user->id,
            'slug' => 'supplier-invoice-test-'.$user->id,
        ]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }

    private function expectValidationMessage(callable $callback, string $key): void
    {
        try {
            $callback();
            $this->fail("A validation error was expected for {$key}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
