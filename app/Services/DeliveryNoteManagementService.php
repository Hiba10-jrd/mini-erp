<?php

namespace App\Services;

use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Models\SalesOrderHistory;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeliveryNoteManagementService
{
    public function __construct(
        private readonly DocumentSequenceManagementService $sequences,
    ) {}

    public function createForOrder(SalesOrder $order, array $attributes = []): DeliveryNote
    {
        Gate::authorize('sales.create');

        return DB::transaction(function () use ($order, $attributes): DeliveryNote {
            $lockedOrder = SalesOrder::query()->with('items.product')->lockForUpdate()->findOrFail($order->id);
            $this->ensureOrderEligible($lockedOrder);

            $existingDraft = DeliveryNote::query()
                ->where('sales_order_id', $lockedOrder->id)
                ->where('status', 'draft')
                ->orderByDesc('created_at')
                ->first();

            if ($existingDraft !== null) {
                return $existingDraft->fresh(['salesOrder', 'warehouse', 'items', 'creator']);
            }

            $warehouseId = isset($attributes['warehouse_id']) ? (int) $attributes['warehouse_id'] : null;
            $hasPhysicalItem = $lockedOrder->items->contains(fn ($item): bool => $item->item_type === 'product' || ($item->product_id !== null && $item->product?->type === 'product'));

            if ($hasPhysicalItem && $warehouseId === null) {
                throw ValidationException::withMessages(['warehouse_id' => __('Un bon de livraison avec produits physiques exige un dépôt.')]);
            }

            if ($warehouseId !== null) {
                $warehouse = Warehouse::query()->findOrFail($warehouseId);
                if (! $warehouse->is_active) {
                    throw ValidationException::withMessages(['warehouse_id' => __('Le dépôt sélectionné est inactif.')]);
                }
            }

            $deliveryDate = $attributes['delivery_date'] ?? today()->toDateString();
            $number = $this->sequences->allocate('delivery_note', (int) date('Y', strtotime($deliveryDate)));

            $deliveryNote = DeliveryNote::query()->create([
                'number' => $number,
                'sales_order_id' => $lockedOrder->id,
                'warehouse_id' => $warehouseId,
                'status' => 'draft',
                'delivery_date' => $deliveryDate,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $items = $attributes['items'] ?? $lockedOrder->items
                ->filter(fn (SalesOrderItem $item): bool => $this->remainingForItem($item) > 0)
                ->map(fn (SalesOrderItem $item): array => [
                    'sales_order_item_id' => $item->id,
                    'quantity' => (string) BigDecimal::of((string) $item->ordered_quantity)->minus((string) $item->delivered_quantity)->toScale(3),
                ])
                ->all();
            if (! is_array($items)) {
                throw ValidationException::withMessages(['items' => __('Les lignes du bon de livraison sont invalides.')]);
            }
            $this->replaceDraftItems($deliveryNote, $lockedOrder, $items);

            $this->recordOrderHistory(
                $lockedOrder,
                'delivery_note_created',
                $lockedOrder->status,
                $lockedOrder->status,
                __('Bon de livraison :number créé.', ['number' => $deliveryNote->number]),
                [
                    'delivery_note_id' => $deliveryNote->id,
                    'delivery_note_number' => $deliveryNote->number,
                    'warehouse_id' => $warehouseId,
                    'description_key' => 'Bon de livraison :number créé.',
                    'description_params' => ['number' => $deliveryNote->number],
                ]
            );

            return $deliveryNote->fresh(['salesOrder', 'warehouse', 'items', 'creator']);
        }, 3);
    }

    public function updateDraft(DeliveryNote $deliveryNote, array $attributes = []): DeliveryNote
    {
        Gate::authorize('sales.update');

        return DB::transaction(function () use ($deliveryNote, $attributes): DeliveryNote {
            $locked = DeliveryNote::query()->with('items')->lockForUpdate()->findOrFail($deliveryNote->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => __('Seul un brouillon peut être modifié.')]);
            }

            $order = SalesOrder::query()->lockForUpdate()->findOrFail($locked->sales_order_id);
            $locked->fill([
                'warehouse_id' => array_key_exists('warehouse_id', $attributes) ? $attributes['warehouse_id'] : $locked->warehouse_id,
                'delivery_date' => $attributes['delivery_date'] ?? $locked->delivery_date,
                'notes' => $attributes['notes'] ?? $locked->notes,
            ]);

            $hasPhysicalItem = $order->items()->where(function ($query): void {
                $query->where('item_type', 'product')->orWhereHas('product', fn ($productQuery) => $productQuery->where('type', 'product'));
            })->exists();
            if ($hasPhysicalItem && $locked->warehouse_id === null) {
                throw ValidationException::withMessages(['warehouse_id' => __('Un bon de livraison avec produits physiques exige un dépôt.')]);
            }

            if ($locked->warehouse_id !== null) {
                $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);
                if (! $warehouse->is_active) {
                    throw ValidationException::withMessages(['warehouse_id' => __('Le dépôt sélectionné est inactif.')]);
                }
            }

            $locked->save();

            if (isset($attributes['items']) && is_array($attributes['items'])) {
                $this->replaceDraftItems($locked, $order, $attributes['items']);
            }

            return $locked->fresh(['salesOrder', 'warehouse', 'items', 'creator']);
        }, 3);
    }

    public function cancelDraft(DeliveryNote $deliveryNote): DeliveryNote
    {
        Gate::authorize('sales.delete');

        return DB::transaction(function () use ($deliveryNote): DeliveryNote {
            $locked = DeliveryNote::query()->lockForUpdate()->findOrFail($deliveryNote->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => __('Seul un brouillon peut être annulé.')]);
            }

            $locked->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ])->save();

            return $locked->fresh(['salesOrder', 'warehouse', 'items', 'creator']);
        }, 3);
    }

    public function validate(DeliveryNote $deliveryNote): DeliveryNote
    {
        Gate::authorize('sales.update');

        return DB::transaction(function () use ($deliveryNote): DeliveryNote {
            $locked = DeliveryNote::query()->with(['items', 'salesOrder.items'])->lockForUpdate()->findOrFail($deliveryNote->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => __('Ce bon de livraison n’est pas un brouillon.')]);
            }

            $order = $locked->salesOrder()->lockForUpdate()->firstOrFail();
            $this->ensureOrderEligible($order);

            $orderItems = SalesOrderItem::query()->whereIn('id', $locked->items->pluck('sales_order_item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $warehouseId = $locked->warehouse_id;
            $physicalItems = [];

            foreach ($locked->items as $line) {
                $salesOrderItem = $orderItems->get($line->sales_order_item_id);
                if ($salesOrderItem === null) {
                    throw ValidationException::withMessages(['delivery_note' => __('Une ligne du bon de livraison n’existe plus dans la commande.')]);
                }

                $remaining = $this->remainingForItem($salesOrderItem);
                if ((float) $line->quantity <= 0 || (float) $line->quantity > (float) $remaining) {
                    throw ValidationException::withMessages(['quantity' => __('La quantité livrée dépasse le reste de commande.')]);
                }

                if ($line->item_type === 'product' && $warehouseId !== null) {
                    $physicalItems[] = ['line' => $line, 'salesOrderItem' => $salesOrderItem, 'warehouse_id' => $warehouseId];
                }
            }

            if ($warehouseId !== null) {
                $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);
                if (! $warehouse->is_active) {
                    throw ValidationException::withMessages(['warehouse_id' => __('Le dépôt sélectionné est inactif.')]);
                }
            }

            $stockLocks = [];
            foreach ($physicalItems as $itemData) {
                $stock = WarehouseStock::query()
                    ->where('warehouse_id', $itemData['warehouse_id'])
                    ->where('product_id', $itemData['salesOrderItem']->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($stock === null) {
                    $stock = WarehouseStock::query()->create([
                        'warehouse_id' => $itemData['warehouse_id'],
                        'product_id' => $itemData['salesOrderItem']->product_id,
                        'quantity' => '0.000',
                    ]);
                }

                $before = (string) $stock->quantity;
                $after = (string) BigDecimal::of($before)->minus($itemData['line']->quantity)->toScale(3);
                if ((float) $after < 0) {
                    throw ValidationException::withMessages(['quantity' => __('Le stock disponible est insuffisant pour cette livraison.')]);
                }

                $stockLocks[] = ['stock' => $stock, 'before' => $before, 'after' => $after, 'quantity' => (string) $itemData['line']->quantity, 'reference' => $itemData['salesOrderItem']->reference];
            }

            foreach ($stockLocks as $stockLock) {
                $stockLock['stock']->forceFill(['quantity' => $stockLock['after']])->save();
                StockMovement::query()->create([
                    'product_id' => $stockLock['stock']->product_id,
                    'warehouse_id' => $stockLock['stock']->warehouse_id,
                    'type' => 'delivery_out',
                    'quantity' => $stockLock['quantity'],
                    'quantity_before' => $stockLock['before'],
                    'quantity_after' => $stockLock['after'],
                    'reference' => $locked->number,
                    'notes' => __('Livraison client via :delivery / :order', [
                        'delivery' => $locked->number,
                        'order' => $order->number,
                    ]),
                    'performed_by' => Auth::id(),
                ]);
            }

            foreach ($locked->items as $line) {
                $salesOrderItem = $orderItems->get($line->sales_order_item_id);
                $increment = (string) BigDecimal::of((string) $salesOrderItem->delivered_quantity)->plus($line->quantity)->toScale(3);
                DB::table('sales_order_items')->where('id', $salesOrderItem->id)->update(['delivered_quantity' => $increment]);
            }

            $newStatus = $this->resolveOrderStatus($order->id);
            DB::table('sales_orders')->where('id', $order->id)->update([
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

            $this->recordOrderHistory($order, 'delivery_note_validated', $order->status, $newStatus, __('BL :number validé depuis :warehouse.', ['number' => $locked->number, 'warehouse' => $warehouseId !== null ? $warehouse->code : '—']), [
                'delivery_note_id' => $locked->id,
                'delivery_note_number' => $locked->number,
                'warehouse_id' => $warehouseId,
                'description_key' => 'BL :number validé depuis :warehouse.',
                'description_params' => ['number' => $locked->number, 'warehouse' => $warehouseId !== null ? $warehouse->code : '—'],
            ]);

            $locked->forceFill([
                'status' => 'validated',
                'validated_by' => Auth::id(),
                'validated_at' => now(),
            ])->save();

            return $locked->fresh(['salesOrder', 'warehouse', 'items', 'creator', 'validator']);
        }, 3);
    }

    private function ensureOrderEligible(SalesOrder $order): void
    {
        $admissible = [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_DELIVERED];
        if (! in_array($order->status, $admissible, true)) {
            throw ValidationException::withMessages(['sales_order_id' => __('Une commande non confirmée ne peut pas recevoir de bon de livraison.')]);
        }

        $remaining = $order->items->sum(fn ($item): float => (float) $this->remainingForItem($item));
        if ($remaining <= 0) {
            throw ValidationException::withMessages(['sales_order_id' => __('Aucune quantité n’est restante à livrer sur cette commande.')]);
        }
    }

    private function remainingForItem(SalesOrderItem $item): float
    {
        return (float) BigDecimal::of((string) $item->ordered_quantity)->minus((string) $item->delivered_quantity)->toScale(3)->toFloat();
    }

    /** @param array<int|string, array{sales_order_item_id: int|string, quantity: int|float|string}> $items */
    private function replaceDraftItems(DeliveryNote $deliveryNote, SalesOrder $order, array $items): void
    {
        $seen = [];
        $validatedItems = [];

        foreach ($items as $index => $line) {
            $itemId = filter_var($line['sales_order_item_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = trim((string) ($line['quantity'] ?? ''));
            if ($itemId === false || ! preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,3})?$/D', $quantity)) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('La quantité doit être un nombre positif avec au plus trois décimales.')]);
            }

            if (isset($seen[$itemId])) {
                throw ValidationException::withMessages(["lines.{$index}.sales_order_item_id" => __('Une même ligne de commande ne peut apparaître qu’une fois.')]);
            }
            $seen[$itemId] = true;

            $item = SalesOrderItem::query()
                ->where('sales_order_id', $order->id)
                ->whereKey($itemId)
                ->lockForUpdate()
                ->first();
            if ($item === null) {
                throw ValidationException::withMessages(["lines.{$index}.sales_order_item_id" => __('La ligne sélectionnée n’appartient pas à cette commande.')]);
            }

            $requested = BigDecimal::of($quantity)->toScale(3);
            $remaining = BigDecimal::of((string) $item->ordered_quantity)->minus((string) $item->delivered_quantity)->toScale(3);
            if ($requested->compareTo(BigDecimal::zero()) <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('La quantité doit être supérieure à zéro.')]);
            }
            if ($requested->compareTo($remaining) > 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('La quantité demandée dépasse le restant à livrer.')]);
            }

            $validatedItems[] = ['item' => $item, 'quantity' => (string) $requested];
        }

        if ($validatedItems === []) {
            throw ValidationException::withMessages(['items' => __('Le bon de livraison doit contenir au moins une quantité positive.')]);
        }

        $deliveryNote->items()->delete();
        foreach ($validatedItems as $position => $validatedItem) {
            /** @var SalesOrderItem $item */
            $item = $validatedItem['item'];
            $deliveryNote->items()->create([
                'sales_order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'item_type' => $item->item_type,
                'reference' => $item->reference,
                'description' => $item->description,
                'unit_label' => $item->unit_label,
                'quantity' => $validatedItem['quantity'],
                'position' => $position + 1,
            ]);
        }
    }

    private function resolveOrderStatus(int $orderId): string
    {
        $order = SalesOrder::query()->with('items')->findOrFail($orderId);
        $remaining = $order->items->sum(fn ($item): float => (float) $this->remainingForItem($item));
        $allDelivered = $order->items->every(fn ($item): bool => (float) $item->delivered_quantity >= (float) $item->ordered_quantity);

        if ($remaining <= 0 && $allDelivered) {
            return SalesOrder::STATUS_DELIVERED;
        }

        if ($remaining > 0 && $order->items->some(fn ($item): bool => (float) $item->delivered_quantity > 0)) {
            return SalesOrder::STATUS_PARTIALLY_DELIVERED;
        }

        return SalesOrder::STATUS_CONFIRMED;
    }

    private function recordOrderHistory(SalesOrder $order, string $event, ?string $fromStatus, ?string $toStatus, string $description, array $metadata = []): void
    {
        SalesOrderHistory::query()->create([
            'sales_order_id' => $order->id,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'user_id' => Auth::id(),
            'description' => $description,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
