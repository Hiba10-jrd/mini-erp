<?php

namespace App\Services;

use App\Models\CustomerReminder;
use App\Models\GoodsReceiptHistory;
use App\Models\OperationHistory;
use App\Models\PurchaseOrderHistory;
use App\Models\SalesOrderHistory;
use App\Models\StockMovement;
use App\Models\SupplierInvoiceHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class AuditQueryService
{
    public const SOURCES = [
        'operations' => [OperationHistory::class, 'user_id', 'action', 'user'],
        'sales-orders' => [SalesOrderHistory::class, 'user_id', 'event', 'user'],
        'purchase-orders' => [PurchaseOrderHistory::class, 'user_id', 'event', 'user'],
        'goods-receipts' => [GoodsReceiptHistory::class, 'user_id', 'event', 'user'],
        'supplier-invoices' => [SupplierInvoiceHistory::class, 'user_id', 'event', 'user'],
        'stock' => [StockMovement::class, 'performed_by', 'type', 'performer'],
        'reminders' => [CustomerReminder::class, 'created_by', 'channel', 'creator'],
    ];

    public function query(string $source, array $filters): Builder
    {
        Gate::authorize('audit.access');
        abort_unless(isset(self::SOURCES[$source]), 404);
        [$model, $actor, $action, $relation] = self::SOURCES[$source];

        return $model::query()->with($relation.':id,name')
            ->when($filters['user'] ?? null, fn ($q, $value) => $q->where($actor, $value))
            ->when($filters['action'] ?? null, fn ($q, $value) => $q->where($action, $value))
            ->when($source === 'operations' && ($filters['entity'] ?? '') !== '', fn ($q) => $q->where('subject_type', $filters['entity']))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->where('created_at', '>=', $value.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->where('created_at', '<', Carbon::parse($value)->addDay()->toDateString()))
            ->orderByDesc('created_at')->orderByDesc('id');
    }
}
