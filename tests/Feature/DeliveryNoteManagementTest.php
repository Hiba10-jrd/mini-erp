<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DocumentSequence;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderHistory;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\DeliveryNoteManagementService;
use App\Services\DocumentSequenceManagementService;
use App\Services\SalesOrderManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DeliveryNoteManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_delivery_note_permissions_and_sequence_are_enforced(): void
    {
        $this->get(route('sales.delivery-notes.index'))->assertRedirect(route('login'));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $warehouse = Warehouse::query()->create(['code' => 'WH-ACCESS', 'name' => 'Dépôt accès', 'is_active' => true]);
        $this->createSequence('order', 'CMD');
        $this->createSequence('delivery_note', 'BL');

        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update']));
        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product)]);
        $order = app(SalesOrderManagementService::class)->transition($order, SalesOrder::STATUS_CONFIRMED);

        $service = app(DeliveryNoteManagementService::class);
        $note = $service->createForOrder($order, ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString(), 'notes' => 'Livraison de test']);

        $this->actingAs($this->createUserWithPermissions([]));
        $this->get(route('sales.delivery-notes.index'))->assertForbidden();
        $this->get(route('sales.delivery-notes.show', $note))->assertForbidden();

        $this->actingAs($this->createUserWithPermissions(['sales.view']));
        $this->get(route('dashboard'))->assertOk()->assertSee('Bons de livraison');
        $this->get(route('sales.delivery-notes.index'))->assertOk();
        $this->get(route('sales.delivery-notes.show', $note))->assertOk();

        $this->assertSame('BL-'.today()->year.'-00001', $note->number);
        $this->assertSame(1, DocumentSequence::query()->where('document_type', 'delivery_note')->value('counter'));
    }

    public function test_delivery_note_validation_updates_stock_and_order_status_only_once(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.delete', 'sales.view']));
        $customer = $this->createCustomer();
        $product = $this->createProduct(['reference' => 'PRD-BL-01', 'name' => 'Bureau livraison']);
        $warehouse = Warehouse::query()->create(['code' => 'WH-BL', 'name' => 'Dépôt BL', 'is_active' => true]);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '10.000']);
        $this->createSequence('order', 'CMD');
        $this->createSequence('delivery_note', 'BL');
        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '5.000')]);
        $order = app(SalesOrderManagementService::class)->transition($order, SalesOrder::STATUS_CONFIRMED);

        $service = app(DeliveryNoteManagementService::class);
        $note = $service->createForOrder($order, ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString()]);

        $this->assertSame('draft', $note->status);
        $this->assertSame('5.000', $note->items->first()->quantity);
        $this->assertSame('10.000', WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));

        $validated = $service->validate($note);
        $this->assertSame('validated', $validated->status);
        $this->assertSame('5.000', $order->fresh()->items->first()->delivered_quantity);
        $this->assertSame(SalesOrder::STATUS_DELIVERED, $order->fresh()->status);
        $this->assertSame('5.000', WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));

        $movementCount = StockMovement::query()->count();
        $this->assertSame(1, $movementCount);

        $movement = StockMovement::query()->firstOrFail();

        $this->assertSame('BL-'.today()->year.'-00001', $movement->reference);

        $this->assertSame(
            'Livraison client via '.$note->number.' / '.$order->number,
            $movement->notes
        );

        $this->assertStringNotContainsString('CMD-CMD', $movement->notes);
        $this->assertSame('delivery_note_validated', SalesOrderHistory::query()->where('sales_order_id', $order->id)->latest('created_at')->first()->event);

        try {
            $service->validate($note);
            $secondValidationAllowed = true;
        } catch (ValidationException) {
            $secondValidationAllowed = false;
        }
        $this->assertFalse($secondValidationAllowed);
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame('5.000', $order->fresh()->items->first()->delivered_quantity);
    }

    public function test_order_can_start_delivery_note_flow_and_pdf_route_is_available(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.view', 'sales.delete']));
        $customer = $this->createCustomer();
        $product = $this->createProduct(['reference' => 'PRD-BL-02', 'name' => 'Produit livraison']);
        $warehouse = Warehouse::query()->create(['code' => 'WH-FLOW', 'name' => 'Dépôt flow', 'is_active' => true]);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '20.000']);
        $this->createSequence('order', 'CMD');
        $this->createSequence('delivery_note', 'BL');

        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '3.000')]);
        $order = app(SalesOrderManagementService::class)->transition($order, SalesOrder::STATUS_CONFIRMED);

        $this->get(route('sales.orders.show', $order))->assertOk()->assertSee('Créer un bon de livraison');
        $this->get(route('sales.orders.delivery-notes.create', $order))->assertOk();

        $note = app(DeliveryNoteManagementService::class)->createForOrder($order, ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString()]);

        $this->get(route('sales.delivery-notes.show', $note))->assertOk();
        $this->get(route('sales.delivery-notes.pdf', $note))->assertOk();
    }

    public function test_delivery_note_numbering_and_preview_do_not_consume_numbers(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Administrateur']);
        foreach (['settings.manage', 'sales.create', 'sales.update', 'sales.view', 'sales.delete'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $admin->roles()->syncWithoutDetaching([$role->id]);
        $this->actingAs($admin);

        $customer = $this->createCustomer();
        $product = $this->createProduct(['reference' => 'PRD-BL-03', 'name' => 'Produit numérotation']);
        $warehouse = Warehouse::query()->create(['code' => 'WH-NUM', 'name' => 'Dépôt numérotation', 'is_active' => true]);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '20.000']);
        $this->createSequence('order', 'CMD');
        $this->createSequence('delivery_note', 'BL');

        $preview = app(DocumentSequenceManagementService::class)->preview([
            'document_type' => 'delivery_note',
            'prefix' => 'BL',
            'year' => today()->year,
            'counter' => 0,
            'number_format' => '{prefix}-{year}-{counter:05d}',
        ]);
        $this->assertSame('BL-'.today()->year.'-00001', $preview);

        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '2.000')]);
        $order = app(SalesOrderManagementService::class)->transition($order, SalesOrder::STATUS_CONFIRMED);

        $note = app(DeliveryNoteManagementService::class)->createForOrder($order, ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString()]);
        $this->assertSame('BL-'.today()->year.'-00001', $note->number);

        $secondOrder = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '1.000')]);
        $secondOrder = app(SalesOrderManagementService::class)->transition($secondOrder, SalesOrder::STATUS_CONFIRMED);
        $secondNote = app(DeliveryNoteManagementService::class)->createForOrder($secondOrder, ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString()]);
        $this->assertSame('BL-'.today()->year.'-00002', $secondNote->number);
    }

    public function test_delivery_note_sequence_rejects_wrong_prefix_configuration_before_number_is_allocated(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.view', 'sales.delete']));
        $this->createSequence('delivery_note', 'DEV');

        $this->expectException(ValidationException::class);
        app(DocumentSequenceManagementService::class)->allocate('delivery_note', today()->year);
    }

    public function test_existing_draft_delivery_note_is_reused_and_duplicate_validation_is_rejected_after_first_validation(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.view', 'sales.delete']));
        $customer = $this->createCustomer();
        $product = $this->createProduct(['reference' => 'PRD-BL-dup', 'name' => 'Produit doublon']);
        $warehouse = Warehouse::query()->create(['code' => 'WH-DUP', 'name' => 'Dépôt doublon', 'is_active' => true]);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '10.000']);
        $this->createSequence('order', 'CMD');
        $this->createSequence('delivery_note', 'BL');

        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '2.000')]);
        $order = app(SalesOrderManagementService::class)->transition($order, SalesOrder::STATUS_CONFIRMED);

        $firstDraft = app(DeliveryNoteManagementService::class)->createForOrder($order, ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString()]);
        $secondDraft = app(DeliveryNoteManagementService::class)->createForOrder($order, ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString()]);
        $this->assertSame($firstDraft->id, $secondDraft->id);
        $this->assertSame(1, DeliveryNote::query()->where('sales_order_id', $order->id)->where('status', 'draft')->count());

        $firstDraft = app(DeliveryNoteManagementService::class)->updateDraft($firstDraft, [
            'warehouse_id' => $warehouse->id,
            'delivery_date' => today()->toDateString(),
            'items' => [[
                'sales_order_item_id' => $order->items->first()->id,
                'quantity' => '2.000',
            ]],
        ]);

        app(DeliveryNoteManagementService::class)->validate($firstDraft);
        $this->assertSame('2.000', $order->fresh()->items->first()->delivered_quantity);

        $legacyDraft = DeliveryNote::query()->create([
            'sales_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'delivery_date' => today()->toDateString(),
            'number' => 'BL-'.today()->year.'-00099',
            'created_by' => auth()->id(),
            'notes' => 'Ancien brouillon de reprise',
        ]);
        $legacyDraft->items()->create([
            'sales_order_item_id' => $order->items->first()->id,
            'product_id' => $product->id,
            'item_type' => 'product',
            'reference' => $product->reference,
            'description' => $product->name,
            'unit_label' => 'u',
            'quantity' => '2.000',
            'position' => 1,
        ]);

        $this->expectException(ValidationException::class);
        app(DeliveryNoteManagementService::class)->validate($legacyDraft);
    }

    public function test_delivery_note_creation_rejects_invalid_order_status_and_partial_delivery_context(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.view', 'sales.delete']));
        $customer = $this->createCustomer();
        $product = $this->createProduct(['reference' => 'PRD-BL-04', 'name' => 'Produit statut']);
        $warehouse = Warehouse::query()->create(['code' => 'WH-STAT', 'name' => 'Dépôt statut', 'is_active' => true]);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '10.000']);
        $this->createSequence('order', 'CMD');
        $this->createSequence('delivery_note', 'BL');

        $draftOrder = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '2.000')]);
        $this->expectException(ValidationException::class);
        app(DeliveryNoteManagementService::class)->createForOrder($draftOrder, ['warehouse_id' => $warehouse->id]);

        $confirmedOrder = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '2.000')]);
        $confirmedOrder = app(SalesOrderManagementService::class)->transition($confirmedOrder, SalesOrder::STATUS_CONFIRMED);
        $this->assertNotNull(app(DeliveryNoteManagementService::class)->createForOrder($confirmedOrder, ['warehouse_id' => $warehouse->id]));

        $partialOrder = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '4.000')]);
        $partialOrder = app(SalesOrderManagementService::class)->transition($partialOrder, SalesOrder::STATUS_CONFIRMED);
        $partialNote = app(DeliveryNoteManagementService::class)->createForOrder($partialOrder, ['warehouse_id' => $warehouse->id]);
        app(DeliveryNoteManagementService::class)->validate($partialNote);
        $partialOrder->refresh();
        $this->assertSame(SalesOrder::STATUS_PARTIALLY_DELIVERED, $partialOrder->status);

        $cancelledOrder = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '1.000')]);
        $cancelledOrder = app(SalesOrderManagementService::class)->transition($cancelledOrder, SalesOrder::STATUS_CANCELLED);
        $this->expectException(ValidationException::class);
        app(DeliveryNoteManagementService::class)->createForOrder($cancelledOrder, ['warehouse_id' => $warehouse->id]);
    }

    public function test_delivery_note_draft_actions_do_not_touch_stock_or_delivered_quantity(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.view', 'sales.delete']));
        $customer = $this->createCustomer();
        $product = $this->createProduct(['reference' => 'PRD-BL-05', 'name' => 'Produit brouillon']);
        $warehouse = Warehouse::query()->create(['code' => 'WH-DRAFT', 'name' => 'Dépôt brouillon', 'is_active' => true]);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '10.000']);
        $this->createSequence('order', 'CMD');
        $this->createSequence('delivery_note', 'BL');

        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '3.000')]);
        $order = app(SalesOrderManagementService::class)->transition($order, SalesOrder::STATUS_CONFIRMED);

        $note = app(DeliveryNoteManagementService::class)->createForOrder($order, ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString()]);
        $this->assertSame('10.000', WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame('0.000', $order->fresh()->items->first()->delivered_quantity);
        $this->assertSame(0, StockMovement::query()->count());

        $note = app(DeliveryNoteManagementService::class)->updateDraft($note, [
            'warehouse_id' => $warehouse->id,
            'delivery_date' => today()->toDateString(),
            'notes' => 'Mise à jour',
            'items' => [[
                'sales_order_item_id' => $order->items->first()->id,
                'quantity' => '2.000',
            ]],
        ]);
        $this->assertSame('2.000', $note->items->first()->quantity);
        $this->assertSame('10.000', WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame('0.000', $order->fresh()->items->first()->delivered_quantity);

        $cancelled = app(DeliveryNoteManagementService::class)->cancelDraft($note);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('10.000', WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame('0.000', $order->fresh()->items->first()->delivered_quantity);
    }

    public function test_livewire_delivery_note_saves_entered_quantity_and_allows_safe_draft_edit(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.view', 'sales.delete']));
        $customer = $this->createCustomer();
        $product = $this->createProduct(['reference' => 'MOB-BUR-001', 'name' => 'Mobilier test']);
        $warehouse = Warehouse::query()->create(['code' => 'DEP-RABAT', 'name' => 'Dépôt Rabat', 'is_active' => true]);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '7.000']);
        $this->createSequence('order', 'CMD');
        $sequence = $this->createSequence('delivery_note', 'BL');

        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '2.000')]);
        $order = app(SalesOrderManagementService::class)->transition($order, SalesOrder::STATUS_CONFIRMED);
        $orderItem = $order->items->first();

        $form = Volt::test('admin.delivery-note-form', ['orderId' => $order->id])
            ->set('lines.'.$orderItem->id.'.quantity', '1.000')
            ->call('save')
            ->assertHasErrors(['warehouse_id']);

        $form->set('warehouseId', (string) $warehouse->id)
            ->assertHasNoErrors(['warehouse_id'])
            ->call('save')
            ->assertHasNoErrors();

        $note = DeliveryNote::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame('1.000', $note->items()->firstOrFail()->quantity);
        $this->assertSame('7.000', WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame('0.000', $order->fresh()->items->first()->delivered_quantity);
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $order->fresh()->status);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(1, $sequence->fresh()->counter);

        Volt::test('admin.delivery-note-form', ['noteId' => $note->id, 'orderId' => $order->id])
            ->set('lines.'.$orderItem->id.'.quantity', '0.750')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0.750', $note->items()->firstOrFail()->fresh()->quantity);
        $this->assertSame('7.000', WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame('0.000', $order->fresh()->items->first()->delivered_quantity);
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $order->fresh()->status);
        $this->assertSame(0, StockMovement::query()->count());

        try {
            app(DeliveryNoteManagementService::class)->updateDraft($note->fresh(), [
                'warehouse_id' => $warehouse->id,
                'items' => [['sales_order_item_id' => $orderItem->id, 'quantity' => '3.000']],
            ]);
            $this->fail('A quantity above the remaining order quantity must be rejected.');
        } catch (ValidationException) {
            $this->assertSame('0.750', $note->items()->firstOrFail()->fresh()->quantity);
            $this->assertSame('7.000', WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
            $this->assertSame('0.000', $order->fresh()->items->first()->delivered_quantity);
            $this->assertSame(SalesOrder::STATUS_CONFIRMED, $order->fresh()->status);
            $this->assertSame(0, StockMovement::query()->count());
        }
    }

    public function test_delivery_note_mixed_product_and_service_validation_updates_stock_and_order_status(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.view', 'sales.delete']));
        $customer = $this->createCustomer();
        $product = $this->createProduct(['reference' => 'PRD-BL-06', 'name' => 'Produit mixte']);
        $service = $this->createProduct(['type' => 'service', 'reference' => 'SRV-BL-06', 'name' => 'Service mixte']);
        $warehouse = Warehouse::query()->create(['code' => 'WH-MIX', 'name' => 'Dépôt mixte', 'is_active' => true]);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '10.000']);
        $this->createSequence('order', 'CMD');
        $this->createSequence('delivery_note', 'BL');

        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [
            $this->line($product, '2.000'),
            $this->serviceLine($service, '3.000'),
        ]);
        $order = app(SalesOrderManagementService::class)->transition($order, SalesOrder::STATUS_CONFIRMED);
        $note = app(DeliveryNoteManagementService::class)->createForOrder($order, ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString()]);

        $note = app(DeliveryNoteManagementService::class)->updateDraft($note, [
            'warehouse_id' => $warehouse->id,
            'delivery_date' => today()->toDateString(),
            'items' => [
                ['sales_order_item_id' => $order->items->first()->id, 'quantity' => '1.000'],
                ['sales_order_item_id' => $order->items->last()->id, 'quantity' => '2.000'],
            ],
        ]);

        $validated = app(DeliveryNoteManagementService::class)->validate($note);
        $this->assertSame('validated', $validated->status);
        $this->assertSame('1.000', $order->fresh()->items->first()->delivered_quantity);
        $this->assertSame('2.000', $order->fresh()->items->last()->delivered_quantity);
        $this->assertSame('9.000', WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(SalesOrder::STATUS_PARTIALLY_DELIVERED, $order->fresh()->status);
        $this->assertSame(1, StockMovement::query()->where('product_id', $product->id)->count());

        $secondNote = app(DeliveryNoteManagementService::class)->createForOrder($order->fresh(), ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString()]);
        $secondNote = app(DeliveryNoteManagementService::class)->updateDraft($secondNote, [
            'warehouse_id' => $warehouse->id,
            'delivery_date' => today()->toDateString(),
            'items' => [
                ['sales_order_item_id' => $order->fresh()->items->first()->id, 'quantity' => '1.000'],
                ['sales_order_item_id' => $order->fresh()->items->last()->id, 'quantity' => '1.000'],
            ],
        ]);
        $validatedSecond = app(DeliveryNoteManagementService::class)->validate($secondNote);
        $this->assertSame('validated', $validatedSecond->status);
        $this->assertSame(SalesOrder::STATUS_DELIVERED, $order->fresh()->status);
        $this->assertSame('2.000', $order->fresh()->items->first()->delivered_quantity);
        $this->assertSame('3.000', $order->fresh()->items->last()->delivered_quantity);
    }

    public function test_delivery_note_validation_is_atomic_and_rejects_insufficient_stock_without_changes(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.view', 'sales.delete']));
        $customer = $this->createCustomer();
        $product = $this->createProduct(['reference' => 'PRD-BL-07', 'name' => 'Produit insuffisant']);
        $warehouse = Warehouse::query()->create(['code' => 'WH-LOW', 'name' => 'Dépôt insuffisant', 'is_active' => true]);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '3.000']);
        $this->createSequence('order', 'CMD');
        $this->createSequence('delivery_note', 'BL');

        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '5.000')]);
        $order = app(SalesOrderManagementService::class)->transition($order, SalesOrder::STATUS_CONFIRMED);
        $note = app(DeliveryNoteManagementService::class)->createForOrder($order, ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString()]);

        $stockBefore = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity');
        $deliveredBefore = $order->fresh()->items->first()->delivered_quantity;
        $movementCountBefore = StockMovement::query()->count();

        try {
            app(DeliveryNoteManagementService::class)->validate($note);
            $this->fail('Validation should fail when stock is insufficient.');
        } catch (ValidationException) {
            $this->assertSame($stockBefore, WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
            $this->assertSame($deliveredBefore, $order->fresh()->items->first()->delivered_quantity);
            $this->assertSame($movementCountBefore, StockMovement::query()->count());
            $this->assertSame('draft', $note->fresh()->status);
        }
    }

    public function test_delivery_note_pdf_response_is_protected_and_headers_are_pdf_like(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.view', 'sales.delete']));
        $customer = $this->createCustomer();
        $product = $this->createProduct(['reference' => 'PRD-BL-08', 'name' => 'Produit PDF']);
        $warehouse = Warehouse::query()->create(['code' => 'WH-PDF', 'name' => 'Dépôt PDF', 'is_active' => true]);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '10.000']);
        $this->createSequence('order', 'CMD');
        $this->createSequence('delivery_note', 'BL');

        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product, '2.000')]);
        $order = app(SalesOrderManagementService::class)->transition($order, SalesOrder::STATUS_CONFIRMED);
        $note = app(DeliveryNoteManagementService::class)->createForOrder($order, ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString()]);

        $response = $this->get(route('sales.delivery-notes.pdf', $note));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $content = $response->getContent();
        $this->assertStringStartsWith('%PDF', $content);

        $this->actingAs($this->createUserWithPermissions([]));
        $this->get(route('sales.delivery-notes.pdf', $note))->assertForbidden();
    }

    private function createCustomer(array $attributes = []): Customer
    {
        return Customer::query()->forceCreate(array_merge([
            'code' => 'CLI-'.str()->upper(str()->random(8)),
            'customer_type' => 'company',
            'name' => 'Client de test',
            'status' => 'active',
        ], $attributes));
    }

    private function createProduct(array $attributes = []): Product
    {
        $unit = Unit::query()->firstOrCreate(['symbol' => 'pce'], ['name' => 'Pièce']);

        return Product::query()->forceCreate(array_merge([
            'type' => 'product',
            'reference' => 'PRD-'.str()->upper(str()->random(8)),
            'name' => 'Produit de test',
            'unit_id' => $unit->id,
            'purchase_price' => '0.00',
            'selling_price' => '100.00',
            'is_active' => true,
        ], $attributes));
    }

    private function createSequence(string $type, string $prefix): DocumentSequence
    {
        return DocumentSequence::query()->create([
            'document_type' => $type,
            'prefix' => $prefix,
            'year' => today()->year,
            'counter' => 0,
            'number_format' => '{prefix}-{year}-{counter:05d}',
        ]);
    }

    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'Delivery test '.$user->id, 'slug' => 'delivery-test-'.$user->id]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }

    private function orderAttributes(Customer $customer, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customer->id,
            'order_date' => today()->toDateString(),
            'terms' => 'Paiement à réception.',
            'notes' => null,
        ], $overrides);
    }

    private function line(Product $product, string $quantity = '1.000', string $price = '100.00', string $discount = '0.00', ?int $taxRateId = null): array
    {
        return [
            'product_id' => $product->id,
            'ordered_quantity' => $quantity,
            'unit_price' => $price,
            'discount_percent' => $discount,
            'tax_rate_id' => $taxRateId,
        ];
    }

    private function serviceLine(Product $product, string $quantity = '1.000', string $price = '80.00', string $discount = '0.00', ?int $taxRateId = null): array
    {
        return [
            'product_id' => $product->id,
            'ordered_quantity' => $quantity,
            'unit_price' => $price,
            'discount_percent' => $discount,
            'tax_rate_id' => $taxRateId,
        ];
    }
}
