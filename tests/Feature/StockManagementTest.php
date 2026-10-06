<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use LogicException;
use Tests\TestCase;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_stock_route_and_navigation_respect_existing_permissions(): void
    {
        $this->get(route('admin.stock.index'))->assertRedirect(route('login'));

        $unauthorized = $this->createUserWithPermissions([]);
        $this->actingAs($unauthorized)->get(route('admin.stock.index'))->assertForbidden();

        $viewer = $this->createUserWithPermissions(['stock.view']);
        $this->actingAs($viewer)
            ->get(route('admin.stock.index'))
            ->assertOk()
            ->assertSeeVolt('admin.stock-operations-manager')
            ->assertSeeVolt('admin.stock-balances')
            ->assertSeeVolt('admin.stock-movements-history')
            ->assertSeeVolt('admin.warehouses-manager')
            ->assertDontSee('Valider l’opération');

        $this->get(route('dashboard'))->assertOk()->assertSee('Stocks et dépôts');
    }

    public function test_viewer_cannot_call_livewire_or_service_mutations_directly(): void
    {
        $viewer = $this->createUserWithPermissions(['stock.view']);
        $product = $this->createProduct();
        $warehouse = $this->createWarehouse();
        $this->actingAs($viewer);

        Volt::test('admin.stock-operations-manager')
            ->set('operationType', 'entry')
            ->set('productId', (string) $product->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('quantity', '2.000')
            ->call('saveMovement')
            ->assertForbidden();

        Volt::test('admin.warehouses-manager')
            ->set('code', 'DENIED')
            ->set('name', 'Denied')
            ->call('saveWarehouse')
            ->assertForbidden();

        try {
            app(StockManagementService::class)->receive($product->id, $warehouse->id, '2.000');
            $serviceWasDenied = false;
        } catch (AuthorizationException) {
            $serviceWasDenied = true;
        }

        $this->assertTrue($serviceWasDenied);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseMissing('warehouses', ['code' => 'DENIED']);
    }

    public function test_warehouses_can_be_created_updated_and_logically_disabled(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));

        Volt::test('admin.warehouses-manager')
            ->set('code', '  casa-01 ')
            ->set('name', 'Dépôt Casablanca')
            ->set('city', 'Casablanca')
            ->call('saveWarehouse')
            ->assertHasNoErrors();

        $warehouse = Warehouse::query()->where('code', 'CASA-01')->firstOrFail();

        Volt::test('admin.warehouses-manager')
            ->call('editWarehouse', $warehouse->id)
            ->set('name', 'Dépôt principal')
            ->call('saveWarehouse')
            ->assertHasNoErrors()
            ->call('toggleWarehouse', $warehouse->id);

        $this->assertSame('Dépôt principal', $warehouse->fresh()->name);
        $this->assertFalse($warehouse->fresh()->is_active);
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
    }

    public function test_warehouse_changes_dispatch_refresh_event_to_dependent_components(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));
        $operations = Volt::test('admin.stock-operations-manager')->assertDontSee('LIVE-WH');
        $balances = Volt::test('admin.stock-balances')->assertDontSee('LIVE-WH');
        $history = Volt::test('admin.stock-movements-history')->assertDontSee('LIVE-WH');
        $manager = Volt::test('admin.warehouses-manager')
            ->set('code', 'LIVE-WH')
            ->set('name', 'Dépôt dynamique')
            ->call('saveWarehouse')
            ->assertHasNoErrors();
        $warehouse = Warehouse::query()->where('code', 'LIVE-WH')->firstOrFail();

        $manager->assertDispatched('warehouse-updated', warehouseId: $warehouse->id);
        $operations->dispatch('warehouse-updated', warehouseId: $warehouse->id)->assertSee('LIVE-WH');
        $balances->dispatch('warehouse-updated', warehouseId: $warehouse->id)->assertSee('LIVE-WH');
        $history->dispatch('warehouse-updated', warehouseId: $warehouse->id)->assertSee('LIVE-WH');

        $manager
            ->call('toggleWarehouse', $warehouse->id)
            ->assertDispatched('warehouse-updated', warehouseId: $warehouse->id);
        $operations->dispatch('warehouse-updated', warehouseId: $warehouse->id)->assertDontSee('LIVE-WH');
        $balances->dispatch('warehouse-updated', warehouseId: $warehouse->id)->assertSee('LIVE-WH');
        $history->dispatch('warehouse-updated', warehouseId: $warehouse->id)->assertSee('LIVE-WH');

        $manager
            ->call('toggleWarehouse', $warehouse->id)
            ->assertDispatched('warehouse-updated', warehouseId: $warehouse->id);
        $operations->dispatch('warehouse-updated', warehouseId: $warehouse->id)->assertSee('LIVE-WH');
    }

    public function test_entry_exit_returns_and_adjustments_keep_exact_balances_and_audit_data(): void
    {
        $user = $this->createUserWithPermissions(['stock.view', 'stock.manage']);
        $this->actingAs($user);
        $product = $this->createProduct();
        $warehouse = $this->createWarehouse();
        $service = app(StockManagementService::class);

        $entry = $service->receive($product->id, $warehouse->id, '10.125', 'REC-1', 'Réception');
        $service->issue($product->id, $warehouse->id, '2.125');
        $service->returnIn($product->id, $warehouse->id, '1.000');
        $service->returnOut($product->id, $warehouse->id, '1.000');
        $service->adjust($product->id, $warehouse->id, 'positive', '3.000', 'Inventaire physique');
        $last = $service->adjust($product->id, $warehouse->id, 'negative', '1.000', 'Casse');

        $this->assertSame('0.000', $entry->quantity_before);
        $this->assertSame('10.125', $entry->quantity_after);
        $this->assertSame($user->id, $entry->performed_by);
        $this->assertSame('10.000', $last->quantity_after);
        $this->assertDatabaseHas('warehouse_stocks', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 10,
        ]);
        $this->assertDatabaseCount('stock_movements', 6);
        foreach (['entry', 'exit', 'return_in', 'return_out', 'adjustment_positive', 'adjustment_negative'] as $type) {
            $this->assertDatabaseHas('stock_movements', ['type' => $type]);
        }
    }

    public function test_stock_can_never_become_negative_and_failed_operation_is_rolled_back(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $product = $this->createProduct();
        $warehouse = $this->createWarehouse();
        $service = app(StockManagementService::class);
        $service->receive($product->id, $warehouse->id, '2.000');

        try {
            $service->issue($product->id, $warehouse->id, '2.001');
            $wasRejected = false;
        } catch (ValidationException) {
            $wasRejected = true;
        }

        $this->assertTrue($wasRejected);
        $this->assertSame('2.000', WarehouseStock::query()->firstOrFail()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_transfer_is_atomic_and_creates_two_linked_immutable_movements(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $product = $this->createProduct();
        $source = $this->createWarehouse(['code' => 'SRC', 'name' => 'Source']);
        $destination = $this->createWarehouse(['code' => 'DST', 'name' => 'Destination']);
        $service = app(StockManagementService::class);
        $service->receive($product->id, $source->id, '8.000');

        $transfer = $service->transfer($product->id, $source->id, $destination->id, '3.250', 'TRF-1');

        $this->assertSame('4.750', $transfer['out']->quantity_after);
        $this->assertSame('3.250', $transfer['in']->quantity_after);
        $this->assertNotNull($transfer['out']->transfer_group_id);
        $this->assertSame($transfer['out']->transfer_group_id, $transfer['in']->transfer_group_id);
        $this->assertSame('transfer_out', $transfer['out']->type);
        $this->assertSame('transfer_in', $transfer['in']->type);
        $this->assertSame('4.750', WarehouseStock::query()->where('warehouse_id', $source->id)->value('quantity'));
        $this->assertSame('3.250', WarehouseStock::query()->where('warehouse_id', $destination->id)->value('quantity'));
    }

    public function test_invalid_transfer_leaves_both_warehouses_unchanged(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $product = $this->createProduct();
        $source = $this->createWarehouse(['code' => 'SRC']);
        $destination = $this->createWarehouse(['code' => 'DST']);
        $service = app(StockManagementService::class);
        $service->receive($product->id, $source->id, '1.000');

        foreach ([[$source->id, $destination->id, '2.000'], [$source->id, $source->id, '1.000']] as [$from, $to, $quantity]) {
            try {
                $service->transfer($product->id, $from, $to, $quantity);
                $wasRejected = false;
            } catch (ValidationException) {
                $wasRejected = true;
            }
            $this->assertTrue($wasRejected);
        }

        $this->assertSame('1.000', WarehouseStock::query()->where('warehouse_id', $source->id)->value('quantity'));
        $this->assertDatabaseMissing('warehouse_stocks', ['warehouse_id' => $destination->id]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_services_inactive_products_and_inactive_warehouses_are_refused(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $serviceProduct = $this->createProduct(['type' => 'service', 'reference' => 'SRV-1']);
        $inactiveProduct = $this->createProduct(['reference' => 'OLD-1']);
        $inactiveProduct->forceFill(['is_active' => false])->save();
        $warehouse = $this->createWarehouse();
        $inactiveWarehouse = $this->createWarehouse(['code' => 'OLD-W']);
        $inactiveWarehouse->forceFill(['is_active' => false])->save();
        $service = app(StockManagementService::class);

        foreach ([[$serviceProduct->id, $warehouse->id], [$inactiveProduct->id, $warehouse->id], [$this->createProduct(['reference' => 'PRD-2'])->id, $inactiveWarehouse->id]] as [$productId, $warehouseId]) {
            try {
                $service->receive($productId, $warehouseId, '1.000');
                $wasRejected = false;
            } catch (ValidationException) {
                $wasRejected = true;
            }
            $this->assertTrue($wasRejected);
        }

        Volt::test('admin.stock-operations-manager')
            ->set('operationType', 'entry')
            ->set('productId', (string) $serviceProduct->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('quantity', '1.000')
            ->call('saveMovement')
            ->assertHasErrors(['productId']);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_adjustment_requires_a_reason_and_at_most_three_decimals(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $product = $this->createProduct();
        $warehouse = $this->createWarehouse();

        Volt::test('admin.stock-operations-manager')
            ->set('operationType', 'adjustment_positive')
            ->set('productId', (string) $product->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('quantity', '1.1234')
            ->call('saveMovement')
            ->assertHasErrors(['quantity', 'notes']);
    }

    public function test_every_successful_operation_dispatches_refresh_event_and_listeners_rerender(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));
        $product = $this->createProduct(['reference' => 'MOB-BUR-001', 'name' => 'Bureau mobile']);
        $source = $this->createWarehouse(['code' => 'DEP-CASA', 'name' => 'Casablanca']);
        $destination = $this->createWarehouse(['code' => 'DEP-RABAT', 'name' => 'Rabat']);
        $balances = Volt::test('admin.stock-balances');
        $history = Volt::test('admin.stock-movements-history')->assertDontSee('TRF-001');
        $details = Volt::test('admin.product-details', ['productId' => $product->id]);
        $operations = [
            ['entry', $source->id, '20.000', 'ENT-001', null, ''],
            ['exit', $source->id, '1.000', 'EXT-001', null, ''],
            ['return_in', $source->id, '1.000', 'RET-IN-001', null, ''],
            ['return_out', $source->id, '1.000', 'RET-OUT-001', null, ''],
            ['adjustment_positive', $source->id, '2.000', 'ADJ-P-001', null, 'Comptage'],
            ['adjustment_negative', $source->id, '1.000', 'ADJ-N-001', null, 'Casse'],
            ['transfer', $source->id, '5.000', 'TRF-001', $destination->id, ''],
        ];

        foreach ($operations as [$type, $warehouseId, $quantity, $reference, $destinationId, $notes]) {
            $component = Volt::test('admin.stock-operations-manager')
                ->set('operationType', $type)
                ->set('productId', (string) $product->id)
                ->set('warehouseId', (string) $warehouseId)
                ->set('quantity', $quantity)
                ->set('reference', $reference)
                ->set('notes', $notes);

            if ($destinationId !== null) {
                $component->set('destinationWarehouseId', (string) $destinationId);
            }

            $component
                ->call('saveMovement')
                ->assertHasNoErrors()
                ->assertDispatched('stock-updated', productId: $product->id);
        }

        $balances
            ->dispatch('stock-updated', productId: $product->id)
            ->assertSee('15,000')
            ->assertSee('5,000')
            ->assertSee('20,000');
        $history
            ->dispatch('stock-updated', productId: $product->id)
            ->assertSee('TRF-001')
            ->assertSee('15.000')
            ->assertSee('5.000');
        $details
            ->dispatch('stock-updated', productId: $product->id)
            ->assertSee('20,000');
    }

    public function test_balances_alerts_history_filters_and_product_total_exclude_services(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));
        $lowProduct = $this->createProduct(['reference' => 'LOW-1', 'name' => 'Produit faible', 'minimum_stock' => '5.000']);
        $emptyProduct = $this->createProduct(['reference' => 'ZERO-1', 'name' => 'Produit vide', 'minimum_stock' => '2.000']);
        $serviceProduct = $this->createProduct(['type' => 'service', 'reference' => 'SRV-1', 'name' => 'Conseil']);
        $warehouse = $this->createWarehouse(['code' => 'CENTRAL', 'name' => 'Central']);
        $secondWarehouse = $this->createWarehouse(['code' => 'ANNEXE', 'name' => 'Annexe']);
        $service = app(StockManagementService::class);
        $service->receive($lowProduct->id, $warehouse->id, '3.000', 'REC-LOW');
        $service->receive($lowProduct->id, $secondWarehouse->id, '4.000', 'REC-ANNEXE');
        $secondWarehouse->forceFill(['is_active' => false])->save();

        Volt::test('admin.stock-balances')
            ->set('alertFilter', 'low')
            ->assertSee('Produit faible')
            ->assertDontSee('Conseil')
            ->assertViewHas('balances', fn ($balances): bool => collect($balances->items())->every(
                fn (Product $product): bool => $product->id === $lowProduct->id
                    && (float) $product->total_stock === 7.0
            ))
            ->set('alertFilter', 'rupture')
            ->assertSee('Produit vide')
            ->assertDontSee('Conseil')
            ->assertViewHas('balances', fn ($balances): bool => collect($balances->items())->every(
                fn (Product $product): bool => $product->id === $emptyProduct->id
            ))
            ->assertViewHas('balances', fn ($balances): bool => $balances->perPage() === 10);

        Volt::test('admin.stock-balances')
            ->set('warehouseFilter', (string) $warehouse->id)
            ->set('productFilter', (string) $lowProduct->id)
            ->assertViewHas('balances', fn ($balances): bool => collect($balances->items())->every(
                fn (Product $product): bool => (float) $product->total_stock === 7.0
            ));

        $this->assertFalse(Schema::hasColumn('warehouse_stocks', 'total_stock'));

        Volt::test('admin.stock-movements-history')
            ->set('warehouseFilter', (string) $warehouse->id)
            ->assertSee('REC-LOW')
            ->assertDontSee('REC-ANNEXE')
            ->assertViewHas('movements', fn ($movements): bool => $movements->perPage() === 15);

        $this->get(route('admin.products.show', $lowProduct))
            ->assertOk()
            ->assertSee('Stock total')
            ->assertSee('7,000')
            ->assertSee('CENTRAL')
            ->assertSee('ANNEXE');

        $this->get(route('admin.products.show', $serviceProduct))
            ->assertOk()
            ->assertSee('Prestation de service')
            ->assertDontSee('Stock par dépôt');
    }

    public function test_validated_movements_cannot_be_updated_or_deleted_through_the_model(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.manage']));
        $movement = app(StockManagementService::class)->receive(
            $this->createProduct()->id,
            $this->createWarehouse()->id,
            '1.000',
        );

        try {
            $movement->forceFill(['notes' => 'Altération'])->save();
            $updateWasBlocked = false;
        } catch (LogicException) {
            $updateWasBlocked = true;
        }

        try {
            $movement->delete();
            $deleteWasBlocked = false;
        } catch (LogicException) {
            $deleteWasBlocked = true;
        }

        $this->assertTrue($updateWasBlocked);
        $this->assertTrue($deleteWasBlocked);
        $this->assertDatabaseHas('stock_movements', ['id' => $movement->id]);
    }

    public function test_super_administrator_keeps_full_stock_access(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());
        $this->actingAs($user)->get(route('admin.stock.index'))->assertOk();

        Volt::test('admin.warehouses-manager')
            ->set('code', 'SUPER')
            ->set('name', 'Super dépôt')
            ->call('saveWarehouse')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('warehouses', ['code' => 'SUPER']);
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
            'name' => 'Stock test '.$user->id,
            'slug' => 'stock-test-'.$user->id,
        ]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }
}
