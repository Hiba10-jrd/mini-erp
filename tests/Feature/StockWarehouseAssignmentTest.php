<?php

namespace Tests\Feature;

use App\Jobs\BroadcastStoredNotification;
use App\Jobs\CheckStockAlerts;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\DashboardService;
use App\Services\InventoryManagementService;
use App\Services\ReportService;
use App\Services\StockManagementService;
use App\Services\StockReportingQuery;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerReminderFixtures;
use Tests\TestCase;

class StockWarehouseAssignmentTest extends TestCase
{
    use CustomerReminderFixtures, RefreshDatabase;

    private Product $product;

    private Warehouse $maarif;

    private Warehouse $casa;

    private Warehouse $rabat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Bus::fake([BroadcastStoredNotification::class]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());
        $this->actingAs($admin);
        $unit = Unit::query()->create(['symbol' => 'pce', 'name' => 'Pièce']);
        $this->product = Product::query()->forceCreate([
            'unit_id' => $unit->id, 'type' => 'product', 'reference' => 'PROD-DEMO-001',
            'name' => 'Ordinateur portable Pro 16', 'minimum_stock' => '3.000', 'is_active' => true,
        ]);
        $this->maarif = Warehouse::query()->create(['code' => 'DEP-MAARIF', 'name' => 'Dépôt Maarif']);
        $this->casa = Warehouse::query()->create(['code' => 'DEP-CASA', 'name' => 'Dépôt Casablanca']);
        $this->rabat = Warehouse::query()->create(['code' => 'DEP-RABAT', 'name' => 'Dépôt Rabat']);
    }

    public function test_unassigned_product_has_no_balance_reporting_row_or_notification(): void
    {
        $this->assertCount(0, app(StockReportingQuery::class)->query()->get());
        Volt::test('admin.stock-balances')->assertViewHas('balances', fn ($rows) => $rows->total() === 0)
            ->assertDontSeeHtml('wire:key="balance-'.$this->product->id.'-'.$this->casa->id.'"');
        Volt::test('admin.product-details', ['productId' => $this->product->id])->assertViewHas('stockRows', fn ($rows) => $rows->isEmpty())->assertViewHas('totalStock', '0');
        $this->assertSame(0, app(ReportService::class)->stockSummary()['Ruptures']);
        app()->call([new CheckStockAlerts, 'handle']);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('warehouse_stocks', 0);
    }

    public function test_only_assigned_warehouses_are_listed_and_global_total_remains_correct(): void
    {
        $service = app(StockManagementService::class);
        $service->receive($this->product->id, $this->maarif->id, '10.000');
        $before = Product::query()->crossJoin('warehouses')->where('products.id', $this->product->id)->count();
        $query = app(StockReportingQuery::class)->query();
        $this->assertStringNotContainsString('cross join', strtolower($query->toSql()));
        $this->assertStringNotContainsString('left join', strtolower($query->toSql()));
        $rows = $query->get();
        $this->assertSame(3, $before);
        $this->assertCount(1, $rows);
        $this->assertSame($this->maarif->id, $rows->first()->warehouse_id);
        $this->assertSame('available', $rows->first()->report_state);
        fwrite(STDOUT, "\nStock pairs for one product / three warehouses: before={$before}, after={$rows->count()}\n");
        Volt::test('admin.stock-balances')->assertViewHas('balances', fn ($rows) => $rows->total() === 1 && (float) $rows->first()->total_stock === 10.0)
            ->assertDontSeeHtml('wire:key="balance-'.$this->product->id.'-'.$this->casa->id.'"')
            ->assertDontSeeHtml('wire:key="balance-'.$this->product->id.'-'.$this->rabat->id.'"');
        Volt::test('admin.product-details', ['productId' => $this->product->id])->assertViewHas('stockRows', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $this->maarif->id)->assertSee('10,000')->assertDontSee('DEP-CASA')->assertDontSee('DEP-RABAT');
        $service->receive($this->product->id, $this->rabat->id, '4.000');
        $this->rabat->forceFill(['is_active' => false])->save();
        $this->assertCount(2, app(StockReportingQuery::class)->query()->get());
        $this->assertSame(14.0, (float) WarehouseStock::query()->where('product_id', $this->product->id)->sum('quantity'));
        Volt::test('admin.stock-balances')->assertViewHas('balances', fn ($rows) => $rows->total() === 2 && $rows->every(fn ($row) => (float) $row->total_stock === 14.0));
        Volt::test('admin.product-details', ['productId' => $this->product->id])->assertViewHas('stockRows', fn ($rows) => $rows->count() === 2)->assertSee('14,000')->assertSee('DEP-RABAT')->assertDontSee('DEP-CASA');
    }

    public function test_emptied_stock_row_is_preserved_and_still_reports_a_real_rupture(): void
    {
        $service = app(StockManagementService::class);
        $service->receive($this->product->id, $this->maarif->id, '10.000');
        $stockId = WarehouseStock::query()->firstOrFail()->id;
        $service->issue($this->product->id, $this->maarif->id, '10.000');
        $this->assertDatabaseHas('warehouse_stocks', ['id' => $stockId, 'quantity' => 0]);
        $this->assertDatabaseCount('warehouse_stocks', 1);
        $rows = app(StockReportingQuery::class)->query()->get();
        $this->assertCount(1, $rows);
        $this->assertSame('rupture', $rows->first()->report_state);
        Volt::test('admin.stock-balances')->set('alertFilter', 'rupture')->assertViewHas('balances', fn ($rows) => $rows->total() === 1 && $rows->first()->warehouse_id === $this->maarif->id)->assertSee('Rupture');
        Volt::test('admin.product-details', ['productId' => $this->product->id])->assertViewHas('stockRows', fn ($rows) => $rows->count() === 1 && (float) $rows->first()->stock_quantity === 0.0);
        $this->assertSame(1, app(ReportService::class)->stockSummary()['Ruptures']);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public static function quantities(): array
    {
        return ['zero' => ['0.000', 'rupture', 'stock.out'], 'low' => ['2.000', 'low', 'stock.low'], 'equal threshold' => ['3.000', 'low', 'stock.low'], 'available' => ['10.000', 'available', null]];
    }

    #[DataProvider('quantities')]
    public function test_states_and_notifications_only_concern_the_real_association(string $quantity, string $state, ?string $type): void
    {
        WarehouseStock::query()->create(['product_id' => $this->product->id, 'warehouse_id' => $this->maarif->id, 'quantity' => $quantity]);
        $rows = app(StockReportingQuery::class)->query()->get();
        $this->assertCount(1, $rows);
        $this->assertSame($state, $rows->first()->report_state);
        app()->call([new CheckStockAlerts, 'handle']);
        app()->call([new CheckStockAlerts, 'handle']);
        $notifications = auth()->user()->notifications()->get();
        $this->assertCount($type === null ? 0 : 1, $notifications);
        if ($type !== null) {
            $this->assertSame($type, $notifications->first()->data['type']);
            $this->assertSame($this->product->id, $notifications->first()->data['entity_id']);
            $this->assertSame('Dépôt Maarif', $notifications->first()->data['params']['warehouse']);
            $this->assertStringContainsString('warehouse='.$this->maarif->id, $notifications->first()->data['url']);
        }
        $this->assertDatabaseMissing('warehouse_stocks', ['warehouse_id' => $this->casa->id]);
        $this->assertDatabaseMissing('warehouse_stocks', ['warehouse_id' => $this->rabat->id]);
    }

    public function test_both_dashboards_count_only_real_zero_balances(): void
    {
        app(StockManagementService::class)->receive($this->product->id, $this->maarif->id, '10.000');
        $admin = auth()->user();
        $viewer = $this->userWithPermissions(['stock.view']);
        foreach ([10, 0] as $quantity) {
            WarehouseStock::query()->update(['quantity' => $quantity]);
            $expected = $quantity === 0 ? 1 : 0;
            $this->actingAs($admin);
            $data = app(DashboardService::class)->read(today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString());
            $this->assertSame($expected, $data['current']['Alertes stock']);
            $this->assertCount($expected, $data['alerts']['stock']);
            $this->actingAs($viewer);
            Volt::test('dashboard-summary')->assertViewHas('cards', fn ($cards) => collect($cards)->firstWhere('label', 'Ruptures de stock')['value'] === $expected)
                ->assertViewHas('alerts', fn ($alerts) => $alerts['Alertes stock']->count() === $expected);
        }
    }

    public function test_inventory_can_find_an_untracked_product_and_validated_zero_becomes_real(): void
    {
        $service = app(InventoryManagementService::class);
        $inventory = $service->create($this->maarif->id);
        $line = $inventory->lines->firstOrFail();
        $this->assertSame('0.000', $line->theoretical_quantity);
        $this->assertDatabaseCount('warehouse_stocks', 0);
        $service->saveLine($inventory->id, $line->id, '4.000');
        $service->validate($inventory->id);
        $this->assertCount(1, app(StockReportingQuery::class)->query()->get());
        $this->assertSame('4.000', WarehouseStock::query()->firstOrFail()->quantity);
        $this->assertDatabaseHas('stock_movements', ['reference' => $inventory->reference, 'quantity_after' => 4]);
        $zeroInventory = $service->create($this->rabat->id);
        $service->saveLine($zeroInventory->id, $zeroInventory->lines->firstOrFail()->id, '0.000');
        $service->validate($zeroInventory->id);
        $rows = app(StockReportingQuery::class)->query()->get();
        $this->assertCount(2, $rows);
        $this->assertSame('rupture', $rows->firstWhere('warehouse_id', $this->rabat->id)->report_state);
        $this->assertSame(1, app(ReportService::class)->stockSummary()['Ruptures']);
        $this->assertDatabaseMissing('warehouse_stocks', ['warehouse_id' => $this->casa->id]);
    }

    public function test_reporting_is_one_query_as_associations_grow(): void
    {
        WarehouseStock::query()->create(['product_id' => $this->product->id, 'warehouse_id' => $this->maarif->id, 'quantity' => 10]);
        $measure = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            app(StockReportingQuery::class)->query()->get();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            return count($queries);
        };
        $this->assertSame(1, $measure());
        WarehouseStock::query()->create(['product_id' => $this->product->id, 'warehouse_id' => $this->rabat->id, 'quantity' => 4]);
        $this->assertSame(1, $measure());
    }
}
