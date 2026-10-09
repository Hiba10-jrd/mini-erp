<?php

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use App\Services\GoodsReceiptManagementService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public ?int $receiptId = null;

    public string $purchaseOrderId = '';

    public string $warehouseId = '';

    public string $receiptDate = '';

    public string $notes = '';

    /** @var array<int, array{purchase_order_item_id: string, quantity: string}> */
    public array $lines = [];

    public function mount(?int $receiptId = null, ?int $orderId = null): void
    {
        $this->receiptDate = today()->toDateString();
        if ($receiptId !== null) {
            Gate::authorize('purchases.update');
            $receipt = GoodsReceipt::query()->with(['items', 'purchaseOrder'])->findOrFail($receiptId);
            abort_unless($receipt->isEditable(), 403);
            $this->receiptId = $receipt->id;
            $this->purchaseOrderId = (string) $receipt->purchase_order_id;
            $this->warehouseId = (string) $receipt->warehouse_id;
            $this->receiptDate = $receipt->receipt_date->toDateString();
            $this->notes = $receipt->notes ?? '';
            $this->initializeLines($receipt->purchaseOrder, app(GoodsReceiptManagementService::class));
            foreach ($receipt->items as $item) {
                $this->lines[$item->purchase_order_item_id]['quantity'] = $item->quantity;
            }

            return;
        }

        Gate::authorize('purchases.create');
        if ($orderId !== null) {
            $order = PurchaseOrder::query()->findOrFail($orderId);
            $this->purchaseOrderId = (string) $order->id;
            $this->initializeLines($order, app(GoodsReceiptManagementService::class), true);
        }
    }

    public function updatedPurchaseOrderId(GoodsReceiptManagementService $service): void
    {
        $this->authorizeMutation();
        $this->lines = [];
        if ($this->purchaseOrderId !== '') {
            $this->initializeLines(PurchaseOrder::query()->findOrFail($this->purchaseOrderId), $service, true);
        }
    }

    public function save(GoodsReceiptManagementService $service): void
    {
        $this->authorizeMutation();
        $items = collect($this->lines)->filter(function (array $line): bool {
            try {
                return BigDecimal::of((string) ($line['quantity'] ?? '0'))->isGreaterThan(0);
            } catch (\Throwable) {
                return true;
            }
        })->values()->all();
        $attributes = [
            'warehouse_id' => $this->warehouseId,
            'receipt_date' => $this->receiptDate,
            'notes' => $this->notes,
            'items' => $items,
        ];
        $receipt = $this->receiptId === null
            ? $service->createDraft(PurchaseOrder::query()->findOrFail($this->purchaseOrderId), $attributes)
            : $service->updateDraft(GoodsReceipt::query()->findOrFail($this->receiptId), $attributes);
        $this->redirectRoute('purchases.receipts.show', ['goodsReceipt' => $receipt], navigate: true);
    }

    public function with(GoodsReceiptManagementService $service): array
    {
        $this->authorizeMutation();
        $order = $this->resolveOrder();

        $orders = PurchaseOrder::query()
            ->with('items')
            ->where('status', PurchaseOrder::STATUS_CONFIRMED)
            ->latest('order_date')
            ->get()
            ->filter(fn (PurchaseOrder $candidate): bool => $service->hasRemaining($candidate));

        return [
            'order' => $order,
            'available' => $order === null ? [] : $service->availableItemsForOrder($order),
            'orders' => $orders,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }

    private function initializeLines(PurchaseOrder $order, GoodsReceiptManagementService $service, bool $fillRemaining = false): void
    {
        abort_unless($order->status === PurchaseOrder::STATUS_CONFIRMED, 403);
        foreach ($service->availableItemsForOrder($order) as $line) {
            if (! BigDecimal::of($line['remaining'])->isGreaterThan(0)) {
                continue;
            }
            $this->lines[$line['item']->id] = [
                'purchase_order_item_id' => (string) $line['item']->id,
                'quantity' => $fillRemaining ? $line['remaining'] : '0.000',
            ];
        }
    }

    private function authorizeMutation(): void
    {
        Gate::authorize($this->receiptId === null ? 'purchases.create' : 'purchases.update');
        if ($this->receiptId !== null) {
            abort_unless(GoodsReceipt::query()->findOrFail($this->receiptId)->isEditable(), 403);
        }
    }

    private function resolveOrder(): ?PurchaseOrder
    {
        if ($this->receiptId !== null) {
            return GoodsReceipt::query()->findOrFail($this->receiptId)->purchaseOrder;
        }
        if ($this->purchaseOrderId === '') {
            return null;
        }

        return PurchaseOrder::query()->findOrFail($this->purchaseOrderId);
    }
}; ?>

<section class="space-y-6">
    <div class="flex justify-end"><a href="{{ route('purchases.receipts.index') }}" wire:navigate class="text-sm font-medium text-indigo-700">{{ __('Retour aux réceptions') }}</a></div>
    <form wire:submit="save" class="space-y-6">
        <section class="border-y border-gray-200 bg-white p-5 sm:p-6"><h3 class="text-base font-semibold text-gray-900">{{ __('Réception fournisseur') }}</h3><div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2"><x-input-label for="receipt-order" :value="__('Commande fournisseur confirmée')" />@if($receiptId !== null || $order !== null && request()->routeIs('purchases.orders.receipts.create'))<x-text-input id="receipt-order" value="{{ $order?->number }} · {{ $order?->supplier_name }}" disabled class="mt-1 block w-full bg-gray-50" />@else<select id="receipt-order" wire:model.live="purchaseOrderId" required class="mt-1 block w-full border-gray-300 shadow-sm"><option value="">{{ __('Sélectionner une commande') }}</option>@foreach($orders as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->number }} · {{ $candidate->supplier_name }}</option>@endforeach</select>@endif<x-input-error :messages="$errors->get('purchase_order_id')" class="mt-2" /></div>
            <div><x-input-label for="receipt-date" :value="__('Date de réception')" /><x-text-input id="receipt-date" type="date" wire:model="receiptDate" required class="mt-1 block w-full" /><x-input-error :messages="$errors->get('receipt_date')" class="mt-2" /></div>
            <div><x-input-label for="receipt-warehouse-id" :value="__('Dépôt de réception')" /><select id="receipt-warehouse-id" wire:model="warehouseId" required class="mt-1 block w-full border-gray-300 shadow-sm"><option value="">{{ __('Sélectionner un dépôt') }}</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach</select><x-input-error :messages="$errors->get('warehouse_id')" class="mt-2" /></div>
            <div class="sm:col-span-2 lg:col-span-4"><x-input-label for="receipt-notes" :value="__('Notes')" /><textarea id="receipt-notes" wire:model="notes" rows="3" class="mt-1 block w-full border-gray-300 shadow-sm"></textarea><x-input-error :messages="$errors->get('notes')" class="mt-2" /></div>
        </div></section>

        @if($order)
            <section class="border-y border-gray-200 bg-white"><div class="border-b border-gray-200 px-5 py-4"><h3 class="font-semibold text-gray-900">{{ __('Quantités à recevoir') }}</h3><p class="mt-1 text-sm text-gray-500">{{ $order->supplier_name }}</p></div><x-input-error :messages="$errors->get('items')" class="mx-5 mt-3" /><div class="overflow-x-auto"><div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="bg-gray-50 text-start text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">{{ __('Article') }}</th><th class="px-4 py-3 text-end">{{ __('Commandé') }}</th><th class="px-4 py-3 text-end">{{ __('Déjà reçu') }}</th><th class="px-4 py-3 text-end">{{ __('Restant') }}</th><th class="px-4 py-3">{{ __('À recevoir') }}</th></tr></thead><tbody class="divide-y divide-gray-100">
                @forelse($available as $itemId => $line) @if(isset($lines[$itemId]))<tr wire:key="receipt-line-{{ $itemId }}"><td class="px-4 py-4"><p class="font-medium">{{ $line['item']->description }}</p><p class="text-xs text-gray-500">{{ $line['item']->reference ?? '—' }} · {{ $line['item']->item_type === 'service' ? __('Service') : __('Produit') }}</p></td><td class="px-4 py-4 text-end">{{ $line['ordered'] }}</td><td class="px-4 py-4 text-end">{{ $line['received'] }}</td><td class="px-4 py-4 text-end font-medium">{{ $line['remaining'] }}</td><td class="w-44 px-4 py-4"><x-text-input type="number" min="0" max="{{ $line['remaining'] }}" step="0.001" wire:model="lines.{{ $itemId }}.quantity" class="block w-full" /><x-input-error :messages="$errors->get('items.'.$loop->index.'.quantity')" class="mt-1" /></td></tr>@endif @empty<tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">{{ __('Aucune quantité ne reste à recevoir.') }}</td></tr>@endforelse
            </tbody></table></div></div></section>
        @endif
        <div class="flex justify-end"><x-primary-button :disabled="$order === null">{{ $receiptId === null ? __('Enregistrer le brouillon') : __('Enregistrer le brouillon') }}</x-primary-button></div>
    </form>
</section>
