<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockManagementService
{
    public function receive(int $productId, int $warehouseId, string|int|float $quantity, ?string $reference = null, ?string $notes = null): StockMovement
    {
        return $this->applyMovement($productId, $warehouseId, 'entry', $quantity, true, $reference, $notes);
    }

    public function receivePurchaseReceipt(int $productId, int $warehouseId, string|int|float $quantity, string $reference, ?string $notes = null): StockMovement
    {
        Gate::authorize('purchases.update');

        return $this->applyMovement($productId, $warehouseId, 'entry', $quantity, true, $reference, $notes, false);
    }

    public function issue(int $productId, int $warehouseId, string|int|float $quantity, ?string $reference = null, ?string $notes = null): StockMovement
    {
        return $this->applyMovement($productId, $warehouseId, 'exit', $quantity, false, $reference, $notes);
    }

    public function returnIn(int $productId, int $warehouseId, string|int|float $quantity, ?string $reference = null, ?string $notes = null): StockMovement
    {
        return $this->applyMovement($productId, $warehouseId, 'return_in', $quantity, true, $reference, $notes);
    }

    public function returnOut(int $productId, int $warehouseId, string|int|float $quantity, ?string $reference = null, ?string $notes = null): StockMovement
    {
        return $this->applyMovement($productId, $warehouseId, 'return_out', $quantity, false, $reference, $notes);
    }

    public function adjust(int $productId, int $warehouseId, string $direction, string|int|float $quantity, string $notes, ?string $reference = null): StockMovement
    {
        if (! in_array($direction, ['positive', 'negative'], true)) {
            throw ValidationException::withMessages(['adjustmentDirection' => __('Le sens de l’ajustement est invalide.')]);
        }

        if (trim($notes) === '') {
            throw ValidationException::withMessages(['notes' => __('Le motif de l’ajustement est obligatoire.')]);
        }

        return $this->applyMovement(
            $productId,
            $warehouseId,
            $direction === 'positive' ? 'adjustment_positive' : 'adjustment_negative',
            $quantity,
            $direction === 'positive',
            $reference,
            $notes,
        );
    }

    /** @return array{out: StockMovement, in: StockMovement} */
    public function transfer(int $productId, int $sourceWarehouseId, int $destinationWarehouseId, string|int|float $quantity, ?string $reference = null, ?string $notes = null): array
    {
        Gate::authorize('stock.manage');

        if ($sourceWarehouseId === $destinationWarehouseId) {
            throw ValidationException::withMessages([
                'destinationWarehouseId' => __('Les dépôts source et destination doivent être différents.'),
            ]);
        }

        $quantityUnits = $this->toMilliunits($quantity);
        [$reference, $notes] = $this->validateMetadata($reference, $notes);

        return DB::transaction(function () use ($productId, $sourceWarehouseId, $destinationWarehouseId, $quantityUnits, $reference, $notes): array {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $this->ensureStockableProduct($product);

            $warehouseIds = [$sourceWarehouseId, $destinationWarehouseId];
            sort($warehouseIds);
            $warehouses = Warehouse::query()
                ->whereKey($warehouseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($warehouses->count() !== 2) {
                throw ValidationException::withMessages(['warehouseId' => __('Un dépôt sélectionné est introuvable.')]);
            }

            $sourceWarehouse = $warehouses->get($sourceWarehouseId);
            $destinationWarehouse = $warehouses->get($destinationWarehouseId);
            $this->ensureActiveWarehouse($sourceWarehouse);
            $this->ensureActiveWarehouse($destinationWarehouse, 'destinationWarehouseId');

            $stocks = [];
            foreach ($warehouseIds as $warehouseId) {
                $stocks[$warehouseId] = $this->lockedStock($warehouseId, $productId);
            }

            $sourceStock = $stocks[$sourceWarehouseId];
            $destinationStock = $stocks[$destinationWarehouseId];
            $sourceBefore = $this->toMilliunits($sourceStock->quantity, false);
            $destinationBefore = $this->toMilliunits($destinationStock->quantity, false);
            $sourceAfter = $sourceBefore - $quantityUnits;

            if ($sourceAfter < 0) {
                throw ValidationException::withMessages(['quantity' => __('Le stock du dépôt source est insuffisant.')]);
            }

            $destinationAfter = $destinationBefore + $quantityUnits;
            $sourceStock->forceFill(['quantity' => $this->fromMilliunits($sourceAfter)])->save();
            $destinationStock->forceFill(['quantity' => $this->fromMilliunits($destinationAfter)])->save();
            $groupId = (string) Str::uuid();

            $out = $this->createMovement($productId, $sourceWarehouseId, 'transfer_out', $quantityUnits, $sourceBefore, $sourceAfter, $reference, $notes, $groupId);
            $in = $this->createMovement($productId, $destinationWarehouseId, 'transfer_in', $quantityUnits, $destinationBefore, $destinationAfter, $reference, $notes, $groupId);

            return ['out' => $out, 'in' => $in];
        }, 3);
    }

    private function applyMovement(int $productId, int $warehouseId, string $type, string|int|float $quantity, bool $isIncrease, ?string $reference, ?string $notes, bool $authorizeStock = true): StockMovement
    {
        if ($authorizeStock) {
            Gate::authorize('stock.manage');
        }
        $quantityUnits = $this->toMilliunits($quantity);
        [$reference, $notes] = $this->validateMetadata($reference, $notes);

        return DB::transaction(function () use ($productId, $warehouseId, $type, $quantityUnits, $isIncrease, $reference, $notes): StockMovement {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $this->ensureStockableProduct($product);
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);
            $this->ensureActiveWarehouse($warehouse);
            $stock = $this->lockedStock($warehouseId, $productId);
            $before = $this->toMilliunits($stock->quantity, false);
            $after = $isIncrease ? $before + $quantityUnits : $before - $quantityUnits;

            if ($after < 0) {
                throw ValidationException::withMessages(['quantity' => __('Le stock disponible est insuffisant.')]);
            }

            $stock->forceFill(['quantity' => $this->fromMilliunits($after)])->save();

            return $this->createMovement($productId, $warehouseId, $type, $quantityUnits, $before, $after, $reference, $notes);
        }, 3);
    }

    private function lockedStock(int $warehouseId, int $productId): WarehouseStock
    {
        WarehouseStock::query()->firstOrCreate(
            ['warehouse_id' => $warehouseId, 'product_id' => $productId],
            ['quantity' => '0.000'],
        );

        return WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function createMovement(int $productId, int $warehouseId, string $type, int $quantity, int $before, int $after, ?string $reference, ?string $notes, ?string $transferGroupId = null): StockMovement
    {
        return StockMovement::query()->create([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'type' => $type,
            'quantity' => $this->fromMilliunits($quantity),
            'quantity_before' => $this->fromMilliunits($before),
            'quantity_after' => $this->fromMilliunits($after),
            'reference' => $reference,
            'notes' => $notes,
            'performed_by' => auth()->id(),
            'transfer_group_id' => $transferGroupId,
        ]);
    }

    private function ensureStockableProduct(Product $product): void
    {
        if ($product->type !== 'product') {
            throw ValidationException::withMessages(['productId' => __('Une prestation de service ne peut pas avoir de stock.')]);
        }

        if (! $product->is_active) {
            throw ValidationException::withMessages(['productId' => __('Le produit sélectionné est inactif.')]);
        }
    }

    private function ensureActiveWarehouse(Warehouse $warehouse, string $key = 'warehouseId'): void
    {
        if (! $warehouse->is_active) {
            throw ValidationException::withMessages([$key => __('Le dépôt sélectionné est inactif.')]);
        }
    }

    /** @return array{0: string|null, 1: string|null} */
    private function validateMetadata(?string $reference, ?string $notes): array
    {
        $reference = trim((string) $reference) ?: null;
        $notes = trim((string) $notes) ?: null;

        if ($reference !== null && mb_strlen($reference) > 100) {
            throw ValidationException::withMessages(['reference' => __('La référence ne peut pas dépasser 100 caractères.')]);
        }

        if ($notes !== null && mb_strlen($notes) > 5000) {
            throw ValidationException::withMessages(['notes' => __('La note ne peut pas dépasser 5000 caractères.')]);
        }

        return [$reference, $notes];
    }

    private function toMilliunits(string|int|float $quantity, bool $mustBePositive = true): int
    {
        $value = trim((string) $quantity);

        if (! preg_match('/^\d{1,12}(?:\.\d{1,3})?$/', $value)) {
            throw ValidationException::withMessages(['quantity' => __('La quantité doit contenir au maximum trois décimales.')]);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $scaled = ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');

        if ($mustBePositive && $scaled <= 0) {
            throw ValidationException::withMessages(['quantity' => __('La quantité doit être strictement supérieure à zéro.')]);
        }

        return $scaled;
    }

    private function fromMilliunits(int $quantity): string
    {
        return intdiv($quantity, 1000).'.'.str_pad((string) ($quantity % 1000), 3, '0', STR_PAD_LEFT);
    }
}
