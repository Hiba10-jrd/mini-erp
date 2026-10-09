<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\CashManagementService;
use App\Services\CustomerReminderManagementService;
use App\Services\ExpenseManagementService;
use App\Services\InvoiceManagementService;
use App\Services\PaymentManagementService;
use App\Services\ReportService;
use App\Services\SupplierPaymentManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\Support\ReportFixtures;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase, ReportFixtures;

    private function reports(): ReportService
    {
        return app(ReportService::class);
    }

    public function test_sales_use_document_dates_statuses_net_ht_and_negative_net(): void
    {
        $issued = $this->invoice();
        $draft = $this->invoice(false);
        $cancelled = $this->invoice(false);
        app(InvoiceManagementService::class)->cancelDraft($cancelled);
        $this->credit($issued, '30.00');
        $this->credit($issued, '50.00', 'draft');
        $summary = $this->reports()->salesSummary('2026-10-01', '2026-10-31');
        $this->assertSame('70.00', $summary['CA net HT']);
        $this->assertSame('120.00', $summary['Facturation brute TTC']);
        $this->assertSame('30.00', $summary['Avoirs émis TTC']);
        $this->assertSame('90.00', $summary['Facturation nette TTC']);
        $this->assertSame('20.00', $summary['TVA nette']);
        $this->assertSame(1, $summary['Factures émises']);
        $this->credit($issued, '40.00', 'issued', '2026-11-01');
        $november = $this->reports()->salesSummary('2026-11-01', '2026-11-30');
        $this->assertSame('-40.00', $november['CA net HT']);
        $this->assertSame('-40.00', $november['Facturation nette TTC']);
        $this->assertSame('70.00', $this->reports()->monthlySummary('2026-10-01', '2026-10-31')[0]['net_ht']);
    }

    public function test_multi_invoice_payment_is_counted_once_on_payment_date(): void
    {
        $invoice = $this->invoice();
        $other = Invoice::query()->forceCreate(array_merge($invoice->getAttributes(), ['id' => null, 'number' => 'REPORT-SECOND', 'invoice_date' => '2026-09-01']));
        $payment = $this->recordedPayment($invoice, '60.00', '2026-11-02');
        PaymentAllocation::query()->create(['payment_id' => $payment->id, 'invoice_id' => $other->id, 'amount' => '40.00']);
        $payment->update(['amount' => '100.00']);
        $this->assertSame('100.00', $this->reports()->paymentSummary('2026-11-01', '2026-11-30')['Encaissements']);
        $this->assertSame('0.00', $this->reports()->paymentSummary('2026-10-01', '2026-10-31')['Encaissements']);
        $this->assertSame(1, $this->reports()->table('payments', '2026-11-01', '2026-11-30')->total());
    }

    public function test_grouped_balances_match_unit_methods_without_join_multiplication(): void
    {
        $invoice = $this->invoice(true, '2026-10-04');
        $this->recordedPayment($invoice, '10.01');
        $this->recordedPayment($invoice, '20.02');
        $this->credit($invoice, '5.03');
        $this->credit($invoice, '6.04');
        $this->credit($invoice, '50.00', 'draft');
        $service = app(PaymentManagementService::class);
        $row = $service->reportingQuery()->first();
        foreach (['paid' => 'paidAmount', 'credited' => 'creditedAmount', 'payable' => 'payableAmount', 'remaining' => 'remainingAmount'] as $field => $method) {
            $this->assertSame($service->$method($invoice), ReportService::decimal($row->{'report_'.$field}));
        }
        $this->assertSame('78.90', $this->reports()->receivablesSummary()['Montant en retard']);
    }

    public function test_clamp_is_per_invoice_and_today_null_and_settled_are_not_overdue(): void
    {
        $overpaid = $this->invoice(true, '2026-10-04');
        $this->recordedPayment($overpaid, '120.00');
        $this->credit($overpaid, '30.00');
        $this->credit($overpaid, '200.00');
        $partial = $this->invoice(true, '2026-10-04');
        $this->recordedPayment($partial, '20.00');
        $this->invoice(true, '2026-10-05');
        $this->invoice(true, null);
        $summary = $this->reports()->receivablesSummary();
        $this->assertSame('340.00', $summary['Créances clients']);
        $this->assertSame('100.00', $summary['Montant en retard']);
        $this->assertSame(1, $summary['Factures en retard']);
        $this->assertSame(3, $summary['Factures ouvertes']);
        $this->assertSame(1, $this->reports()->table('receivables', '2026-10-01', '2026-10-04')->total());
    }

    public function test_supplier_totals_and_grouped_remaining_match_existing_service(): void
    {
        $invoice = $this->supplierInvoice();
        $this->supplierInvoice('draft');
        $this->supplierInvoice('cancelled');
        $method = PaymentMethod::query()->create(['name' => 'Supplier report mode', 'is_active' => true]);
        $payment = SupplierPayment::query()->create(['supplier_id' => $invoice->supplier_id, 'payment_method_id' => $method->id, 'payment_date' => '2026-11-02', 'amount' => '28.03']);
        SupplierPaymentAllocation::query()->create(['supplier_payment_id' => $payment->id, 'supplier_invoice_id' => $invoice->id, 'amount' => '28.03']);
        $service = app(SupplierPaymentManagementService::class);
        $row = $service->reportingQuery()->first();
        $this->assertSame($service->paidAmount($invoice), ReportService::decimal($row->report_paid));
        $this->assertSame($service->remainingAmount($invoice), ReportService::decimal($row->report_remaining));
        $summary = $this->reports()->purchaseSummary('2026-10-01', '2026-10-31');
        $this->assertSame('90.00', $summary['Achats HT']);
        $this->assertSame('18.00', $summary['TVA achats']);
        $this->assertSame('108.00', $summary['Achats facturés TTC']);
        $this->assertSame('79.97', $summary['Fournisseurs à payer actuels']);
        $this->assertSame('28.03', $this->reports()->paymentSummary('2026-11-01', '2026-11-30')['Paiements fournisseurs']);
    }

    public function test_expense_tax_and_cash_are_not_double_counted_and_inactive_cash_is_included(): void
    {
        $register = CashRegister::query()->create(['code' => 'REPORT', 'name' => 'Caisse rapport', 'initial_balance' => '200.00', 'is_active' => true]);
        $cash = app(CashManagementService::class);
        $cash->createManualTransaction($register->id, ['transaction_date' => '2026-09-01', 'type' => 'entry', 'amount' => '20.01']);
        $cash->createManualTransaction($register->id, ['transaction_date' => '2026-10-05', 'type' => 'exit', 'amount' => '10.02']);
        $category = ExpenseCategory::query()->create(['name' => 'Report expense']);
        $method = PaymentMethod::query()->create(['name' => 'Report cash', 'payment_type' => 'cash', 'is_active' => true]);
        app(ExpenseManagementService::class)->create(['expense_category_id' => $category->id, 'payment_method_id' => $method->id, 'cash_register_id' => $register->id, 'expense_date' => '2026-10-05', 'amount' => '60.00', 'tax_amount' => '10.00']);
        $register->update(['is_active' => false]);
        $this->assertSame(['Dépenses TTC' => '60.00', 'TVA déclarée incluse' => '10.00', 'Dépenses hors TVA déclarée' => '50.00'], $this->reports()->expenseSummary('2026-10-01', '2026-10-31'));
        $row = $cash->reportingQuery()->first();
        $this->assertSame($cash->currentBalance($register), ReportService::decimal($row->report_balance));
        $this->assertSame('149.99', $this->reports()->cashSummary('2026-10-01', '2026-10-31')['Solde caisse actuel']);
        $this->assertSame('70.02', $this->reports()->cashSummary('2026-10-01', '2026-10-31')['Sorties caisse']);
        $this->assertSame('0.00', $this->reports()->cashSummary('2026-10-01', '2026-10-31')['Entrées caisse']);
        Volt::test('admin.reports-manager')->call('selectSection', 'expenses')->assertSee('Report expense')->assertSee('60,00 MAD')->assertSee('10,00 MAD');
        Volt::test('dashboard-summary')->assertSee('Caisse rapport (inactive)')->assertSee('149,99 MAD');
    }

    public function test_stock_alerts_include_missing_rows_and_equal_threshold_per_warehouse(): void
    {
        $invoice = $this->invoice();
        $product = Product::query()->first();
        $product->update(['minimum_stock' => '5.000']);
        WarehouseStock::query()->where('product_id', $product->id)->update(['quantity' => '5.000']);
        Warehouse::query()->create(['code' => 'MISSING', 'name' => 'Dépôt sans ligne']);
        Product::query()->forceCreate(['reference' => 'REPORT-SERVICE', 'name' => 'Conseil', 'type' => 'service', 'unit_id' => $product->unit_id, 'is_active' => true]);
        $summary = $this->reports()->stockSummary();
        $this->assertSame(2, $summary['Produits actifs']);
        $this->assertSame(1, $summary['Produits physiques actifs']);
        $this->assertSame(1, $summary['Ruptures']);
        $this->assertSame(1, $summary['Stocks faibles']);
        $this->assertSame(2, $summary['Alertes stock']);
    }

    public function test_all_sections_render_and_reports_use_sql_pagination(): void
    {
        $invoice = $this->invoice();
        $this->credit($invoice, '10.00');
        $this->recordedPayment($invoice, '20.00');
        $this->supplierInvoice();
        $component = Volt::test('admin.reports-manager');
        foreach (['sales', 'purchases', 'payments', 'expenses', 'receivables', 'stock'] as $section) {
            $component->call('selectSection', $section)->assertHasNoErrors()->assertViewHas('tables', fn ($tables) => $tables[$section]['paginator'] instanceof LengthAwarePaginator);
        }
        for ($i = 0; $i < 14; $i++) {
            Invoice::query()->forceCreate(array_merge($invoice->getAttributes(), ['id' => null, 'number' => 'PAGE-'.$i]));
        }
        $page = $this->reports()->table('sales', '2026-10-01', '2026-10-31');
        $this->assertSame(15, $page->total());
        $this->assertSame(12, $page->count());
    }

    public function test_financial_kpi_query_count_does_not_grow_with_invoice_count(): void
    {
        $invoice = $this->invoice();
        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->reports()->receivablesSummary();
            $this->reports()->salesSummary('2026-10-01', '2026-10-31');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $before = $measure();
        for ($i = 0; $i < 30; $i++) {
            Invoice::query()->forceCreate(array_merge($invoice->getAttributes(), ['id' => null, 'number' => 'PERF-'.$i]));
        }
        $this->assertSame($before, $measure());
        $this->assertSame('3720.00', $this->reports()->receivablesSummary()['Créances clients']);
    }

    public function test_reminders_and_stock_movements_render_and_reading_changes_no_business_data(): void
    {
        $invoice = $this->invoice(true, '2026-10-04');
        app(CustomerReminderManagementService::class)->create($invoice, ['reminder_date' => '2026-10-03', 'channel' => 'phone', 'note' => 'Appel effectué']);
        $tables = ['invoices', 'credit_notes', 'payments', 'payment_allocations', 'expenses', 'cash_transactions', 'warehouse_stocks', 'stock_movements', 'customer_reminders'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        Volt::test('admin.reports-manager')->call('selectSection', 'receivables')->assertSee('03/10/2026')->assertSee('1 jour');
        Volt::test('admin.reports-manager')->call('selectSection', 'stock')->assertSee('Mouvements de la période')->assertViewHas('tables', fn ($tables) => $tables['movements']['paginator']->total() === 1);
        $this->assertSame(0, $this->reports()->table('movements', '2026-09-01', '2026-09-30')->total());
        Volt::test('dashboard-summary')->assertSee('Dernières relances')->assertSee('phone');
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_inclusive_date_bounds_and_monthly_payments_use_their_own_dates(): void
    {
        $invoice = $this->invoice();
        $this->recordedPayment($invoice, '0.01', '2026-10-01');
        $this->recordedPayment($invoice, '0.02', '2026-10-31');
        $this->recordedPayment($invoice, '0.03', '2026-11-01');
        $summary = $this->reports()->paymentSummary('2026-10-01', '2026-10-31');
        $this->assertSame('0.03', $summary['Encaissements']);
        $this->assertSame('0.03', $this->reports()->monthlySummary('2026-10-01', '2026-10-31')[0]['payments']);
        $this->assertSame('0.03', $this->reports()->paymentSummary('2026-11-01', '2026-11-01')['Encaissements']);
    }
}
