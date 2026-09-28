<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockInventory;
use App\Models\StockInventoryLine;
use App\Models\StockInventorySequence;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InventoryManagementService
{
    public function create(int $warehouseId, ?string $notes = null): StockInventory
    {
        Gate::authorize('stock.manage');
        $notes = $this->normalizeNotes($notes);

        return DB::transaction(function () use ($warehouseId, $notes): StockInventory {
            $year = (int) now()->format('Y');
            $now = now();
            DB::table('stock_inventory_sequences')->insertOrIgnore([
                'year' => $year,
                'next_number' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $sequence = StockInventorySequence::query()
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();
            $number = $sequence->next_number;
            $sequence->increment('next_number');

            $productIds = Product::query()
                ->where('type', 'product')
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id');
            $products = Product::query()
                ->whereKey($productIds)
                ->where('type', 'product')
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            if ($products->isEmpty()) {
                throw ValidationException::withMessages([
                    'warehouseId' => __('Aucun produit physique actif ne peut être inventorié.'),
                ]);
            }

            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);

            if (! $warehouse->is_active) {
                throw ValidationException::withMessages(['warehouseId' => __('Le dépôt sélectionné est inactif.')]);
            }

            $stocks = WarehouseStock::query()
                ->where('warehouse_id', $warehouse->id)
                ->whereIn('product_id', $products->pluck('id'))
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');
            $inventory = new StockInventory;
            $inventory->forceFill([
                'reference' => sprintf('INV-%d-%05d', $year, $number),
                'warehouse_id' => $warehouse->id,
                'status' => StockInventory::STATUS_DRAFT,
                'notes' => $notes,
                'started_by' => auth()->id(),
                'started_at' => $now,
            ])->save();

            $lines = $products->map(fn (Product $product): array => [
                'stock_inventory_id' => $inventory->id,
                'product_id' => $product->id,
                'theoretical_quantity' => $stocks->get($product->id)?->quantity ?? '0.000',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            StockInventoryLine::query()->insert($lines);

            return $inventory->load(['warehouse', 'starter', 'lines.product.unit']);
        }, 3);
    }

    public function saveLine(int $inventoryId, int $lineId, string|int|float $actualQuantity, ?string $notes = null): StockInventoryLine
    {
        Gate::authorize('stock.manage');
        $actual = $this->toMilliunits($actualQuantity);
        $notes = $this->normalizeNotes($notes);

        return DB::transaction(function () use ($inventoryId, $lineId, $actual, $notes): StockInventoryLine {
            $inventory = StockInventory::query()->lockForUpdate()->findOrFail($inventoryId);
            $this->ensureEditable($inventory);
            $line = StockInventoryLine::query()
                ->where('stock_inventory_id', $inventory->id)
                ->whereKey($lineId)
                ->lockForUpdate()
                ->firstOrFail();
            $theoretical = $this->toMilliunits($line->theoretical_quantity);
            $line->forceFill([
                'actual_quantity' => $this->fromMilliunits($actual),
                'difference' => $this->fromMilliunits($actual - $theoretical),
                'notes' => $notes,
            ])->save();

            if ($inventory->status === StockInventory::STATUS_DRAFT) {
                $inventory->forceFill(['status' => StockInventory::STATUS_IN_PROGRESS])->save();
            }

            return $line->fresh('product.unit');
        }, 3);
    }

    public function cancel(int $inventoryId): StockInventory
    {
        Gate::authorize('stock.manage');

        return DB::transaction(function () use ($inventoryId): StockInventory {
            $inventory = StockInventory::query()->lockForUpdate()->findOrFail($inventoryId);
            $this->ensureEditable($inventory);
            $inventory->forceFill(['status' => StockInventory::STATUS_CANCELLED])->save();

            return $inventory->fresh(['warehouse', 'starter', 'validator']);
        }, 3);
    }

    public function validate(int $inventoryId): StockInventory
    {
        Gate::authorize('stock.manage');

        return DB::transaction(function () use ($inventoryId): StockInventory {
            $inventory = StockInventory::query()->lockForUpdate()->findOrFail($inventoryId);
            $this->ensureEditable($inventory);
            $lines = StockInventoryLine::query()
                ->where('stock_inventory_id', $inventory->id)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty() || $lines->contains(fn (StockInventoryLine $line): bool => $line->actual_quantity === null)) {
                throw ValidationException::withMessages([
                    'inventory' => __('Toutes les quantités réelles doivent être renseignées avant validation.'),
                ]);
            }

            $productIds = $lines->pluck('product_id')->sort()->values();
            $products = Product::query()
                ->whereKey($productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count() || $products->contains(fn (Product $product): bool => $product->type !== 'product')) {
                throw ValidationException::withMessages([
                    'inventory' => __('Les lignes de l’inventaire doivent référencer uniquement des produits physiques.'),
                ]);
            }

            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($inventory->warehouse_id);
            $this->ensureStockRowsExist($warehouse->id, $productIds);
            $stocks = WarehouseStock::query()
                ->where('warehouse_id', $warehouse->id)
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            foreach ($lines as $line) {
                $stock = $stocks->get($line->product_id);
                $actual = $this->toMilliunits($line->actual_quantity);
                $theoretical = $this->toMilliunits($line->theoretical_quantity);
                $current = $this->toMilliunits($stock->quantity);
                $correction = $actual - $current;
                $line->forceFill(['difference' => $this->fromMilliunits($actual - $theoretical)])->save();

                if ($correction === 0) {
                    continue;
                }

                $stock->forceFill(['quantity' => $this->fromMilliunits($actual)])->save();
                StockMovement::query()->create([
                    'product_id' => $line->product_id,
                    'warehouse_id' => $warehouse->id,
                    'type' => $correction > 0 ? 'adjustment_positive' : 'adjustment_negative',
                    'quantity' => $this->fromMilliunits(abs($correction)),
                    'quantity_before' => $this->fromMilliunits($current),
                    'quantity_after' => $this->fromMilliunits($actual),
                    'reference' => $inventory->reference,
                    'notes' => $this->movementNotes($inventory, $line),
                    'performed_by' => auth()->id(),
                ]);
            }

            $inventory->forceFill([
                'status' => StockInventory::STATUS_VALIDATED,
                'validated_by' => auth()->id(),
                'validated_at' => now(),
            ])->save();

            return $inventory->fresh(['warehouse', 'starter', 'validator', 'lines.product.unit']);
        }, 3);
    }

    private function ensureEditable(StockInventory $inventory): void
    {
        if (! $inventory->isEditable()) {
            throw ValidationException::withMessages([
                'inventory' => __('Cet inventaire est clôturé et ne peut plus être modifié.'),
            ]);
        }
    }

    /** @param Collection<int, int> $productIds */
    private function ensureStockRowsExist(int $warehouseId, Collection $productIds): void
    {
        foreach ($productIds as $productId) {
            WarehouseStock::query()->firstOrCreate(
                ['warehouse_id' => $warehouseId, 'product_id' => $productId],
                ['quantity' => '0.000'],
            );
        }
    }

    private function movementNotes(StockInventory $inventory, StockInventoryLine $line): string
    {
        $notes = __('Correction issue de l’inventaire :reference', ['reference' => $inventory->reference]);

        return $line->notes ? $notes.' — '.$line->notes : $notes;
    }

    private function normalizeNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        if (mb_strlen($notes) > 5000) {
            throw ValidationException::withMessages(['notes' => __('La note ne peut pas dépasser 5000 caractères.')]);
        }

        return $notes === '' ? null : $notes;
    }

    private function toMilliunits(string|int|float $quantity): int
    {
        $value = trim((string) $quantity);

        if (! preg_match('/^\d{1,12}(?:\.\d{1,3})?$/', $value)) {
            throw ValidationException::withMessages([
                'actualQuantity' => __('La quantité doit être positive ou nulle et contenir au maximum trois décimales.'),
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
    }

    private function fromMilliunits(int $quantity): string
    {
        $sign = $quantity < 0 ? '-' : '';
        $absolute = abs($quantity);

        return $sign.intdiv($absolute, 1000).'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
    }
}
