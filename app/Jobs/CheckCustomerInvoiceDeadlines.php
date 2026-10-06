<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\InternalNotificationDispatcher;
use App\Services\PaymentManagementService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckCustomerInvoiceDeadlines implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public int $tries = 3;

    public int $timeout = 60;

    public function handle(PaymentManagementService $payments, InternalNotificationDispatcher $dispatcher): void
    {
        // Wrap the LOT 20 read model to filter its computed balance without duplicating it.
        $query = Invoice::query()->fromSub($payments->reportingQuery()->toBase(), 'invoices')
            ->where('report_remaining', '>', 0)->whereNotNull('invoices.due_date')
            ->whereDate('invoices.due_date', '<=', today()->addDays(7));
        $query
            ->chunkById(100, function ($invoices) use ($dispatcher): void {
                foreach ($invoices as $invoice) {
                    $overdue = $invoice->due_date->lt(today());
                    $type = $overdue ? 'invoice.overdue' : 'invoice.due-soon';
                    $dispatcher->group('finance', [
                        'type' => $type, 'title' => $overdue ? 'Facture en retard' : 'Échéance proche',
                        'message' => 'Facture '.$invoice->number.' : '.$invoice->due_date->toDateString(),
                        'url' => route('finance.receivables.index', ['invoice' => $invoice->id], false),
                        'entity_type' => 'invoice', 'entity_id' => $invoice->id,
                        'severity' => $overdue ? 'warning' : 'info',
                    ], $type.':'.$invoice->id.':'.$invoice->due_date->toDateString());
                }
            }, 'invoices.id', 'id');
    }
}
