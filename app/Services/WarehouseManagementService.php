<?php

namespace App\Services;

use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WarehouseManagementService
{
    /** @param array<string, string|null> $attributes */
    public function save(?int $warehouseId, array $attributes): Warehouse
    {
        Gate::authorize('stock.manage');

        return DB::transaction(function () use ($warehouseId, $attributes): Warehouse {
            $warehouse = $warehouseId === null
                ? new Warehouse
                : Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);

            if (! $warehouse->exists) {
                $warehouse->is_active = true;
            }

            $warehouse->fill($attributes)->save();

            return $warehouse->fresh();
        }, 3);
    }

    public function setActive(int $warehouseId, bool $isActive): Warehouse
    {
        Gate::authorize('stock.manage');

        return DB::transaction(function () use ($warehouseId, $isActive): Warehouse {
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);
            $warehouse->forceFill(['is_active' => $isActive])->save();

            return $warehouse->fresh();
        }, 3);
    }
}
