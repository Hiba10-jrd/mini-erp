<?php

namespace App\Services;

use App\Models\CustomerReminder;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DashboardService
{
    public function __construct(private ReportService $reports, private PaymentManagementService $payments) {}

    public function read(string $from, string $to): array
    {
        Gate::authorize('reports.view');
        $data = $this->reports->dashboardSummary($from, $to);
        $operations = $this->reports->operationsSummary($from, $to);
        $activity = $this->reports->recentActivity();
        $permissions = [];
        foreach (['sales.view', 'invoices.view', 'purchases.view', 'payments.view', 'stock.access'] as $permission) {
            $permissions[$permission] = Gate::allows($permission);
        }

        $pending = [
            'quotes' => Quote::query()->whereNull('archived_at')->whereIn('status', [Quote::STATUS_DRAFT, Quote::STATUS_SENT])->count(),
            'salesOrders' => SalesOrder::query()->whereNull('archived_at')->whereIn('status', [SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_DELIVERED])->count(),
            // Purchase orders have no completed status. Expose the actual statuses instead
            // of inventing a completion workflow in a read-only dashboard.
            'purchaseOrders' => PurchaseOrder::query()->whereIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CONFIRMED])->count(),
            'receipts' => GoodsReceipt::query()->where('status', GoodsReceipt::STATUS_DRAFT)->count(),
            'unpaid' => $data['receivables']['Factures ouvertes'],
            // Reminder records are immutable completed contacts, not a task queue.
            'reminders' => $data['receivables']['Factures en retard'],
        ];

        $recentOrders = PurchaseOrder::query()->orderByDesc('order_date')->orderByDesc('id')->limit(5)->get();
        $recentReceipts = GoodsReceipt::query()->with('purchaseOrder:id,supplier_name')
            ->where('status', GoodsReceipt::STATUS_VALIDATED)->orderByDesc('validated_at')->orderByDesc('id')->limit(5)->get();
        $feed = collect();
        foreach ([
            [$activity['Factures récentes'], 'Factures récentes', 'invoice_date', 'customer_name', 'total_ttc', 'blue', 'sales.invoices.show', 'invoices.view'],
            [$activity['Paiements récents'], 'Paiements récents', 'payment_date', 'customer.name', 'amount', 'green', null, 'payments.view'],
            [$activity['Dépenses récentes'], 'Dépenses récentes', 'expense_date', 'category.name', 'amount', 'red', null, 'payments.view'],
            [$activity['Dernières relances'], 'Dernières relances', 'reminder_date', 'channel', null, 'orange', null, 'payments.view'],
            [$recentOrders, 'Commandes fournisseurs', 'order_date', 'supplier_name', 'total_ttc', 'violet', 'purchases.orders.show', 'purchases.view'],
            [$recentReceipts, 'Réceptions validées', 'validated_at', 'purchaseOrder.supplier_name', null, 'green', 'purchases.receipts.show', 'purchases.view'],
            [$operations['Devis récents'], 'Devis récents sur la période', 'quote_date', 'customer_name', 'total_ttc', 'blue', 'sales.quotes.show', 'sales.view'],
        ] as [$rows, $label, $date, $party, $amount, $color, $route, $permission]) {
            foreach ($rows as $row) {
                $feed->push([
                    'key' => $row->getTable().'-'.$row->getKey(),
                    'label' => $label,
                    'reference' => $row->number ?? $row->reference ?? ($row instanceof CustomerReminder ? $row->invoice?->number : null),
                    'party' => data_get($row, $party),
                    'date' => $row->{$date},
                    'amount' => $amount === null ? null : $row->{$amount},
                    'color' => $color,
                    'url' => $route !== null && $permissions[$permission] ? route($route, $row) : null,
                ]);
            }
        }

        $overdue = $this->payments->reportingQuery()->where('due_date', '<', today()->toDateString())
            ->whereIn('invoices.id', DB::query()->fromSub($this->payments->reportingQuery()->toBase(), 'open_balances')->where('report_remaining', '>', 0)->select('id'))
            ->orderBy('due_date')->orderBy('invoices.id')->limit(4)->get();
        $stockAlerts = $this->reports->stockQuery()->where(function ($query): void {
            $query->whereRaw('COALESCE(warehouse_stocks.quantity, 0) = 0')
                ->orWhereColumn('warehouse_stocks.quantity', '<=', 'products.minimum_stock');
        })->orderByRaw('COALESCE(warehouse_stocks.quantity, 0)')->orderBy('products.name')->limit(4)->get();

        return $data + [
            'currency' => $this->reports->currency(),
            'cashDetails' => $this->reports->cashRegisters(6),
            'operations' => $operations,
            'activity' => $activity,
            'feed' => $feed->sortByDesc(fn ($item) => $item['date']?->getTimestamp() ?? 0)->values(),
            'pending' => $pending,
            'permissions' => $permissions,
            'overdueCount' => $data['receivables']['Factures en retard'],
            'chart' => $this->reports->activitySeries($from, $to),
            'alerts' => [
                'overdue' => $overdue,
                'stock' => $stockAlerts,
                'receipts' => GoodsReceipt::query()->where('status', GoodsReceipt::STATUS_DRAFT)->orderBy('receipt_date')->limit(3)->get(),
                'supplierInvoices' => SupplierInvoice::query()->where('status', SupplierInvoice::STATUS_DRAFT)->orderBy('invoice_date')->limit(3)->get(),
            ],
        ];
    }
}
