<?php

namespace App\Services;

use App\Models\CommercialSetting;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Operational reads, isolated from the transversal reports contract. */
class DashboardDomainLoader
{
    public function read(): array
    {
        Gate::authorize('erp.access');
        $capabilities = [];
        foreach (['customers.access', 'suppliers.access', 'sales.view', 'purchases.view', 'stock.access', 'invoices.view', 'payments.view'] as $gate) {
            $capabilities[$gate] = Gate::allows($gate);
        }
        $cards = $pending = $situation = $alerts = [];
        $feed = collect();
        $currency = null;
        $card = function (string $label, mixed $value, string $color = 'blue') use (&$cards): void {
            $cards[] = compact('label', 'value', 'color');
        };
        $operation = function (string $label, int $value, string $route) use (&$pending): void {
            $pending[] = compact('label', 'value', 'route');
        };
        $recent = function ($rows, string $label, string $date, string $reference, ?string $route = null, ?string $amount = null, ?string $detail = null) use ($feed): void {
            foreach ($rows as $row) {
                $feed->push([
                    'key' => $row->getTable().'-'.$row->getKey(), 'label' => $label,
                    'reference' => $row->{$reference}, 'date' => $row->{$date},
                    'url' => $route ? route($route, $row->id) : null,
                    'amount' => $amount ? $row->{$amount} : null,
                    'detail' => $detail ? $row->{$detail} : null,
                ]);
            }
        };

        if ($capabilities['customers.access']) {
            $card('Clients actifs', Customer::query()->where('status', 'active')->count());
            $recent(Customer::query()->latest('id')->limit(5)->get(['id', 'name', 'created_at']), 'Clients récents', 'created_at', 'name', 'admin.customers.show');
        }
        if ($capabilities['suppliers.access']) {
            $card('Fournisseurs actifs', Supplier::query()->where('status', 'active')->count(), 'violet');
            $recent(Supplier::query()->latest('id')->limit(5)->get(['id', 'name', 'created_at']), 'Fournisseurs récents', 'created_at', 'name', 'admin.suppliers.show');
        }
        if ($capabilities['sales.view']) {
            $quotes = Quote::query()->whereNull('archived_at')->whereIn('status', [Quote::STATUS_DRAFT, Quote::STATUS_SENT])->count();
            $orders = SalesOrder::query()->whereNull('archived_at')->whereIn('status', [SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_DELIVERED])->count();
            $card('Devis en attente', $quotes);
            $card('Commandes clients', $orders);
            $operation('Devis en attente', $quotes, 'sales.quotes.index');
            $operation('Commandes clients', $orders, 'sales.orders.index');
            $recent(Quote::query()->whereNull('archived_at')->orderByDesc('quote_date')->orderByDesc('id')->limit(5)->get(['id', 'number', 'quote_date']), 'Devis récents', 'quote_date', 'number', 'sales.quotes.show');
            $recent(SalesOrder::query()->whereNull('archived_at')->orderByDesc('order_date')->orderByDesc('id')->limit(5)->get(['id', 'number', 'order_date']), 'Commandes clients', 'order_date', 'number', 'sales.orders.show');
        }
        if ($capabilities['purchases.view']) {
            // These counts describe actual statuses, never imply full receipt of an order.
            $orders = PurchaseOrder::query()->whereIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CONFIRMED])->count();
            $receipts = GoodsReceipt::query()->where('status', GoodsReceipt::STATUS_DRAFT)->count();
            $card('Commandes fournisseurs en cours', $orders, 'violet');
            $awaiting = app(GoodsReceiptManagementService::class)->ordersAwaitingReceipt();
            $card('Commandes à réceptionner', $awaiting, 'green');
            $operation('Commandes fournisseurs', $orders, 'purchases.orders.index');
            $operation('Réceptions brouillon', $receipts, 'purchases.receipts.index');
            $operation('Commandes à réceptionner', $awaiting, 'purchases.orders.index');
            $recent(PurchaseOrder::query()->orderByDesc('order_date')->orderByDesc('id')->limit(5)->get(['id', 'number', 'order_date']), 'Commandes fournisseurs', 'order_date', 'number', 'purchases.orders.show');
            $recent(GoodsReceipt::query()->where('status', GoodsReceipt::STATUS_VALIDATED)->orderByDesc('validated_at')->orderByDesc('id')->limit(5)->get(['id', 'number', 'validated_at']), 'Réceptions validées', 'validated_at', 'number', 'purchases.receipts.show');
            $alerts['Factures fournisseurs brouillon'] = SupplierInvoice::query()->where('status', SupplierInvoice::STATUS_DRAFT)->orderBy('invoice_date')->orderBy('id')->limit(3)->get(['id', 'supplier_invoice_number'])->map(fn ($row) => $row->supplier_invoice_number);
        }
        if ($capabilities['stock.access']) {
            $stocks = app(StockReportingQuery::class)->query(includePrices: false);
            $states = DB::query()->fromSub((clone $stocks)->toBase(), 'stocks')->selectRaw('report_state, COUNT(*) AS total')->groupBy('report_state')->pluck('total', 'report_state');
            $card('Ruptures de stock', (int) ($states['rupture'] ?? 0), 'red');
            $card('Stocks faibles', (int) ($states['low'] ?? 0), 'orange');
            $situation['Produits actifs'] = Product::query()->where('is_active', true)->count();
            $situation['Inventaires ouverts'] = StockInventory::query()->whereIn('status', [StockInventory::STATUS_DRAFT, StockInventory::STATUS_IN_PROGRESS])->count();
            $alerts['Alertes stock'] = $stocks->where(fn ($query) => $query->whereRaw('COALESCE(warehouse_stocks.quantity, 0) = 0')->orWhereColumn('warehouse_stocks.quantity', '<=', 'products.minimum_stock'))
                ->orderByRaw('COALESCE(warehouse_stocks.quantity, 0)')->orderBy('products.name')->limit(4)->get()
                ->map(fn ($row) => $row->name.' · '.$row->warehouse_name.' · '.$row->report_quantity);
            $movements = StockMovement::query()->join('products', 'products.id', '=', 'stock_movements.product_id')
                ->join('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')
                ->orderByDesc('stock_movements.id')->limit(5)
                ->get(['stock_movements.id', 'stock_movements.created_at', 'stock_movements.quantity', 'stock_movements.type', 'products.name as product_name', 'warehouses.name as warehouse_name']);
            foreach ($movements as $movement) {
                $label = match ($movement->type) {
                    'entry' => __('Entrée'), 'exit' => __('Sortie'),
                    'transfer_out' => __('Transfert sortant'), 'transfer_in' => __('Transfert entrant'),
                    'return_in' => __('Retour entrant'), 'return_out' => __('Retour sortant'),
                    'adjustment_positive' => __('Ajustement positif'), 'adjustment_negative' => __('Ajustement négatif'),
                    default => $movement->type,
                };
                $movement->detail = $movement->warehouse_name.' · '.$label.' · '.$movement->quantity;
            }
            $recent($movements, 'Mouvements récents', 'created_at', 'product_name', null, null, 'detail');
        }
        if ($capabilities['invoices.view']) {
            $counts = Invoice::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
            $card('Factures clients', $counts->sum());
            $card('Factures émises', (int) ($counts[Invoice::STATUS_ISSUED] ?? 0), 'green');
            $operation('Factures brouillon', (int) ($counts['draft'] ?? 0), 'sales.invoices.index');
            $recent(Invoice::query()->orderByDesc('invoice_date')->orderByDesc('id')->limit(5)->get(['id', 'number', 'invoice_date']), 'Factures récentes', 'invoice_date', 'number', 'sales.invoices.show');
        }
        if ($capabilities['payments.view']) {
            $currency = CommercialSetting::query()->where('singleton', true)->value('currency_code') ?? 'MAD';
            $money = fn ($amount) => ReportService::money($amount, $currency);
            $balances = app(PaymentManagementService::class)->reportingQuery();
            $summary = DB::query()->fromSub($balances->toBase(), 'balances')->selectRaw('COALESCE(SUM(report_remaining), 0) AS remaining, COUNT(CASE WHEN report_remaining > 0 THEN 1 END) AS open_count, COUNT(CASE WHEN due_date < ? AND report_remaining > 0 THEN 1 END) AS overdue', [today()->toDateString()])->first();
            $card('Encaissements', $money(Payment::query()->sum('amount')), 'green');
            $card('Créances clients', $money($summary->remaining));
            $operation('Créances clients', (int) $summary->open_count, 'finance.receivables.index');
            $card('Factures en retard', (int) $summary->overdue, 'orange');
            $card('Dépenses TTC', $money(Expense::query()->sum('amount')), 'red');
            $registers = app(CashManagementService::class)->reportingQuery();
            $situation['Solde caisse'] = $money(DB::query()->fromSub($registers->toBase(), 'registers')->sum('report_balance'));
            $recent(Payment::query()->orderByDesc('payment_date')->orderByDesc('id')->limit(5)->get(['id', 'reference', 'payment_date', 'amount']), 'Paiements récents', 'payment_date', 'reference', null, 'amount');
            $alerts['Factures en retard'] = DB::query()->fromSub($balances->toBase(), 'balances')->where('due_date', '<', today()->toDateString())->where('report_remaining', '>', 0)->orderBy('due_date')->orderBy('id')->limit(4)->get(['number', 'report_remaining'])->map(fn ($row) => $row->number.' · '.$money($row->report_remaining));
        }

        return compact('capabilities', 'cards', 'pending', 'situation', 'alerts', 'currency') + ['feed' => $feed->sortByDesc(fn ($row) => $row['date']?->getTimestamp() ?? 0)->values()];
    }
}
