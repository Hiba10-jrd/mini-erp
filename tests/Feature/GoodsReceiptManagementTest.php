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
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\DocumentSequenceManagementService;
use App\Services\GoodsReceiptManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use LogicException;
use Tests\TestCase;

class GoodsReceiptManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_permissions_routes_livewire_service_and_super_admin_are_enforced(): void
    {
        $this->get(route('purchases.receipts.index'))->assertRedirect(route('login'));
        $order = $this->createOrder(PurchaseOrder::STATUS_CONFIRMED);
        $warehouse = $this->createWarehouse();
        $this->createSequence();

        $this->actingAs($this->userWithPermissions([]));
        $this->get(route('purchases.receipts.index'))->assertForbidden();
        $this->get(route('purchases.receipts.create'))->assertForbidden();
        $this->get(route('purchases.orders.receipts.create', $order))->assertForbidden();
        Volt::test('admin.goods-receipts-manager')->assertForbidden();
        Volt::test('admin.goods-receipt-form', ['orderId' => $order->id])->assertForbidden();
        try {
            $this->service()->createDraft($order, $this->attributes($warehouse, $order));
            $denied = false;
        } catch (AuthorizationException) {
            $denied = true;
        }
        $this->assertTrue($denied);

        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());
        $this->actingAs($superAdmin);
        $this->get(route('purchases.receipts.index'))->assertOk()->assertSeeVolt('admin.goods-receipts-manager');
        $this->get(route('purchases.receipts.create'))->assertOk()->assertSeeVolt('admin.goods-receipt-form');
        $this->get(route('purchases.orders.receipts.create', $order))->assertOk()->assertSeeVolt('admin.goods-receipt-form');
        Volt::test('admin.document-sequences-manager')->set('documentType', 'goods_receipt')->assertSet('prefix', 'BRF');
    }

    public function test_only_confirmed_orders_and_active_warehouses_can_create_receipts(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.create']));
        $draft = $this->createOrder(PurchaseOrder::STATUS_DRAFT);
        $cancelled = $this->createOrder(PurchaseOrder::STATUS_CANCELLED);
        $confirmed = $this->createOrder(PurchaseOrder::STATUS_CONFIRMED);
        $warehouse = $this->createWarehouse();
        $inactiveWarehouse = $this->createWarehouse(['is_active' => false]);
        $this->createSequence();

        foreach ([
            fn () => $this->service()->createDraft($draft, $this->attributes($warehouse, $draft)),
            fn () => $this->service()->createDraft($cancelled, $this->attributes($warehouse, $cancelled)),
            fn () => $this->service()->createDraft($confirmed, $this->attributes($inactiveWarehouse, $confirmed)),
        ] as $attempt) {
            try {
                $attempt();
                $rejected = false;
            } catch (ValidationException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        }

        $receipt = $this->service()->createDraft($confirmed, $this->attributes($warehouse, $confirmed));
        $this->assertSame(GoodsReceipt::STATUS_DRAFT, $receipt->status);
    }

    public function test_partial_and_multiple_receipts_never_exceed_ordered_quantity(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.create', 'purchases.update']));
        $order = $this->createOrder(PurchaseOrder::STATUS_CONFIRMED, [['type' => 'product', 'quantity' => '10.000']]);
        $item = $order->items->firstOrFail();
        $warehouse = $this->createWarehouse();
        $this->createSequence();
        $service = $this->service();

        $first = $service->createDraft($order, $this->attributes($warehouse, $order, [[$item->id, '4.000']]));
        $service->validate($first);
        $available = $service->availableItemsForOrder($order);
        $this->assertSame('4.000', $available[$item->id]['received']);
        $this->assertSame('6.000', $available[$item->id]['remaining']);

        $second = $service->createDraft($order, $this->attributes($warehouse, $order, [[$item->id, '6.000']]));
        $service->validate($second);
        $this->assertSame('0.000', $service->availableItemsForOrder($order)[$item->id]['remaining']);
        $this->assertSame(2, GoodsReceipt::query()->where('purchase_order_id', $order->id)->where('status', 'validated')->count());

        try {
            $service->createDraft($order, $this->attributes($warehouse, $order, [[$item->id, '0.001']]));
            $overReceived = false;
        } catch (ValidationException) {
            $overReceived = true;
        }
        $this->assertTrue($overReceived);
    }

    public function test_stock_changes_only_once_on_validation_and_services_create_no_movements(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete']));
        $order = $this->createOrder(PurchaseOrder::STATUS_CONFIRMED, [
            ['type' => 'product', 'quantity' => '5.000'],
            ['type' => 'service', 'quantity' => '1.000'],
        ]);
        [$physical, $serviceItem] = $order->items->all();
        $warehouse = $this->createWarehouse();
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $physical->product_id, 'quantity' => '2.000']);
        $this->createSequence();
        $receipt = $this->service()->createDraft($order, $this->attributes($warehouse, $order, [[$physical->id, '4.000'], [$serviceItem->id, '1.000']]));

        $this->get(route('purchases.receipts.show', $receipt))
            ->assertOk()
            ->assertSeeVolt('admin.goods-receipt-details')
            ->assertSee($receipt->number)
            ->assertSee($order->number);
        $this->get(route('purchases.receipts.edit', $receipt))->assertOk()->assertSeeVolt('admin.goods-receipt-form');
        $this->get(route('purchases.orders.show', $order))
            ->assertOk()
            ->assertSee('Créer une réception')
            ->assertSee('5.000');
        $this->assertSame('2.000', WarehouseStock::query()->where('product_id', $physical->product_id)->value('quantity'));
        $this->assertSame(0, StockMovement::query()->count());
        $validated = $this->service()->validate($receipt);
        $this->assertSame('6.000', WarehouseStock::query()->where('product_id', $physical->product_id)->value('quantity'));
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $physical->product_id,
            'warehouse_id' => $warehouse->id,
            'type' => 'entry',
            'quantity' => '4.000',
            'quantity_before' => '2.000',
            'quantity_after' => '6.000',
            'reference' => $validated->number,
        ]);
        $this->assertSame(1, StockMovement::query()->count());
        $this->get(route('purchases.receipts.show', $validated))
            ->assertOk()
            ->assertSee('Validée')
            ->assertSee('Validation de la réception')
            ->assertSee('4.000');

        try {
            $this->service()->validate($validated);
            $doubleValidationRejected = false;
        } catch (ValidationException) {
            $doubleValidationRejected = true;
        }
        $this->assertTrue($doubleValidationRejected);
        $this->assertSame('6.000', WarehouseStock::query()->where('product_id', $physical->product_id)->value('quantity'));
        $this->assertSame(1, StockMovement::query()->count());

        $serviceOnlyOrder = $this->createOrder(PurchaseOrder::STATUS_CONFIRMED, [['type' => 'service', 'quantity' => '1.000']]);
        $serviceReceipt = $this->service()->createDraft($serviceOnlyOrder, $this->attributes($warehouse, $serviceOnlyOrder));
        $this->service()->validate($serviceReceipt);
        $this->assertSame(1, StockMovement::query()->count());

        $cancelOrder = $this->createOrder(PurchaseOrder::STATUS_CONFIRMED);
        $cancelled = $this->service()->createDraft($cancelOrder, $this->attributes($warehouse, $cancelOrder));
        $this->service()->cancelDraft($cancelled);
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_validation_rechecks_remaining_and_rolls_back_all_lines_atomically(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.create', 'purchases.update']));
        $order = $this->createOrder(PurchaseOrder::STATUS_CONFIRMED, [
            ['type' => 'product', 'quantity' => '5.000'],
            ['type' => 'product', 'quantity' => '5.000'],
        ]);
        [$firstItem, $secondItem] = $order->items->all();
        $warehouse = $this->createWarehouse();
        $this->createSequence();
        $receipt = $this->service()->createDraft($order, $this->attributes($warehouse, $order, [[$firstItem->id, '5.000'], [$secondItem->id, '5.000']]));
        Product::query()->whereKey($secondItem->product_id)->update(['is_active' => false]);

        try {
            $this->service()->validate($receipt);
            $atomicFailure = false;
        } catch (ValidationException) {
            $atomicFailure = true;
        }
        $this->assertTrue($atomicFailure);
        $this->assertSame(GoodsReceipt::STATUS_DRAFT, $receipt->fresh()->status);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, WarehouseStock::query()->sum('quantity'));

        Product::query()->whereKey($secondItem->product_id)->update(['is_active' => true]);
        $competing = GoodsReceipt::query()->forceCreate([
            'number' => 'BRF-COMPETING', 'purchase_order_id' => $order->id, 'warehouse_id' => $warehouse->id,
            'receipt_date' => today(), 'status' => GoodsReceipt::STATUS_DRAFT,
        ]);
        $competing->items()->create([
            'purchase_order_item_id' => $firstItem->id, 'product_id' => $firstItem->product_id, 'item_type' => 'product',
            'reference' => $firstItem->reference, 'description' => $firstItem->description, 'unit_label' => $firstItem->unit_label,
            'quantity' => '1.000', 'position' => 1,
        ]);
        DB::table('goods_receipts')->where('id', $competing->id)->update([
            'status' => GoodsReceipt::STATUS_VALIDATED,
            'validated_at' => now(),
        ]);
        try {
            $this->service()->validate($receipt);
            $staleRemainingRejected = false;
        } catch (ValidationException) {
            $staleRemainingRejected = true;
        }
        $this->assertTrue($staleRemainingRejected);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_history_relations_immutability_and_physical_deletion_protection(): void
    {
        $this->actingAs($user = $this->userWithPermissions(['purchases.create', 'purchases.update']));
        $order = $this->createOrder(PurchaseOrder::STATUS_CONFIRMED);
        $warehouse = $this->createWarehouse();
        $this->createSequence();
        $receipt = $this->service()->createDraft($order, $this->attributes($warehouse, $order));
        $receipt = $this->service()->updateDraft($receipt, $this->attributes($warehouse, $order, null, ['notes' => 'Contrôle effectué']));
        $receipt = $this->service()->validate($receipt);

        $this->assertTrue($receipt->purchaseOrder->is($order));
        $this->assertTrue($receipt->warehouse->is($warehouse));
        $this->assertTrue($receipt->validator->is($user));
        $this->assertTrue($receipt->items->firstOrFail()->purchaseOrderItem->purchaseOrder->is($order));
        $this->assertEqualsCanonicalizing(['created', 'draft_updated', 'validated'], $receipt->histories->pluck('event')->all());
        $this->assertTrue($order->histories()->where('event', 'goods_receipt_validated')->exists());

        foreach ([
            fn () => $receipt->forceFill(['notes' => 'Altération'])->save(),
            fn () => $receipt->items->firstOrFail()->forceFill(['quantity' => '9.000'])->save(),
            fn () => $receipt->histories->firstOrFail()->forceFill(['description' => 'Altération'])->save(),
            fn () => $receipt->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $rejected = false;
            } catch (LogicException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        }
    }

    public function test_numbering_preview_search_filters_pagination_and_livewire_tampering_are_safe(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.create', 'purchases.view', 'purchases.update']));
        $supplier = $this->createSupplier(['name' => 'Atlas réception']);
        $order = $this->createOrder(PurchaseOrder::STATUS_CONFIRMED, null, $supplier);
        $warehouse = $this->createWarehouse(['code' => 'DEP-REC']);
        $sequence = $this->createSequence();
        $first = $this->service()->createDraft($order, $this->attributes($warehouse, $order));
        $this->assertSame('BRF-'.today()->year.'-00001', $first->number);

        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());
        $this->actingAs($superAdmin);
        $preview = app(DocumentSequenceManagementService::class)->preview([
            'document_type' => 'goods_receipt', 'prefix' => 'BRF', 'year' => today()->year,
            'counter' => $sequence->fresh()->counter, 'number_format' => '{prefix}-{year}-{counter:05d}',
        ]);
        $this->assertSame('BRF-'.today()->year.'-00002', $preview);
        $this->assertSame(1, $sequence->fresh()->counter);

        $this->actingAs($this->userWithPermissions(['purchases.view', 'purchases.update']));
        foreach (range(2, 11) as $index) {
            GoodsReceipt::query()->forceCreate([
                'number' => sprintf('BRF-%d-%05d', today()->year, $index), 'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouse->id, 'receipt_date' => today(),
                'status' => $index === 11 ? GoodsReceipt::STATUS_VALIDATED : GoodsReceipt::STATUS_CANCELLED,
            ]);
        }
        Volt::test('admin.goods-receipts-manager')
            ->assertViewHas('receipts', fn ($receipts): bool => $receipts->perPage() === 10 && $receipts->total() === 11)
            ->set('search', $first->number)->assertViewHas('receipts', fn ($receipts): bool => $receipts->total() === 1)
            ->set('search', '')->set('statusFilter', GoodsReceipt::STATUS_VALIDATED)->assertViewHas('receipts', fn ($receipts): bool => $receipts->total() === 1)
            ->set('statusFilter', 'all')->set('supplierFilter', (string) $supplier->id)->set('warehouseFilter', (string) $warehouse->id)
            ->assertViewHas('receipts', fn ($receipts): bool => $receipts->total() === 11);

        try {
            Volt::test('admin.goods-receipt-form', ['receiptId' => $first->id])->set('receiptId', 999999);
            $tamperingRejected = false;
        } catch (CannotUpdateLockedPropertyException) {
            $tamperingRejected = true;
        }
        $this->assertTrue($tamperingRejected);
    }

    private function service(): GoodsReceiptManagementService
    {
        return app(GoodsReceiptManagementService::class);
    }

    private function createOrder(string $status, ?array $lines = null, ?Supplier $supplier = null): PurchaseOrder
    {
        $supplier ??= $this->createSupplier();
        $order = PurchaseOrder::query()->forceCreate([
            'number' => 'BCF-'.str()->upper(str()->random(8)), 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
            'status' => PurchaseOrder::STATUS_DRAFT, 'order_date' => today(), 'subtotal_ht' => '100.00',
            'discount_total' => '0.00', 'tax_total' => '20.00', 'total_ttc' => '120.00',
        ]);
        foreach ($lines ?? [['type' => 'product', 'quantity' => '10.000']] as $position => $line) {
            $product = $this->createProduct(['type' => $line['type']]);
            $order->items()->create([
                'product_id' => $product->id, 'item_type' => $product->type, 'reference' => $product->reference,
                'description' => $product->name, 'unit_label' => $product->unit->symbol, 'quantity' => $line['quantity'],
                'unit_price' => '10.00', 'discount_percent' => '0.00', 'discount_amount' => '0.00',
                'tax_rate_percent' => '0.00', 'subtotal_ht' => '10.00', 'tax_amount' => '0.00', 'total_ttc' => '10.00',
                'position' => $position + 1,
            ]);
        }
        DB::table('purchase_orders')->where('id', $order->id)->update([
            'status' => $status,
            'confirmed_at' => $status === PurchaseOrder::STATUS_CONFIRMED ? now() : null,
            'cancelled_at' => $status === PurchaseOrder::STATUS_CANCELLED ? now() : null,
        ]);

        return $order->fresh('items.product.unit');
    }

    private function createSupplier(array $attributes = []): Supplier
    {
        return Supplier::query()->forceCreate(array_merge(['code' => 'FOU-'.str()->upper(str()->random(8)), 'name' => 'Fournisseur test', 'status' => 'active'], $attributes));
    }

    private function createProduct(array $attributes = []): Product
    {
        $unit = Unit::query()->firstOrCreate(['symbol' => 'pce'], ['name' => 'Pièce']);

        return Product::query()->forceCreate(array_merge([
            'type' => 'product', 'reference' => 'PRD-'.str()->upper(str()->random(8)), 'name' => 'Article test',
            'unit_id' => $unit->id, 'purchase_price' => '10.00', 'selling_price' => '15.00', 'is_active' => true,
        ], $attributes));
    }

    private function createWarehouse(array $attributes = []): Warehouse
    {
        return Warehouse::query()->forceCreate(array_merge(['code' => 'DEP-'.str()->upper(str()->random(6)), 'name' => 'Dépôt test', 'is_active' => true], $attributes));
    }

    private function createSequence(): DocumentSequence
    {
        return DocumentSequence::query()->create([
            'document_type' => 'goods_receipt', 'prefix' => 'BRF', 'year' => today()->year,
            'counter' => 0, 'number_format' => '{prefix}-{year}-{counter:05d}',
        ]);
    }

    private function attributes(Warehouse $warehouse, PurchaseOrder $order, ?array $items = null, array $overrides = []): array
    {
        $items ??= $order->items->map(fn ($item): array => [$item->id, $item->quantity])->all();

        return array_merge([
            'warehouse_id' => $warehouse->id, 'receipt_date' => today()->toDateString(), 'notes' => null,
            'items' => array_map(fn (array $line): array => ['purchase_order_item_id' => $line[0], 'quantity' => $line[1]], $items),
        ], $overrides);
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'Receipt test '.$user->id, 'slug' => 'receipt-test-'.$user->id]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }
}
