<?php

namespace App\Jobs;

use App\Services\InternalNotificationDispatcher;
use App\Services\StockReportingQuery;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckStockAlerts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public int $tries = 3;

    public int $timeout = 60;

    public function handle(StockReportingQuery $stocks, InternalNotificationDispatcher $dispatcher): void
    {
        $stocks->query()->where('products.is_active', true)->where('warehouses.is_active', true)
            ->whereRaw('(COALESCE(warehouse_stocks.quantity, 0) = 0 OR (products.minimum_stock IS NOT NULL AND warehouse_stocks.quantity > 0 AND warehouse_stocks.quantity <= products.minimum_stock))')
            ->orderBy('products.id')->orderBy('warehouses.id')->chunk(100, function ($rows) use ($dispatcher): void {
                foreach ($rows as $row) {
                    $out = $row->report_state === 'rupture';
                    $type = $out ? 'stock.out' : 'stock.low';
                    $titleKey = $out ? 'Rupture de stock' : 'Stock faible';
                    $dispatcher->group('stock', [
                        'type' => $type,
                        'title' => $out ? 'Rupture de stock' : 'Stock faible',
                        'title_key' => $titleKey,
                        'message' => $row->name.' — '.$row->warehouse_name,
                        'message_key' => ':product — :warehouse',
                        'params' => ['product' => $row->name, 'warehouse' => $row->warehouse_name],
                        'url' => route('admin.stock.index', ['warehouse' => $row->warehouse_id, 'product' => $row->id], false),
                        'entity_type' => 'product', 'entity_id' => $row->id, 'severity' => 'warning',
                    ], $type.':'.$row->id.':'.$row->warehouse_id.':'.today()->toDateString());
                }
            });
    }
}
