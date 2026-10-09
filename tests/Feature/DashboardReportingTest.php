<?php

namespace Tests\Feature;

use App\Models\CommercialSetting;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\DashboardService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\Support\ReportFixtures;
use Tests\TestCase;

class DashboardReportingTest extends TestCase
{
    use RefreshDatabase, ReportFixtures;

    public function test_default_month_presets_and_custom_period_keep_current_balances(): void
    {
        $this->invoice();
        CommercialSetting::query()->create(['singleton' => true, 'currency_code' => 'EUR', 'currency_name' => 'Euro']);
        $component = Volt::test('dashboard-summary')->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-31')
            ->assertSee('100,00 EUR')->assertViewHas('current', fn ($current) => $current['Créances clients'] === '120.00');
        $component->call('preset', 'previous')->assertSet('from', '2026-09-01')->assertSet('to', '2026-09-30')
            ->assertViewHas('flows', fn ($flows) => $flows['CA net HT'] === '0.00')
            ->assertViewHas('current', fn ($current) => $current['Créances clients'] === '120.00');
        $component->set('from', '2026-10-05')->set('to', '2026-10-05')->call('applyPeriod')
            ->assertViewHas('flows', fn ($flows) => $flows['CA net HT'] === '100.00');
        $component->call('preset', 'today')->assertSet('from', '2026-10-05')->assertSet('to', '2026-10-05');
        $component->call('preset', 'year')->assertSet('from', '2026-01-01')->assertSet('to', '2026-12-31');
    }

    public function test_invalid_period_preserves_last_applied_period(): void
    {
        Volt::test('dashboard-summary')->set('from', '2026-11-01')->set('to', '2026-10-01')->call('applyPeriod')->assertHasErrors('to')->assertSet('appliedFrom', '2026-10-01');
        Volt::test('dashboard-summary')->set('from', 'invalid')->call('applyPeriod')->assertHasErrors('from');
    }

    public function test_week_and_quarter_presets_refresh_the_chart(): void
    {
        Volt::test('dashboard-summary')->call('preset', 'week')
            ->assertSet('appliedFrom', '2026-10-05')->assertSet('appliedTo', '2026-10-11')
            ->assertViewHas('chart', fn ($chart) => count($chart['rows']) === 7 && $chart['interval'] === 'day')
            ->call('preset', 'quarter')->assertSet('appliedFrom', '2026-10-01')->assertSet('appliedTo', '2026-12-31')
            ->assertViewHas('chart', fn ($chart) => count($chart['rows']) === 14 && $chart['interval'] === 'week');
    }

    public function test_chart_reconciles_with_reports_and_keeps_negative_revenue_and_empty_days(): void
    {
        $invoice = $this->invoice();
        $this->invoice(false);
        $this->credit($invoice, '150.00');
        $this->credit($invoice, '900.00', 'draft');
        $this->recordedPayment($invoice, '20.00');
        $this->supplierInvoice();
        $this->supplierInvoice('draft');
        $this->supplierInvoice('cancelled');
        $category = ExpenseCategory::query()->create(['name' => 'Dashboard test', 'is_active' => true]);
        Expense::query()->create(['expense_category_id' => $category->id, 'payment_method_id' => PaymentMethod::query()->first()->id, 'expense_date' => '2026-10-05', 'amount' => '12.34', 'tax_amount' => '2.00']);
        $reports = app(ReportService::class);
        foreach ([['2026-10-01', '2026-10-31'], ['2026-10-01', '2026-12-31'], ['2026-01-01', '2026-12-31']] as [$from, $to]) {
            $chart = $reports->activitySeries($from, $to);
            foreach (['net_ht' => 'CA net HT', 'purchases' => 'Achats facturés TTC', 'payments' => 'Encaissements', 'expenses' => 'Dépenses TTC'] as $key => $label) {
                $sum = array_reduce($chart['rows'], fn ($sum, $row) => bcadd($sum, $row[$key], 2), '0.00');
                $this->assertSame($reports->dashboardSummary($from, $to)['flows'][$label], $sum);
            }
        }
        $chart = $reports->activitySeries('2026-10-04', '2026-10-06');
        $this->assertSame(['0.00', '-50.00', '0.00'], array_column($chart['rows'], 'net_ht'));
        $this->assertSame('108.00', $chart['rows'][1]['purchases']);
        $this->assertSame('20.00', $chart['rows'][1]['payments']);
        $this->assertSame('12.34', $chart['rows'][1]['expenses']);
    }

    public function test_chart_queries_are_bounded_for_long_ranges_and_boundary_dates_are_inclusive(): void
    {
        $invoice = $this->invoice();
        $this->recordedPayment($invoice, '10.00', '2026-10-01');
        $this->recordedPayment($invoice, '20.00', '2026-10-31');
        $this->recordedPayment($invoice, '30.00', '2026-11-01');
        $reports = app(ReportService::class);
        $chart = $reports->activitySeries('2026-10-01', '2026-10-31');
        $this->assertSame('10.00', $chart['rows'][0]['payments']);
        $this->assertSame('20.00', $chart['rows'][30]['payments']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $chart = $reports->activitySeries('2000-01-17', '2050-12-31');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(60, count($chart['rows']));
        $this->assertCount(5, array_filter($queries, fn ($query) => str_contains($query['query'], 'group by "bucket"')));
        $this->assertSame('60.00', array_reduce($chart['rows'], fn ($sum, $row) => bcadd($sum, $row['payments'], 2), '0.00'));
    }

    public function test_current_stock_and_overdue_work_are_independent_of_the_chart_period(): void
    {
        $this->invoice(true, '2026-10-04');
        $product = Product::query()->first();
        $product->update(['purchase_price' => '2.00', 'minimum_stock' => '100.000']);
        $dashboard = app(DashboardService::class);
        $current = $dashboard->read('2026-09-01', '2026-09-30');
        $this->assertSame('198.00', $current['stockValue']);
        $this->assertSame(1, $current['pending']['reminders']);
        $this->assertSame(1, $current['pending']['unpaid']);
        $this->assertCount(1, $current['alerts']['stock']);
        $this->assertCount(1, $current['alerts']['overdue']);
        $this->assertSame('0.00', $current['flows']['CA net HT']);
        $this->assertSame($current['current'], $dashboard->read('2026-10-01', '2026-10-31')['current']);
    }

    public function test_dashboard_reader_without_module_permissions_has_no_module_links(): void
    {
        $this->invoice();
        $this->actingAs($this->userWithPermissions(['reports.view']));
        Volt::test('dashboard-summary')->assertSee('CA net HT')
            ->assertDontSeeHtml('href="'.route('sales.invoices.index').'"')
            ->assertDontSeeHtml('href="'.route('purchases.orders.index').'"')
            ->assertViewHas('feed', fn ($feed) => $feed->every(fn ($row) => $row['url'] === null));
    }

    public function test_dashboard_renders_recent_activity_and_bounded_lists(): void
    {
        $invoice = $this->invoice();
        $this->recordedPayment($invoice, '20.00');
        $this->get(route('dashboard'))->assertOk()->assertSee('Situation actuelle')->assertSee('Factures récentes')->assertSee('Paiements récents');
        Volt::test('dashboard-summary')->assertViewHas('activity', fn ($activity) => collect($activity)->every(fn ($rows) => $rows->count() <= 5));
    }

    public function test_dashboard_read_query_count_does_not_grow_with_records(): void
    {
        $invoice = $this->invoice(true, '2026-10-03');
        $payment = $this->recordedPayment($invoice, '1.00');
        $this->supplierInvoice();
        $measure = function (): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $data = app(DashboardService::class)->read('2026-10-01', '2026-10-31');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertEmpty(array_filter($queries, fn ($query) => preg_match('/^(insert|update|delete|replace)\b/i', $query['query'])));
            $this->assertLessThanOrEqual(35, $data['feed']->count());
            $this->assertLessThanOrEqual(4, $data['alerts']['overdue']->count());

            return $queries;
        };
        $before = $measure();
        for ($i = 0; $i < 30; $i++) {
            Invoice::query()->forceCreate(array_merge($invoice->getAttributes(), ['id' => null, 'number' => 'DASH-PERF-'.$i]));
            Payment::query()->forceCreate(array_merge($payment->getAttributes(), ['id' => null, 'reference' => 'PAY-PERF-'.$i]));
        }
        $this->assertCount(count($before), $measure());
    }
}
