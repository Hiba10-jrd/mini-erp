<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GoodsReceiptManagementService
{
    public function __construct(
        private readonly DocumentSequenceManagementService $sequences,
        private readonly StockManagementService $stock,
    ) {}

    /** @param array{warehouse_id: int, receipt_date: string, notes?: ?string, items?: array<int|string, array{purchase_order_item_id: int|string, quantity: int|float|string}>} $attributes */
    public function createDraft(PurchaseOrder $order, array $attributes): GoodsReceipt
    {
        Gate::authorize('purchases.create');

        return DB::transaction(function () use ($order, $attributes): GoodsReceipt {
            $lockedOrder = PurchaseOrder::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            $this->ensureOrderEligible($lockedOrder);

            $existingDraft = GoodsReceipt::query()
                ->where('purchase_order_id', $lockedOrder->id)
                ->where('status', GoodsReceipt::STATUS_DRAFT)
                ->latest('created_at')
                ->first();
            if ($existingDraft !== null) {
                return $this->load($existingDraft);
            }

            $header = $this->validateHeader($attributes);
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($header['warehouse_id']);
            $this->ensureWarehouseActive($warehouse);
            $number = $this->sequences->allocate('goods_receipt', Carbon::parse($header['receipt_date'])->year);

            $receipt = new GoodsReceipt([
                'purchase_order_id' => $lockedOrder->id,
                'warehouse_id' => $warehouse->id,
                'receipt_date' => $header['receipt_date'],
                'notes' => $header['notes'],
            ]);
            $receipt->forceFill([
                'number' => $number,
                'status' => GoodsReceipt::STATUS_DRAFT,
                'created_by' => Auth::id(),
            ])->save();
            $items = $attributes['items'] ?? $this->defaultItems($lockedOrder);
            $this->replaceDraftItems($receipt, $lockedOrder, $items);
            $this->recordHistory($receipt, 'created', null, GoodsReceipt::STATUS_DRAFT, __('Réception fournisseur créée.'), null, 'Réception fournisseur créée.');
            $this->recordOrderHistory($lockedOrder, 'goods_receipt_created', __('Réception :number créée.', ['number' => $receipt->number]), $receipt, 'Réception :number créée.', ['number' => $receipt->number]);

            return $this->load($receipt);
        }, 3);
    }

    /** @param array{warehouse_id: int, receipt_date: string, notes?: ?string, items?: array<int|string, array{purchase_order_item_id: int|string, quantity: int|float|string}>} $attributes */
    public function updateDraft(GoodsReceipt $receipt, array $attributes): GoodsReceipt
    {
        Gate::authorize('purchases.update');

        return DB::transaction(function () use ($receipt, $attributes): GoodsReceipt {
            $locked = GoodsReceipt::query()->with('items')->lockForUpdate()->findOrFail($receipt->id);
            $this->ensureEditable($locked);
            $order = PurchaseOrder::query()->with('items')->lockForUpdate()->findOrFail($locked->purchase_order_id);
            $this->ensureOrderEligible($order);
            $header = $this->validateHeader($attributes);
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($header['warehouse_id']);
            $this->ensureWarehouseActive($warehouse);

            $locked->fill([
                'warehouse_id' => $warehouse->id,
                'receipt_date' => $header['receipt_date'],
                'notes' => $header['notes'],
            ])->save();
            if (array_key_exists('items', $attributes)) {
                $this->replaceDraftItems($locked, $order, $attributes['items']);
            }
            $this->recordHistory($locked, 'draft_updated', GoodsReceipt::STATUS_DRAFT, GoodsReceipt::STATUS_DRAFT, __('Brouillon de réception modifié.'), null, 'Brouillon de réception modifié.');

            return $this->load($locked);
        }, 3);
    }

    public function validate(GoodsReceipt $receipt): GoodsReceipt
    {
        Gate::authorize('purchases.update');

        return DB::transaction(function () use ($receipt): GoodsReceipt {
            $locked = GoodsReceipt::query()->with('items')->lockForUpdate()->findOrFail($receipt->id);
            $this->ensureEditable($locked);
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($locked->purchase_order_id);
            $this->ensureOrderEligible($order);
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($locked->warehouse_id);
            $this->ensureWarehouseActive($warehouse);

            if ($locked->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => __('Une réception doit contenir au moins une ligne.')]);
            }

            $itemIds = $locked->items->pluck('purchase_order_item_id')->unique()->values();
            $orderItems = PurchaseOrderItem::query()
                ->where('purchase_order_id', $order->id)
                ->whereKey($itemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $received = $this->validatedQuantities($itemIds->all());

            foreach ($locked->items as $index => $line) {
                $orderItem = $orderItems->get($line->purchase_order_item_id);
                if ($orderItem === null) {
                    throw ValidationException::withMessages(["items.{$index}" => __('Une ligne n’appartient plus à la commande source.')]);
                }

                $remaining = BigDecimal::of($orderItem->quantity)
                    ->minus($received[$orderItem->id] ?? '0.000')
                    ->toScale(3);
                $quantity = BigDecimal::of($line->quantity)->toScale(3);
                if ($quantity->isLessThanOrEqualTo(0) || $quantity->isGreaterThan($remaining)) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => __('La quantité reçue dépasse le restant de la commande.')]);
                }

                if ($line->item_type === 'product') {
                    if ($orderItem->product_id === null || $line->product_id === null || $line->product_id !== $orderItem->product_id) {
                        throw ValidationException::withMessages(["items.{$index}.product_id" => __('Le produit physique de la ligne n’est plus disponible.')]);
                    }
                    $this->stock->receivePurchaseReceipt(
                        $line->product_id,
                        $warehouse->id,
                        $line->quantity,
                        $locked->number,
                        __('Réception fournisseur :receipt / commande :order', ['receipt' => $locked->number, 'order' => $order->number]),
                    );
                }
            }

            $locked->forceFill([
                'status' => GoodsReceipt::STATUS_VALIDATED,
                'validated_by' => Auth::id(),
                'validated_at' => now(),
            ])->save();
            $this->recordHistory($locked, 'validated', GoodsReceipt::STATUS_DRAFT, GoodsReceipt::STATUS_VALIDATED, __('Réception fournisseur validée.'), null, 'Réception fournisseur validée.');
            $this->recordOrderHistory($order, 'goods_receipt_validated', __('Réception :number validée.', ['number' => $locked->number]), $locked, 'Réception :number validée.', ['number' => $locked->number]);

            return $this->load($locked);
        }, 3);
    }

    public function cancelDraft(GoodsReceipt $receipt): GoodsReceipt
    {
        Gate::authorize('purchases.delete');

        return DB::transaction(function () use ($receipt): GoodsReceipt {
            $locked = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $this->ensureEditable($locked);
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($locked->purchase_order_id);
            $locked->forceFill(['status' => GoodsReceipt::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
            $this->recordHistory($locked, 'cancelled', GoodsReceipt::STATUS_DRAFT, GoodsReceipt::STATUS_CANCELLED, __('Réception fournisseur annulée.'), null, 'Réception fournisseur annulée.');
            $this->recordOrderHistory($order, 'goods_receipt_cancelled', __('Réception :number annulée.', ['number' => $locked->number]), $locked, 'Réception :number annulée.', ['number' => $locked->number]);

            return $this->load($locked);
        }, 3);
    }

    /** @return array<int, array{item: PurchaseOrderItem, ordered: string, received: string, remaining: string}> */
    public function availableItemsForOrder(PurchaseOrder $order): array
    {
        abort_unless(Gate::allows('purchases.view') || Gate::allows('purchases.create') || Gate::allows('purchases.update'), 403);
        $order->loadMissing('items');
        $ids = $order->items->pluck('id')->all();
        $received = $this->validatedQuantities($ids);
        $available = [];

        foreach ($order->items as $item) {
            $receivedQuantity = $received[$item->id] ?? '0.000';
            $remaining = (string) BigDecimal::of($item->quantity)->minus($receivedQuantity)->toScale(3);
            $available[$item->id] = [
                'item' => $item,
                'ordered' => $item->quantity,
                'received' => $receivedQuantity,
                'remaining' => $remaining,
            ];
        }

        return $available;
    }

    public function hasRemaining(PurchaseOrder $order): bool
    {
        return collect($this->availableItemsForOrder($order))
            ->contains(fn (array $line): bool => BigDecimal::of($line['remaining'])->isGreaterThan(0));
    }

    /** @return array{warehouse_id: int, receipt_date: string, notes: ?string} */
    private function validateHeader(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'receipt_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return [
            'warehouse_id' => (int) $validated['warehouse_id'],
            'receipt_date' => Carbon::parse($validated['receipt_date'])->toDateString(),
            'notes' => $this->nullableString($validated['notes'] ?? null),
        ];
    }

    /** @return array<int, array{purchase_order_item_id: int, quantity: string}> */
    private function defaultItems(PurchaseOrder $order): array
    {
        return collect($this->availableItemsForOrder($order))
            ->filter(fn (array $line): bool => BigDecimal::of($line['remaining'])->isGreaterThan(0))
            ->map(fn (array $line): array => [
                'purchase_order_item_id' => $line['item']->id,
                'quantity' => $line['remaining'],
            ])->values()->all();
    }

    /** @param array<int|string, array{purchase_order_item_id: int|string, quantity: int|float|string}> $items */
    private function replaceDraftItems(GoodsReceipt $receipt, PurchaseOrder $order, array $items): void
    {
        if ($items === [] || count($items) > 100) {
            throw ValidationException::withMessages(['items' => __('Une réception doit contenir entre 1 et 100 lignes.')]);
        }

        $seen = [];
        $prepared = [];
        $received = $this->validatedQuantities($order->items->pluck('id')->all());
        foreach (array_values($items) as $index => $line) {
            $itemId = filter_var($line['purchase_order_item_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = trim((string) ($line['quantity'] ?? ''));
            if ($itemId === false || ! preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,3})?$/D', $quantity)) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => __('La quantité doit être positive avec au plus trois décimales.')]);
            }
            if (isset($seen[$itemId])) {
                throw ValidationException::withMessages(["items.{$index}.purchase_order_item_id" => __('Une ligne de commande ne peut apparaître qu’une fois.')]);
            }
            $seen[$itemId] = true;
            $orderItem = PurchaseOrderItem::query()->where('purchase_order_id', $order->id)->whereKey($itemId)->lockForUpdate()->first();
            if ($orderItem === null) {
                throw ValidationException::withMessages(["items.{$index}.purchase_order_item_id" => __('La ligne n’appartient pas à cette commande fournisseur.')]);
            }
            $requested = BigDecimal::of($quantity)->toScale(3);
            $remaining = BigDecimal::of($orderItem->quantity)->minus($received[$orderItem->id] ?? '0.000')->toScale(3);
            if ($requested->isLessThanOrEqualTo(0) || $requested->isGreaterThan($remaining)) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => __('La quantité doit être positive et ne pas dépasser le restant à recevoir.')]);
            }
            $prepared[] = [
                'purchase_order_item_id' => $orderItem->id,
                'product_id' => $orderItem->product_id,
                'item_type' => $orderItem->item_type,
                'reference' => $orderItem->reference,
                'description' => $orderItem->description,
                'unit_label' => $orderItem->unit_label,
                'quantity' => (string) $requested,
                'position' => $index + 1,
            ];
        }

        $receipt->items()->get()->each->delete();
        $receipt->items()->createMany($prepared);
    }

    /** @param array<int, int> $itemIds @return array<int, string> */
    private function validatedQuantities(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return GoodsReceiptItem::query()
            ->selectRaw('purchase_order_item_id, SUM(quantity) as received_quantity')
            ->whereIn('purchase_order_item_id', $itemIds)
            ->whereHas('goodsReceipt', fn ($query) => $query->where('status', GoodsReceipt::STATUS_VALIDATED))
            ->groupBy('purchase_order_item_id')
            ->pluck('received_quantity', 'purchase_order_item_id')
            ->map(fn ($quantity): string => (string) BigDecimal::of($quantity)->toScale(3))
            ->all();
    }

    private function ensureOrderEligible(PurchaseOrder $order): void
    {
        if ($order->status !== PurchaseOrder::STATUS_CONFIRMED) {
            throw ValidationException::withMessages(['purchase_order_id' => __('Seule une commande fournisseur confirmée peut être réceptionnée.')]);
        }
        if (! $this->hasRemaining($order)) {
            throw ValidationException::withMessages(['purchase_order_id' => __('Aucune quantité ne reste à recevoir sur cette commande.')]);
        }
    }

    private function ensureWarehouseActive(Warehouse $warehouse): void
    {
        if (! $warehouse->is_active) {
            throw ValidationException::withMessages(['warehouse_id' => __('Le dépôt sélectionné est inactif.')]);
        }
    }

    private function ensureEditable(GoodsReceipt $receipt): void
    {
        if (! $receipt->isEditable()) {
            throw ValidationException::withMessages(['status' => __('Seul un brouillon de réception peut être modifié.')]);
        }
    }

    /** @param array<string, mixed>|null $metadata */
    private function recordHistory(
        GoodsReceipt $receipt,
        string $event,
        ?string $from,
        ?string $to,
        string $description,
        ?array $metadata = null,
        ?string $descriptionKey = null,
        array $descriptionParams = []
    ): void {
        $meta = $metadata ?? [];
        $meta['description_key'] = $descriptionKey ?? $description;
        $meta['description_params'] = $descriptionParams;

        $receipt->histories()->create([
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => Auth::id(),
            'description' => $description,
            'metadata' => $meta,
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed>|null $metadata */
    private function recordOrderHistory(
        PurchaseOrder $order,
        string $event,
        string $description,
        GoodsReceipt $receipt,
        ?string $descriptionKey = null,
        array $descriptionParams = [],
        ?array $metadata = null
    ): void {
        $meta = array_merge([
            'goods_receipt_id' => $receipt->id,
            'goods_receipt_number' => $receipt->number,
            'description_key' => $descriptionKey ?? $description,
            'description_params' => $descriptionParams,
        ], $metadata ?? []);

        $order->histories()->create([
            'event' => $event,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'user_id' => Auth::id(),
            'description' => $description,
            'metadata' => $meta,
            'created_at' => now(),
        ]);
    }

    private function load(GoodsReceipt $receipt): GoodsReceipt
    {
        return $receipt->fresh(['purchaseOrder', 'warehouse', 'creator', 'validator', 'items', 'histories.user']);
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
