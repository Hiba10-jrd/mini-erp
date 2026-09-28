<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockInventory;
use App\Models\StockInventoryLine;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\InventoryManagementService;
use App\Services\StockManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use LogicException;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_inventory_routes_require_authentication_and_stock_view_permission(): void
    {
        $this->get(route('admin.inventories.index'))->assertRedirect(route('login'));
        $unauthorized = $this->createUserWithPermissions([]);
        $this->actingAs($unauthorized)->get(route('admin.inventories.index'))->assertForbidden();

        $manager = $this->createUserWithPermissions(['stock.manage']);
        $this->actingAs($manager);
        $inventory = $this->createInventory();
        $viewer = $this->createUserWithPermissions(['stock.view']);

        $this->actingAs($viewer)
            ->get(route('admin.inventories.index'))
            ->assertOk()
            ->assertSeeVolt('admin.inventories-manager')
            ->assertDontSee('Créer et compter');
        $this->actingAs($viewer)
            ->get(route('admin.inventories.show', $inventory))
            ->assertOk()
            ->assertSee($inventory->reference)
            ->assertSeeVolt('admin.inventory-details')
            ->assertDontSee('VALIDER L’INVENTAIRE');
    }

    public function test_direct_livewire_and_service_mutations_require_stock_manage(): void
    {
        $manager = $this->createUserWithPermissions(['stock.manage']);
        $this->actingAs($manager);
        $inventory = $this->createInventory();
        $line = $inventory->lines->firstOrFail();
        $viewer = $this->createUserWithPermissions(['stock.view']);
        $this->actingAs($viewer);

        Volt::test('admin.inventory-details', ['inventoryId' => $inventory->id])
            ->set("actualQuantities.{$line->id}", '2.000')
            ->call('saveLine', $line->id)
            ->assertForbidden();

        try {
            app(InventoryManagementService::class)->saveLine($inventory->id, $line->id, '2.000');
            $serviceWasDenied = false;
        } catch (AuthorizationException) {
            $serviceWasDenied = true;
        }

        $this->assertTrue($serviceWasDenied);
        $this->assertNull($line->fresh()->actual_quantity);
    }

    public function test_creation_generates_unique_reference_and_frozen_lines_for_all_active_physical_products(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $warehouse = $this->createWarehouse();
        $stocked = $this->createProduct(['reference' => 'STOCK-17']);
        $zero = $this->createProduct(['reference' => 'ZERO-0']);
        $serviceProduct = $this->createProduct(['type' => 'service', 'reference' => 'SERVICE']);
        $inactive = $this->createProduct(['reference' => 'INACTIVE']);
        $inactive->forceFill(['is_active' => false])->save();
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $stocked->id, 'quantity' => '17.000']);
        $service = app(InventoryManagementService::class);

        $first = $service->create($warehouse->id, 'Comptage annuel');
        $second = $service->create($warehouse->id);

        $this->assertSame('INV-'.now()->format('Y').'-00001', $first->reference);
        $this->assertSame('INV-'.now()->format('Y').'-00002', $second->reference);
        $this->assertNotSame($first->reference, $second->reference);
        $this->assertSame('17.000', $first->lines->firstWhere('product_id', $stocked->id)->theoretical_quantity);
        $this->assertSame('0.000', $first->lines->firstWhere('product_id', $zero->id)->theoretical_quantity);
        $this->assertNull($first->lines->firstWhere('product_id', $serviceProduct->id));
        $this->assertNull($first->lines->firstWhere('product_id', $inactive->id));
        $this->assertSame('Comptage annuel', $first->notes);
    }

    public function test_snapshot_remains_unchanged_after_a_later_stock_movement(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $warehouse = $this->createWarehouse();
        $product = $this->createProduct();
        app(StockManagementService::class)->receive($product->id, $warehouse->id, '17.000');
        $inventory = app(InventoryManagementService::class)->create($warehouse->id);

        app(StockManagementService::class)->issue($product->id, $warehouse->id, '2.000');

        $this->assertSame('17.000', $inventory->lines->firstOrFail()->fresh()->theoretical_quantity);
        $this->assertSame('15.000', WarehouseStock::query()->where('product_id', $product->id)->value('quantity'));
    }

    public function test_actual_quantity_is_saved_line_by_line_and_difference_is_calculated_server_side(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $warehouse = $this->createWarehouse();
        $product = $this->createProduct();
        app(StockManagementService::class)->receive($product->id, $warehouse->id, '17.000');
        $inventory = app(InventoryManagementService::class)->create($warehouse->id);
        $line = $inventory->lines->firstOrFail();

        $saved = app(InventoryManagementService::class)->saveLine($inventory->id, $line->id, '15.250', 'Rayon A');

        $this->assertSame('15.250', $saved->actual_quantity);
        $this->assertSame('-1.750', $saved->difference);
        $this->assertSame('Rayon A', $saved->notes);
        $this->assertSame(StockInventory::STATUS_IN_PROGRESS, $inventory->fresh()->status);
        $this->assertSame('17.000', $saved->theoretical_quantity);
    }

    public function test_validation_generates_only_required_corrections_with_complete_audit(): void
    {
        $user = $this->createUserWithPermissions(['stock.manage']);
        $this->actingAs($user);
        $warehouse = $this->createWarehouse();
        $surplus = $this->createProduct(['reference' => 'SURPLUS']);
        $shortage = $this->createProduct(['reference' => 'SHORTAGE']);
        $matching = $this->createProduct(['reference' => 'MATCHING']);
        $stockService = app(StockManagementService::class);
        foreach ([$surplus, $shortage, $matching] as $product) {
            $stockService->receive($product->id, $warehouse->id, '10.000');
        }
        $inventoryService = app(InventoryManagementService::class);
        $inventory = $inventoryService->create($warehouse->id);
        $lines = $inventory->lines->keyBy('product_id');
        $inventoryService->saveLine($inventory->id, $lines[$surplus->id]->id, '12.000');
        $inventoryService->saveLine($inventory->id, $lines[$shortage->id]->id, '8.000');
        $inventoryService->saveLine($inventory->id, $lines[$matching->id]->id, '10.000');
        $movementCountBefore = $this->stockMovementCount();

        $validated = $inventoryService->validate($inventory->id);

        $this->assertSame(StockInventory::STATUS_VALIDATED, $validated->status);
        $this->assertSame($user->id, $validated->validated_by);
        $this->assertNotNull($validated->validated_at);
        $this->assertSame($movementCountBefore + 2, $this->stockMovementCount());
        $this->assertMovement($inventory->reference, $surplus->id, 'adjustment_positive', '2.000', '10.000', '12.000');
        $this->assertMovement($inventory->reference, $shortage->id, 'adjustment_negative', '2.000', '10.000', '8.000');
        $this->assertDatabaseMissing('stock_movements', [
            'reference' => $inventory->reference,
            'product_id' => $matching->id,
        ]);
        $this->assertStock($warehouse, $surplus, '12.000');
        $this->assertStock($warehouse, $shortage, '8.000');
        $this->assertStock($warehouse, $matching, '10.000');
    }

    public function test_intermediate_movement_is_reconciled_against_current_locked_stock(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $warehouse = $this->createWarehouse();
        $product = $this->createProduct(['reference' => 'CONCURRENT']);
        $stockService = app(StockManagementService::class);
        $stockService->receive($product->id, $warehouse->id, '17.000');
        $inventoryService = app(InventoryManagementService::class);
        $inventory = $inventoryService->create($warehouse->id);
        $line = $inventory->lines->firstOrFail();
        $stockService->issue($product->id, $warehouse->id, '2.000', 'SORTIE-INTERMEDIAIRE');
        $inventoryService->saveLine($inventory->id, $line->id, '16.000');

        $inventoryService->validate($inventory->id);

        $this->assertSame('17.000', $line->fresh()->theoretical_quantity);
        $this->assertSame('-1.000', $line->fresh()->difference);
        $this->assertMovement($inventory->reference, $product->id, 'adjustment_positive', '1.000', '15.000', '16.000');
        $this->assertStock($warehouse, $product, '16.000');
    }

    public function test_validation_requires_every_actual_quantity_and_is_idempotent(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $warehouse = $this->createWarehouse();
        $firstProduct = $this->createProduct(['reference' => 'FIRST']);
        $secondProduct = $this->createProduct(['reference' => 'SECOND']);
        $service = app(InventoryManagementService::class);
        $inventory = $service->create($warehouse->id);
        $lines = $inventory->lines->keyBy('product_id');
        $service->saveLine($inventory->id, $lines[$firstProduct->id]->id, '1.000');

        $this->assertValidationRejected(fn () => $service->validate($inventory->id));
        $service->saveLine($inventory->id, $lines[$secondProduct->id]->id, '0.000');
        $service->validate($inventory->id);
        $movementCount = $this->stockMovementCount();

        $this->assertValidationRejected(fn () => $service->validate($inventory->id));
        $this->assertSame($movementCount, $this->stockMovementCount());
    }

    public function test_validated_inventory_and_lines_are_immutable(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $inventory = $this->createInventory();
        $line = $inventory->lines->firstOrFail();
        $service = app(InventoryManagementService::class);
        $service->saveLine($inventory->id, $line->id, '0.000');
        $service->validate($inventory->id);

        $this->assertValidationRejected(fn () => $service->saveLine($inventory->id, $line->id, '1.000'));
        $this->assertLogicRejected(function () use ($line): void {
            $line->fresh()->forceFill(['actual_quantity' => '2.000'])->save();
        });
        $this->assertLogicRejected(function () use ($inventory): void {
            $inventory->fresh()->forceFill(['status' => StockInventory::STATUS_DRAFT])->save();
        });
        $newProduct = $this->createProduct(['reference' => 'TOO-LATE']);
        $this->assertLogicRejected(fn () => StockInventoryLine::query()->create([
            'stock_inventory_id' => $inventory->id,
            'product_id' => $newProduct->id,
            'theoretical_quantity' => '0.000',
        ]));
        $this->assertLogicRejected(fn () => $inventory->fresh()->delete());
        $this->assertLogicRejected(fn () => $line->fresh()->delete());
    }

    public function test_cancellation_is_allowed_only_before_validation_and_creates_no_movement(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $service = app(InventoryManagementService::class);
        $cancelled = $this->createInventory();
        $lineCount = $cancelled->lines->count();
        $movementCount = $this->stockMovementCount();

        $service->cancel($cancelled->id);

        $this->assertSame(StockInventory::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertSame($lineCount, $cancelled->fresh()->lines()->count());
        $this->assertSame($movementCount, $this->stockMovementCount());
        $this->assertValidationRejected(fn () => $service->cancel($cancelled->id));

        $validated = $this->createInventory();
        foreach ($validated->lines as $line) {
            $service->saveLine($validated->id, $line->id, '0.000');
        }
        $service->validate($validated->id);
        $this->assertValidationRejected(fn () => $service->cancel($validated->id));
    }

    public function test_inactive_warehouse_cannot_start_inventory_but_existing_inventory_remains_visible(): void
    {
        $manager = $this->createUserWithPermissions(['stock.manage']);
        $this->actingAs($manager);
        $warehouse = $this->createWarehouse();
        $inventory = $this->createInventory($warehouse);
        $warehouse->forceFill(['is_active' => false])->save();

        $this->assertValidationRejected(fn () => app(InventoryManagementService::class)->create($warehouse->id));
        Volt::test('admin.inventories-manager')
            ->set('warehouseId', (string) $warehouse->id)
            ->call('createInventory')
            ->assertHasErrors(['warehouseId']);

        $viewer = $this->createUserWithPermissions(['stock.view']);
        $this->actingAs($viewer)
            ->get(route('admin.inventories.show', $inventory))
            ->assertOk()
            ->assertSee($inventory->reference)
            ->assertSee('actuellement inactif');
    }

    public function test_livewire_validation_dispatches_stock_events_and_history_shows_inventory_reference(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));
        $warehouse = $this->createWarehouse();
        $product = $this->createProduct(['reference' => 'EVENT-PRODUCT']);
        $inventory = app(InventoryManagementService::class)->create($warehouse->id);
        $line = $inventory->lines->firstOrFail();

        $component = Volt::test('admin.inventory-details', ['inventoryId' => $inventory->id])
            ->set("actualQuantities.{$line->id}", '3.000')
            ->call('saveLine', $line->id)
            ->assertHasNoErrors()
            ->call('validateInventory')
            ->assertHasNoErrors()
            ->assertDispatched('stock-updated', productId: $product->id);

        $component->assertDontSee('VALIDER L’INVENTAIRE');
        Volt::test('admin.stock-movements-history')
            ->set('productFilter', (string) $product->id)
            ->assertSee($inventory->reference)
            ->assertSee('3.000');
    }

    public function test_inventory_list_search_filters_and_pagination_work(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view']));
        $firstWarehouse = $this->createWarehouse(['code' => 'WH-A', 'name' => 'Alpha']);
        $secondWarehouse = $this->createWarehouse(['code' => 'WH-B', 'name' => 'Beta']);

        foreach (range(1, 11) as $index) {
            StockInventory::query()->forceCreate([
                'reference' => sprintf('INV-2026-%05d', $index),
                'warehouse_id' => $index === 11 ? $secondWarehouse->id : $firstWarehouse->id,
                'status' => $index === 11 ? StockInventory::STATUS_CANCELLED : StockInventory::STATUS_DRAFT,
                'started_at' => now(),
            ]);
        }

        Volt::test('admin.inventories-manager')
            ->assertViewHas('inventories', fn ($inventories): bool => $inventories->perPage() === 10 && $inventories->total() === 11)
            ->set('search', '00011')
            ->assertViewHas('inventories', fn ($inventories): bool => $inventories->total() === 1)
            ->set('warehouseFilter', (string) $secondWarehouse->id)
            ->set('statusFilter', StockInventory::STATUS_CANCELLED)
            ->assertViewHas('inventories', fn ($inventories): bool => $inventories->total() === 1);
    }

    public function test_inventory_identifier_is_locked_on_details_component(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));
        $inventory = $this->createInventory();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('admin.inventory-details', ['inventoryId' => $inventory->id])
            ->set('inventoryId', 99999);
    }

    private function createInventory(?Warehouse $warehouse = null): StockInventory
    {
        $warehouse ??= $this->createWarehouse();
        Product::query()->where('type', 'product')->where('is_active', true)->first() ?? $this->createProduct();

        return app(InventoryManagementService::class)->create($warehouse->id);
    }

    /** @param array<string, mixed> $attributes */
    private function createProduct(array $attributes = []): Product
    {
        $unit = Unit::query()->firstOrCreate(['symbol' => 'pce'], ['name' => 'Pièce']);

        return Product::query()->forceCreate(array_merge([
            'type' => 'product',
            'reference' => 'PRD-'.str()->random(8),
            'name' => 'Produit test',
            'unit_id' => $unit->id,
            'purchase_price' => '0.00',
            'selling_price' => '0.00',
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function createWarehouse(array $attributes = []): Warehouse
    {
        return Warehouse::query()->create(array_merge([
            'code' => 'WH-'.str()->random(8),
            'name' => 'Dépôt test',
        ], $attributes));
    }

    /** @param array<int, string> $permissions */
    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'Inventory test '.$user->id,
            'slug' => 'inventory-test-'.$user->id,
        ]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }

    private function stockMovementCount(): int
    {
        return (int) StockMovement::query()->count();
    }

    private function assertStock(Warehouse $warehouse, Product $product, string $quantity): void
    {
        $this->assertSame($quantity, WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity'));
    }

    private function assertMovement(string $reference, int $productId, string $type, string $quantity, string $before, string $after): void
    {
        $movement = StockMovement::query()
            ->where('reference', $reference)
            ->where('product_id', $productId)
            ->where('type', $type)
            ->firstOrFail();
        $this->assertSame($quantity, $movement->quantity);
        $this->assertSame($before, $movement->quantity_before);
        $this->assertSame($after, $movement->quantity_after);
        $this->assertStringContainsString($reference, $movement->notes);
    }

    private function assertValidationRejected(callable $callback): void
    {
        try {
            $callback();
            $rejected = false;
        } catch (ValidationException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
    }

    private function assertLogicRejected(callable $callback): void
    {
        try {
            $callback();
            $rejected = false;
        } catch (LogicException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
    }
}
