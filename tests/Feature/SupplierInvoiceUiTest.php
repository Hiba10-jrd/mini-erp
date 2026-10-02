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
use App\Services\SupplierInvoiceManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SupplierInvoiceUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_routes_navigation_and_rbac_are_enforced(): void
    {
        $order = $this->createOrder();
        $source = $this->createReceipt($order, '2.000')->items->firstOrFail();
        $this->actingAs($this->userWithPermissions(['purchases.create', 'purchases.view']));
        $invoice = $this->createDraft($order, $source, 'RBAC-001', '1.000');

        auth()->logout();
        $this->get(route('purchases.invoices.index'))->assertRedirect(route('login'));
        $this->get(route('purchases.invoices.create'))->assertRedirect(route('login'));

        $this->actingAs($this->userWithPermissions([]));
        $this->get(route('purchases.invoices.index'))->assertForbidden();
        $this->get(route('purchases.invoices.create'))->assertForbidden();
        $this->get(route('purchases.orders.invoices.create', $order))->assertForbidden();
        $this->get(route('purchases.invoices.show', $invoice))->assertForbidden();
        $this->get(route('purchases.invoices.edit', $invoice))->assertForbidden();

        $this->actingAs($this->userWithPermissions(['purchases.view']));
        $this->get(route('purchases.invoices.index'))->assertOk()->assertSeeVolt('admin.supplier-invoices-manager');
        $this->get(route('purchases.invoices.show', $invoice))->assertOk()->assertSeeVolt('admin.supplier-invoice-details');
        $this->get(route('purchases.invoices.create'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertSee('Factures fournisseurs');

        $this->actingAs($this->userWithPermissions(['purchases.create', 'purchases.update']));
        $this->get(route('purchases.invoices.create'))->assertOk()->assertSeeVolt('admin.supplier-invoice-form');
        $this->get(route('purchases.orders.invoices.create', $order))->assertOk()->assertSeeVolt('admin.supplier-invoice-form');
        $this->get(route('purchases.invoices.edit', $invoice))->assertOk()->assertSeeVolt('admin.supplier-invoice-form');
    }

    public function test_list_search_filters_and_pagination_cover_faf_reference_bcf_and_supplier(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.view']));
        $supplier = $this->createSupplier(['name' => 'Atlas Facturation']);
        $order = $this->createOrder($supplier);

        foreach (range(1, 11) as $index) {
            SupplierInvoice::query()->forceCreate([
                'number' => sprintf('FAF-2026-%05d', $index),
                'purchase_order_id' => $order->id,
                'supplier_id' => $supplier->id,
                'supplier_invoice_number' => sprintf('FOURN-%03d', $index),
                'supplier_name' => $supplier->name,
                'status' => $index === 11 ? SupplierInvoice::STATUS_DRAFT : SupplierInvoice::STATUS_VALIDATED,
                'invoice_date' => today(),
                'subtotal_ht' => '10.00',
                'discount_total' => '0.00',
                'tax_total' => '0.00',
                'total_ttc' => '10.00',
            ]);
        }

        Volt::test('admin.supplier-invoices-manager')
            ->assertViewHas('invoices', fn ($invoices): bool => $invoices->perPage() === 10 && $invoices->total() === 11)
            ->set('search', 'FAF-2026-00001')
            ->assertViewHas('invoices', fn ($invoices): bool => $invoices->total() === 1)
            ->set('search', 'FOURN-002')
            ->assertViewHas('invoices', fn ($invoices): bool => $invoices->total() === 1)
            ->set('search', $order->number)
            ->assertViewHas('invoices', fn ($invoices): bool => $invoices->total() === 11)
            ->set('search', 'Atlas Facturation')
            ->set('statusFilter', SupplierInvoice::STATUS_DRAFT)
            ->assertViewHas('invoices', fn ($invoices): bool => $invoices->total() === 1)
            ->set('statusFilter', 'all')
            ->set('supplierFilter', (string) $supplier->id)
            ->assertViewHas('invoices', fn ($invoices): bool => $invoices->total() === 11);
    }

    public function test_creation_from_order_and_general_creation_show_only_validated_available_receipts(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.create', 'purchases.view']));
        $order = $this->createOrder();
        $validated = $this->createReceipt($order, '3.000')->items->firstOrFail();
        $draftReceipt = $this->createReceipt($order, '2.000', false);

        Volt::test('admin.supplier-invoice-form', ['orderId' => $order->id])
            ->assertSee($validated->goodsReceipt->number)
            ->assertDontSee($draftReceipt->number)
            ->assertSee('3.000')
            ->set('supplierInvoiceNumber', 'FROM-BCF')
            ->set('invoiceDate', today()->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $created = SupplierInvoice::query()->where('supplier_invoice_number', 'FROM-BCF')->firstOrFail();
        $this->assertSame(SupplierInvoice::STATUS_DRAFT, $created->status);
        $this->assertNull($created->number);
        $this->assertSame($validated->id, $created->items->firstOrFail()->goods_receipt_item_id);

        $otherOrder = $this->createOrder();
        $otherSource = $this->createReceipt($otherOrder, '1.000')->items->firstOrFail();
        Volt::test('admin.supplier-invoice-form')
            ->set('purchaseOrderId', (string) $otherOrder->id)
            ->assertSet('lines.'.$otherSource->id.'.available_quantity', '1.000')
            ->set('supplierInvoiceNumber', 'GENERAL-001')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('supplier_invoices', ['supplier_invoice_number' => 'GENERAL-001', 'status' => 'draft']);
    }

    public function test_form_displays_reservations_and_edit_updates_only_draft_quantity(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.create', 'purchases.update', 'purchases.view']));
        $order = $this->createOrder();
        $source = $this->createReceipt($order, '5.000')->items->firstOrFail();
        $first = $this->createDraft($order, $source, 'EDIT-001', '2.000');
        $this->createDraft($order, $source, 'RESERVED-002', '1.000');

        Volt::test('admin.supplier-invoice-form', ['invoiceId' => $first->id])
            ->assertSet('lines.'.$source->id.'.reserved_by_other_drafts', '1.000')
            ->assertSet('lines.'.$source->id.'.available_quantity', '4.000')
            ->set('supplierInvoiceNumber', 'EDIT-001-BIS')
            ->set('lines.'.$source->id.'.quantity', '4.000')
            ->call('save')
            ->assertHasNoErrors();

        $first = $first->fresh('items');
        $this->assertSame('EDIT-001-BIS', $first->supplier_invoice_number);
        $this->assertSame('4.000', $first->items->firstOrFail()->quantity);
        $this->assertSame('10.00', $first->items->firstOrFail()->unit_price);
        $this->assertDatabaseHas('supplier_invoice_histories', ['supplier_invoice_id' => $first->id, 'event' => 'draft_updated']);
    }

    public function test_details_validate_cancel_and_enforce_immutability_without_stock_impact(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete']));
        $order = $this->createOrder();
        $source = $this->createReceipt($order, '3.000')->items->firstOrFail();
        WarehouseStock::query()->create([
            'warehouse_id' => $source->goodsReceipt->warehouse_id,
            'product_id' => $source->product_id,
            'quantity' => '9.000',
        ]);
        $this->createSequence();
        $draft = $this->createDraft($order, $source, 'VALIDATE-UI', '2.000');
        $stockBefore = WarehouseStock::query()->value('quantity');
        $movementsBefore = StockMovement::query()->count();

        Volt::test('admin.supplier-invoice-details', ['invoiceId' => $draft->id])
            ->assertSee('VALIDATE-UI')
            ->assertSee($source->goodsReceipt->number)
            ->call('validateSupplierInvoice')
            ->assertHasNoErrors()
            ->assertDontSee('Modifier');

        $validated = $draft->fresh();
        $this->assertSame(SupplierInvoice::STATUS_VALIDATED, $validated->status);
        $this->assertStringStartsWith('FAF-', $validated->number);
        $this->assertSame($stockBefore, WarehouseStock::query()->value('quantity'));
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->get(route('purchases.invoices.edit', $validated))->assertForbidden();

        $cancelSource = $this->createReceipt($order, '1.000')->items->firstOrFail();
        $cancelled = $this->createDraft($order, $cancelSource, 'CANCEL-UI', '1.000');
        Volt::test('admin.supplier-invoice-details', ['invoiceId' => $cancelled->id])
            ->call('cancelSupplierInvoice')
            ->assertHasNoErrors()
            ->assertDontSee('Modifier');
        $this->assertSame(SupplierInvoice::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->get(route('purchases.invoices.edit', $cancelled->fresh()))->assertForbidden();
    }

    public function test_order_button_is_hidden_when_no_quantity_remains_and_receipt_links_invoice(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.view', 'purchases.create']));
        $order = $this->createOrder();
        $source = $this->createReceipt($order, '2.000')->items->firstOrFail();

        Volt::test('admin.purchase-order-details', ['orderId' => $order->id])
            ->assertSee('Créer une facture fournisseur');

        $invoice = $this->createDraft($order, $source, 'FULL-RESERVATION', '2.000');
        Volt::test('admin.purchase-order-details', ['orderId' => $order->id])
            ->assertDontSee('Créer une facture fournisseur')
            ->assertSee('FULL-RESERVATION');
        Volt::test('admin.goods-receipt-details', ['receiptId' => $source->goods_receipt_id])
            ->assertSee('Factures fournisseurs liées')
            ->assertSee('FULL-RESERVATION');

        $this->actingAs($this->userWithPermissions(['suppliers.view', 'purchases.view']));
        $this->get(route('admin.suppliers.show', $order->supplier_id))
            ->assertOk()
            ->assertSee('Factures fournisseurs')
            ->assertSee($invoice->supplier_invoice_number);
    }

    public function test_document_sequence_ui_supports_supplier_invoice_faf(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());
        $this->actingAs($user);

        Volt::test('admin.document-sequences-manager')
            ->set('documentType', 'supplier_invoice')
            ->assertSet('prefix', 'FAF')
            ->assertSee('Facture fournisseur');
    }

    private function createDraft(PurchaseOrder $order, $source, string $reference, string $quantity): SupplierInvoice
    {
        return app(SupplierInvoiceManagementService::class)->createDraft($order, [
            'supplier_invoice_number' => $reference,
            'invoice_date' => today()->toDateString(),
            'items' => [[
                'goods_receipt_item_id' => $source->id,
                'quantity' => $quantity,
            ]],
        ]);
    }

    private function createOrder(?Supplier $supplier = null): PurchaseOrder
    {
        $supplier ??= $this->createSupplier();
        $product = $this->createProduct();
        $order = PurchaseOrder::query()->forceCreate([
            'number' => 'BCF-'.str()->upper(str()->random(8)),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'supplier_trade_name' => $supplier->trade_name,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => today(),
            'subtotal_ht' => '50.00',
            'discount_total' => '0.00',
            'tax_total' => '0.00',
            'total_ttc' => '50.00',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'item_type' => 'product',
            'reference' => $product->reference,
            'description' => $product->name,
            'unit_label' => $product->unit->symbol,
            'quantity' => '5.000',
            'unit_price' => '10.00',
            'discount_percent' => '0.00',
            'discount_amount' => '0.00',
            'tax_rate_percent' => '0.00',
            'subtotal_ht' => '50.00',
            'tax_amount' => '0.00',
            'total_ttc' => '50.00',
            'position' => 1,
        ]);
        DB::table('purchase_orders')->where('id', $order->id)->update([
            'status' => PurchaseOrder::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);

        return $order->fresh('items.product.unit');
    }

    private function createReceipt(PurchaseOrder $order, string $quantity, bool $validated = true): GoodsReceipt
    {
        $warehouse = Warehouse::query()->first() ?? Warehouse::query()->forceCreate([
            'code' => 'DEP-UI', 'name' => 'Dépôt UI', 'is_active' => true,
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

        return $receipt->fresh(['items.goodsReceipt', 'warehouse']);
    }

    private function createSupplier(array $attributes = []): Supplier
    {
        return Supplier::query()->forceCreate(array_merge([
            'code' => 'FOU-'.str()->upper(str()->random(8)),
            'name' => 'Fournisseur UI',
            'trade_name' => 'Fournisseur interface',
            'status' => 'active',
        ], $attributes));
    }

    private function createProduct(): Product
    {
        $unit = Unit::query()->firstOrCreate(['symbol' => 'pce'], ['name' => 'Pièce']);

        return Product::query()->forceCreate([
            'type' => 'product',
            'reference' => 'PRD-'.str()->upper(str()->random(8)),
            'name' => 'Article UI',
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

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'Supplier invoice UI '.$user->id,
            'slug' => 'supplier-invoice-ui-'.$user->id,
        ]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }
}
