<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class StockReportingQuery
{
    public function query(): Builder
    {
        return Product::query()->crossJoin('warehouses')
            ->leftJoin('warehouse_stocks', function ($join): void {
                $join->on('warehouse_stocks.product_id', '=', 'products.id')->on('warehouse_stocks.warehouse_id', '=', 'warehouses.id');
            })->where('products.type', 'product')->select('products.*', 'warehouses.name as warehouse_name', 'warehouses.id as warehouse_id', 'warehouses.is_active as warehouse_active')
            ->selectRaw("COALESCE(warehouse_stocks.quantity, 0) AS report_quantity, CASE WHEN COALESCE(warehouse_stocks.quantity, 0) = 0 THEN 'rupture' WHEN products.minimum_stock IS NOT NULL AND warehouse_stocks.quantity > 0 AND warehouse_stocks.quantity <= products.minimum_stock THEN 'low' ELSE 'available' END AS report_state");
    }
}
