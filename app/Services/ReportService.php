<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\CommercialSetting;
use App\Models\CreditNote;
use App\Models\CustomerReminder;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class ReportService
{
    public function __construct(private PaymentManagementService $payments, private SupplierPaymentManagementService $supplierPayments, private CashManagementService $cash) {}

    public static function decimal(mixed $amount, int $scale = 2): string
    {
        return (string) BigDecimal::of((string) ($amount ?? '0'))->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function money(mixed $amount, string $currency): string
    {
        return str_replace('.', ',', self::decimal($amount)).' '.$currency;
    }

    public function currency(): string
    {
        Gate::authorize('reports.view');

        return CommercialSetting::query()->where('singleton', true)->value('currency_code') ?? 'MAD';
    }

    public function period(string $from, string $to): array
    {
        Gate::authorize('reports.view');

        return Validator::make(compact('from', 'to'), ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']])->validate();
    }

    private function sum(Builder $query, string $expression): string
    {
        return self::decimal($query->reorder()->selectRaw("COALESCE(SUM($expression), 0) AS report_sum")->value('report_sum'));
    }

    public function salesSummary(string $from, string $to): array
    {
        $this->period($from, $to);
        $invoices = Invoice::query()->where('status', Invoice::STATUS_ISSUED)->where('invoice_date', '>=', $from)->where('invoice_date', '<', Carbon::parse($to)->addDay()->toDateString());
        $credits = CreditNote::query()->where('status', CreditNote::STATUS_ISSUED)->where('credit_date', '>=', $from)->where('credit_date', '<', Carbon::parse($to)->addDay()->toDateString());
        $gross = $this->sum(clone $invoices, 'total_ttc');
        $credited = $this->sum(clone $credits, 'total_ttc');
        $ht = bcsub($this->sum(clone $invoices, 'subtotal_ht - discount_total'), $this->sum(clone $credits, 'subtotal_ht - discount_total'), 2);
        $net = bcsub($gross, $credited, 2);

        return ['CA net HT' => $ht, 'Facturation brute TTC' => $gross, 'Avoirs émis TTC' => $credited, 'Facturation nette TTC' => $net, 'TVA nette' => bcsub($net, $ht, 2), 'Encaissements' => $this->sum(Payment::query()->where('payment_date', '>=', $from)->where('payment_date', '<', Carbon::parse($to)->addDay()->toDateString()), 'amount'), 'Factures émises' => $invoices->count()];
    }

    public function purchaseSummary(string $from, string $to): array
    {
        $this->period($from, $to);
        $query = SupplierInvoice::query()->where('status', SupplierInvoice::STATUS_VALIDATED)->where('invoice_date', '>=', $from)->where('invoice_date', '<', Carbon::parse($to)->addDay()->toDateString());
        $balances = $this->supplierPayments->reportingQuery();
        $remaining = DB::query()->fromSub($balances->toBase(), 'balances')->sum('report_remaining');

        return ['Achats HT' => $this->sum(clone $query, 'subtotal_ht - discount_total'), 'TVA achats' => $this->sum(clone $query, 'tax_total'), 'Achats facturés TTC' => $this->sum(clone $query, 'total_ttc'), 'Paiements fournisseurs' => $this->sum(SupplierPayment::query()->where('payment_date', '>=', $from)->where('payment_date', '<', Carbon::parse($to)->addDay()->toDateString()), 'amount'), 'Fournisseurs à payer actuels' => self::decimal($remaining)];
    }

    public function paymentSummary(string $from, string $to): array
    {
        $this->period($from, $to);

        return ['Encaissements' => $this->sum(Payment::query()->where('payment_date', '>=', $from)->where('payment_date', '<', Carbon::parse($to)->addDay()->toDateString()), 'amount'), 'Paiements fournisseurs' => $this->sum(SupplierPayment::query()->where('payment_date', '>=', $from)->where('payment_date', '<', Carbon::parse($to)->addDay()->toDateString()), 'amount')];
    }

    public function expenseSummary(string $from, string $to): array
    {
        $this->period($from, $to);
        $query = Expense::query()->where('expense_date', '>=', $from)->where('expense_date', '<', Carbon::parse($to)->addDay()->toDateString());
        $ttc = $this->sum(clone $query, 'amount');
        $tax = $this->sum(clone $query, 'tax_amount');

        return ['Dépenses TTC' => $ttc, 'TVA déclarée incluse' => $tax, 'Dépenses hors TVA déclarée' => bcsub($ttc, $tax, 2)];
    }

    public function receivablesSummary(): array
    {
        Gate::authorize('reports.view');
        $row = DB::query()->fromSub($this->payments->reportingQuery()->toBase(), 'balances')
            ->selectRaw('COALESCE(SUM(report_remaining), 0) AS remaining, COALESCE(SUM(CASE WHEN due_date < ? AND report_remaining > 0 THEN report_remaining ELSE 0 END), 0) AS overdue, COUNT(CASE WHEN due_date < ? AND report_remaining > 0 THEN 1 END) AS overdue_count, COUNT(CASE WHEN report_remaining > 0 THEN 1 END) AS open_count', [today()->toDateString(), today()->toDateString()])->first();

        return ['Créances clients' => self::decimal($row->remaining), 'Montant en retard' => self::decimal($row->overdue), 'Factures en retard' => (int) $row->overdue_count, 'Factures ouvertes' => (int) $row->open_count];
    }

    public function cashSummary(string $from, string $to): array
    {
        $this->period($from, $to);
        $query = CashTransaction::query()->where('transaction_date', '>=', $from)->where('transaction_date', '<', Carbon::parse($to)->addDay()->toDateString());

        return ['Entrées caisse' => $this->sum((clone $query)->where('type', 'entry'), 'amount'), 'Sorties caisse' => $this->sum((clone $query)->where('type', 'exit'), 'amount'), 'Solde caisse actuel' => self::decimal(DB::query()->fromSub($this->cash->reportingQuery()->toBase(), 'balances')->sum('report_balance'))];
    }

    public function stockQuery(): Builder
    {
        Gate::authorize('reports.view');

        return app(StockReportingQuery::class)->query();
    }

    public function stockSummary(): array
    {
        Gate::authorize('reports.view');
        $states = DB::query()->fromSub($this->stockQuery()->toBase(), 'stocks')->selectRaw('report_state, COUNT(*) AS total')->groupBy('report_state')->pluck('total', 'report_state');
        $ruptures = (int) ($states['rupture'] ?? 0);
        $low = (int) ($states['low'] ?? 0);

        return ['Produits actifs' => Product::query()->where('is_active', true)->count(), 'Produits physiques actifs' => Product::query()->where('is_active', true)->where('type', 'product')->count(), 'Alertes stock' => $ruptures + $low, 'Ruptures' => $ruptures, 'Stocks faibles' => $low];
    }

    public function cashRegisters()
    {
        Gate::authorize('reports.view');

        return $this->cash->reportingQuery()->orderByDesc('is_active')->orderBy('name')->get();
    }

    public function operationsSummary(string $from, string $to): array
    {
        $this->period($from, $to);

        return [
            'Commandes clients' => SalesOrder::query()->where('order_date', '>=', $from)->where('order_date', '<', Carbon::parse($to)->addDay()->toDateString())->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
            'Commandes fournisseurs' => PurchaseOrder::query()->where('order_date', '>=', $from)->where('order_date', '<', Carbon::parse($to)->addDay()->toDateString())->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
            'Réceptions validées' => GoodsReceipt::query()->where('status', 'validated')->where('receipt_date', '>=', $from)->where('receipt_date', '<', Carbon::parse($to)->addDay()->toDateString())->count(),
            'Devis récents' => Quote::query()->where('quote_date', '>=', $from)->where('quote_date', '<', Carbon::parse($to)->addDay()->toDateString())->orderByDesc('quote_date')->orderByDesc('id')->limit(5)->get(),
        ];
    }

    public function table(string $section, string $from, string $to, string $pageName = 'page')
    {
        $this->period($from, $to);
        $query = match ($section) {
            'sales' => Invoice::query()->where('status', 'issued')->where('invoice_date', '>=', $from)->where('invoice_date', '<', Carbon::parse($to)->addDay()->toDateString())->orderByDesc('invoice_date')->orderByDesc('id'),
            'credits' => CreditNote::query()->where('status', 'issued')->where('credit_date', '>=', $from)->where('credit_date', '<', Carbon::parse($to)->addDay()->toDateString())->orderByDesc('credit_date')->orderByDesc('id'),
            'purchases' => $this->supplierPayments->reportingQuery()->where('invoice_date', '>=', $from)->where('invoice_date', '<', Carbon::parse($to)->addDay()->toDateString())->orderByDesc('invoice_date')->orderByDesc('supplier_invoices.id'),
            'payments' => Payment::query()->with(['customer', 'paymentMethod'])->where('payment_date', '>=', $from)->where('payment_date', '<', Carbon::parse($to)->addDay()->toDateString())->orderByDesc('payment_date')->orderByDesc('id'),
            'supplierPayments' => SupplierPayment::query()->with(['supplier', 'paymentMethod'])->where('payment_date', '>=', $from)->where('payment_date', '<', Carbon::parse($to)->addDay()->toDateString())->orderByDesc('payment_date')->orderByDesc('id'),
            'expenses' => Expense::query()->with(['category', 'paymentMethod'])->where('expense_date', '>=', $from)->where('expense_date', '<', Carbon::parse($to)->addDay()->toDateString())->orderByDesc('expense_date')->orderByDesc('id'),
            'receivables' => $this->payments->reportingQuery()->with('latestReminder')->where('due_date', '>=', $from)->where('due_date', '<', Carbon::parse($to)->addDay()->toDateString())->orderBy('due_date')->orderBy('invoices.id'),
            'stock' => $this->stockQuery()->with('unit')->orderBy('products.name')->orderBy('warehouses.id'),
            'movements' => StockMovement::query()->with(['product', 'warehouse'])->where('created_at', '>=', $from.' 00:00:00')->where('created_at', '<', Carbon::parse($to)->addDay()->toDateString().' 00:00:00')->orderByDesc('created_at')->orderByDesc('id'),
        };
        if ($section === 'receivables') {
            $query->whereIn('invoices.id', DB::query()->fromSub($this->payments->reportingQuery()->toBase(), 'open_invoices')->where('report_remaining', '>', 0)->select('id'));
        }

        return $query->paginate(12, ['*'], $pageName);
    }

    public function recentActivity(): array
    {
        Gate::authorize('reports.view');

        return ['Factures récentes' => Invoice::query()->where('status', 'issued')->orderByDesc('invoice_date')->orderByDesc('id')->limit(5)->get(), 'Paiements récents' => Payment::query()->with('customer')->orderByDesc('payment_date')->orderByDesc('id')->limit(5)->get(), 'Dépenses récentes' => Expense::query()->with('category')->orderByDesc('expense_date')->orderByDesc('id')->limit(5)->get(), 'Dernières relances' => CustomerReminder::query()->with('invoice')->orderByDesc('reminder_date')->orderByDesc('id')->limit(5)->get()];
    }

    public function monthlySummary(string $from, string $to): array
    {
        $this->period($from, $to);
        // Fixed set of grouped queries, regardless of the number of invoices or months.
        $month = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%%Y-%%m', %s)" : "DATE_FORMAT(%s, '%%Y-%%m')";
        $groups = [];
        foreach ([['invoice', Invoice::query()->where('status', 'issued'), 'invoice_date', 'subtotal_ht - discount_total'], ['credit', CreditNote::query()->where('status', 'issued'), 'credit_date', 'subtotal_ht - discount_total'], ['payment', Payment::query(), 'payment_date', 'amount']] as [$key, $query, $date, $amount]) {
            $groups[$key] = $query->where($date, '>=', $from)->where($date, '<', Carbon::parse($to)->addDay()->toDateString())->selectRaw(sprintf($month, $date)." AS month, SUM($amount) AS amount")->groupBy('month')->pluck('amount', 'month');
        }
        $rows = [];
        foreach (array_unique(array_merge($groups['invoice']->keys()->all(), $groups['credit']->keys()->all(), $groups['payment']->keys()->all())) as $key) {
            $rows[$key] = ['month' => $key, 'net_ht' => bcsub(self::decimal($groups['invoice'][$key] ?? 0), self::decimal($groups['credit'][$key] ?? 0), 2), 'payments' => self::decimal($groups['payment'][$key] ?? 0)];
        }
        ksort($rows);

        return array_values($rows);
    }
}
