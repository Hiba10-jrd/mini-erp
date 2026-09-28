<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CatalogManagementService
{
    /** @param array{name: string, description: string|null, is_active: bool} $attributes */
    public function saveCategory(?int $categoryId, array $attributes): Category
    {
        Gate::authorize('stock.manage');

        return DB::transaction(function () use ($categoryId, $attributes): Category {
            $category = $categoryId === null
                ? new Category
                : Category::query()->lockForUpdate()->findOrFail($categoryId);
            $category->fill($attributes)->save();

            return $category->fresh();
        });
    }

    public function setCategoryActive(int $categoryId, bool $isActive): Category
    {
        Gate::authorize('stock.manage');

        return DB::transaction(function () use ($categoryId, $isActive): Category {
            $category = Category::query()->lockForUpdate()->findOrFail($categoryId);
            $category->forceFill(['is_active' => $isActive])->save();

            return $category->fresh();
        });
    }

    /** @param array{name: string, symbol: string, is_active: bool} $attributes */
    public function saveUnit(?int $unitId, array $attributes): Unit
    {
        Gate::authorize('stock.manage');

        return DB::transaction(function () use ($unitId, $attributes): Unit {
            $unit = $unitId === null
                ? new Unit
                : Unit::query()->lockForUpdate()->findOrFail($unitId);
            $unit->fill($attributes)->save();

            return $unit->fresh();
        });
    }

    public function setUnitActive(int $unitId, bool $isActive): Unit
    {
        Gate::authorize('stock.manage');

        return DB::transaction(function () use ($unitId, $isActive): Unit {
            $unit = Unit::query()->lockForUpdate()->findOrFail($unitId);
            $unit->forceFill(['is_active' => $isActive])->save();

            return $unit->fresh();
        });
    }
}
