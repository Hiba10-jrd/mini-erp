<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\DashboardService;
use App\Services\GoodsReceiptManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReportFixtures;
use Tests\TestCase;

class DashboardPermissionAwareTest extends TestCase
{
    use RefreshDatabase, ReportFixtures;

    public static function domains(): array
    {
        return [
            'purchases and stock' => [['purchases.view', 'stock.view'], ['Commandes fournisseurs en cours', 'Réceptions brouillon', 'Ruptures de stock', 'Stocks faibles'], ['Chiffre d’affaires HT', 'Créances clients', 'Solde caisse', 'Factures clients']],
            'stock management' => [['stock.manage'], ['Ruptures de stock', 'Inventaires ouverts'], ['Commandes fournisseurs', 'Factures clients']],
            'stock consultation' => [['stock.view'], ['Ruptures de stock', 'Stocks faibles'], ['Encaissements', 'Devis en attente']],
            'purchases consultation' => [['purchases.view'], ['Commandes fournisseurs en cours', 'Commandes à réceptionner'], ['Encaissements', 'Ruptures de stock']],
            'customer management' => [['customers.manage'], ['Clients actifs', 'Clients récents'], ['Créances clients', 'Commandes fournisseurs']],
            'supplier management' => [['suppliers.manage'], ['Fournisseurs actifs', 'Fournisseurs récents'], ['Commandes fournisseurs', 'Créances clients']],
            'sales only' => [['sales.view'], ['Devis en attente', 'Commandes clients'], ['Factures clients', 'Factures récentes', 'Encaissements', 'Stocks faibles']],
            'invoices only' => [['invoices.view'], ['Factures clients', 'Factures émises'], ['Devis en attente', 'Commandes clients', 'Créances clients']],
            'payments only' => [['payments.view'], ['Encaissements', 'Créances clients', 'Factures en retard'], ['Devis en attente', 'Stocks faibles']],
            'commercial combination' => [['sales.view', 'customers.view', 'invoices.view'], ['Clients actifs', 'Devis en attente', 'Factures clients'], ['Commandes fournisseurs', 'Stocks faibles', 'Créances clients']],
            'no useful domain' => [['sales.create'], ['Bienvenue dans votre ERP'], ['Devis en attente', 'Encaissements']],
        ];
    }

    #[DataProvider('domains')]
    public function test_effective_permissions_determine_content(array $permissions, array $visible, array $hidden): void
    {
        $this->invoice();
        $this->supplierInvoice();
        $this->actingAs($this->userWithPermissions($permissions));
        $component = Volt::test('dashboard-summary')->assertViewHas('reporting', false);
        foreach ($visible as $label) {
            $component->assertSee($label);
        }
        foreach ($hidden as $label) {
            $component->assertDontSee($label);
        }
        if ($permissions !== ['sales.create']) {
            $component->assertDontSee('Bienvenue dans votre ERP');
        }
    }

    public function test_super_admin_keeps_complete_reporting(): void
    {
        $this->invoice();
        Volt::test('dashboard-summary')->assertViewHas('reporting', true)->assertSee('Chiffre d’affaires HT')->assertSee('Journal de caisse')->assertSee('Évolution de l’activité')->assertSee('Valeur du stock');
    }

    public function test_reports_reader_keeps_transversal_data_without_module_links(): void
    {
        $this->invoice();
        $this->actingAs($this->userWithPermissions(['reports.view']));
        Volt::test('dashboard-summary')->assertSee('CA net HT')->assertSee('Valeur du stock')
            ->assertViewHas('feed', fn ($feed) => $feed->every(fn ($row) => $row['url'] === null))
            ->assertDontSeeHtml('href="'.route('admin.stock.index').'"')
            ->assertDontSeeHtml('href="'.route('sales.invoices.index').'"');
    }

    public function test_accountant_combination_keeps_reporting_without_stock_links(): void
    {
        $this->invoice();
        $this->actingAs($this->userWithPermissions(['invoices.view', 'payments.view', 'reports.view']));
        Volt::test('dashboard-summary')->assertSee('Encaissements')->assertSee('Créances clients')->assertSee('Évolution de l’activité')
            ->assertSeeHtml('href="'.route('reports.index').'"')
            ->assertDontSeeHtml('href="'.route('admin.stock.index').'"');
    }

    public function test_awaiting_receipt_uses_validated_quantities_not_order_status_alone(): void
    {
        $this->invoice();
        $supplierInvoice = $this->supplierInvoice();
        $orderId = $supplierInvoice->purchase_order_id;
        $lineId = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $orderId, 'item_type' => 'product', 'description' => 'Receipt test',
            'quantity' => 10, 'unit_price' => 1, 'subtotal_ht' => 10, 'total_ttc' => 10, 'position' => 1,
        ]);
        DB::table('purchase_orders')->where('id', $orderId)->update(['status' => 'confirmed']);
        $this->actingAs($this->userWithPermissions(['purchases.view']));
        $service = app(GoodsReceiptManagementService::class);
        $this->assertSame(1, $service->ordersAwaitingReceipt());
        $receiptId = DB::table('goods_receipts')->insertGetId([
            'number' => 'RECEIPT-TEST', 'purchase_order_id' => $orderId,
            'warehouse_id' => Warehouse::query()->first()->id,
            'receipt_date' => today(), 'status' => 'draft',
        ]);
        DB::table('goods_receipt_items')->insert([
            'goods_receipt_id' => $receiptId, 'purchase_order_item_id' => $lineId,
            'item_type' => 'product', 'description' => 'Receipt test', 'quantity' => 10, 'position' => 1,
        ]);
        $this->assertSame(1, $service->ordersAwaitingReceipt());
        DB::table('goods_receipts')->where('id', $receiptId)->update(['status' => 'cancelled']);
        $this->assertSame(1, $service->ordersAwaitingReceipt());
        DB::table('goods_receipts')->where('id', $receiptId)->update(['status' => 'validated']);
        DB::table('goods_receipt_items')->where('goods_receipt_id', $receiptId)->update(['quantity' => 4]);
        $this->assertSame(1, $service->ordersAwaitingReceipt());
        DB::table('goods_receipt_items')->where('goods_receipt_id', $receiptId)->update(['quantity' => 10]);
        $this->assertSame(0, $service->ordersAwaitingReceipt());
        // The confirmed status deliberately remains unchanged after full receipt.
        $this->assertSame('confirmed', DB::table('purchase_orders')->where('id', $orderId)->value('status'));
    }

    public function test_revocation_is_applied_on_next_livewire_render(): void
    {
        $user = $this->userWithPermissions(['stock.view', 'purchases.view']);
        $this->actingAs($user);
        $component = Volt::test('dashboard-summary')->assertSee('Commandes fournisseurs en cours');
        $user->roles()->first()->permissions()->detach(DB::table('permissions')->where('name', 'purchases.view')->value('id'));
        $component->call('$refresh')->assertDontSee('Commandes fournisseurs en cours')->assertSee('Ruptures de stock');
        $user->roles()->detach();
        $component->call('$refresh')->assertForbidden();
        $this->get(route('dashboard'))->assertForbidden();
    }

    public function test_revoking_reports_removes_financial_series_but_keeps_stock(): void
    {
        $this->invoice();
        $user = $this->userWithPermissions(['reports.view', 'stock.view']);
        $this->actingAs($user);
        $component = Volt::test('dashboard-summary')->assertSee('Évolution de l’activité');
        $user->roles()->first()->permissions()->detach(DB::table('permissions')->where('name', 'reports.view')->value('id'));
        $component->call('$refresh')->assertSee('Ruptures de stock')->assertDontSee('Chiffre d’affaires HT')->assertDontSee('Créances clients')->assertDontSeeHtml('dashboard-chart-stage')->assertViewHas('reporting', false);
        $this->assertArrayNotHasKey('chart', app(DashboardService::class)->read('', ''));
    }

    public function test_empty_role_uses_welcome_fallback(): void
    {
        $this->actingAs($this->userWithPermissions([]));
        $this->get(route('dashboard'))->assertOk()->assertSee('Bienvenue dans votre ERP');
    }

    public function test_operational_lists_and_query_counts_are_bounded_as_records_grow(): void
    {
        $this->invoice();
        $prototype = Customer::query()->first();
        $this->actingAs($this->userWithPermissions(['customers.view', 'stock.view', 'purchases.view', 'sales.view']));
        $measure = function (): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $payload = app(DashboardService::class)->read('', '');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertLessThanOrEqual(5, $payload['feed']->where('label', 'Clients récents')->count());
            $this->assertLessThanOrEqual(4, $payload['alerts']['Alertes stock']->count());
            $this->assertEmpty(array_filter($queries, fn ($query) => preg_match('/^(insert|update|delete|replace)\b/i', $query['query'])));

            return $queries;
        };
        $before = $measure();
        for ($i = 0; $i < 20; $i++) {
            DB::table('customers')->insert(array_merge($prototype->getAttributes(), ['id' => null, 'code' => 'DASH-CUSTOMER-'.$i]));
        }
        $this->assertCount(count($before), $measure());
    }

    public function test_operational_payload_and_queries_exclude_financial_data(): void
    {
        $invoice = $this->invoice();
        $this->recordedPayment($invoice, '37.41');
        $this->supplierInvoice();
        Product::query()->update(['minimum_stock' => '100.000', 'purchase_price' => '9876.54']);
        $this->actingAs($this->userWithPermissions(['stock.view', 'purchases.view']));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $payload = app(DashboardService::class)->read('', '');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertArrayNotHasKey('chart', $payload);
        $this->assertArrayNotHasKey('flows', $payload);
        $this->assertNull($payload['currency']);
        $this->assertStringNotContainsString('9876.54', json_encode($payload));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(payments|payment_allocations|credit_notes|expenses|cash_transactions|cash_registers|invoices)\b/i', $query['query']);
            $this->assertStringNotContainsString('purchase_price', $query['query']);
            $this->assertStringNotContainsString('total_ttc', $query['query']);
        }
        Volt::test('dashboard-summary')->assertSee('Alertes stock')->assertSee('Article test')->assertDontSee('9876,54')->assertDontSee('37,41')->assertDontSeeHtml('dashboard-chart-stage')->assertDontSee('Valeur du stock');
    }

    public function test_limited_profiles_use_fewer_business_queries(): void
    {
        $this->invoice();
        $this->supplierInvoice();
        $admin = auth()->user();
        $profiles = ['super-admin' => $admin, 'purchases-stock' => $this->userWithPermissions(['purchases.view', 'stock.view']), 'sales' => $this->userWithPermissions(['sales.view']), 'stock' => $this->userWithPermissions(['stock.view'])];
        $counts = [];
        foreach ($profiles as $profile => $user) {
            $this->actingAs($user);
            DB::enableQueryLog();
            DB::flushQueryLog();
            app(DashboardService::class)->read('2026-10-01', '2026-10-31');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $business = array_filter($queries, fn ($query) => ! preg_match('/\b(roles|permissions|role_user|permission_role)\b/', $query['query']));
            $counts[$profile] = ['business' => count($business), 'rbac' => count($queries) - count($business)];
        }
        foreach (['purchases-stock', 'sales', 'stock'] as $profile) {
            $this->assertLessThan($counts['super-admin']['business'], $counts[$profile]['business']);
        }
        fwrite(STDOUT, "\nDashboard queries: ".json_encode($counts)."\n");
    }
}
