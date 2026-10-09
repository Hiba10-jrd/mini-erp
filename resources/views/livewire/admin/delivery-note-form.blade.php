<?php

use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\DeliveryNoteManagementService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public ?int $noteId = null;

    #[Locked]
    public ?int $orderId = null;

    public string $warehouseId = '';

    public string $deliveryDate = '';

    public string $notes = '';

    /** @var array<int, array{sales_order_item_id: string, quantity: string}> */
    public array $lines = [];

    public function mount(?int $noteId = null, ?int $orderId = null): void
    {
        $this->deliveryDate = today()->toDateString();

        if ($noteId !== null) {
            Gate::authorize('sales.update');
            $note = DeliveryNote::query()->with(['items', 'salesOrder.items'])->findOrFail($noteId);
            abort_unless($note->isEditable(), 403);

            $this->noteId = $note->id;
            $this->orderId = $note->sales_order_id;
            $this->warehouseId = (string) ($note->warehouse_id ?? '');
            $this->deliveryDate = $note->delivery_date->toDateString();
            $this->notes = $note->notes ?? '';
            foreach ($note->items as $item) {
                $this->lines[$item->sales_order_item_id] = [
                    'sales_order_item_id' => (string) $item->sales_order_item_id,
                    'quantity' => (string) $item->quantity,
                ];
            }

            return;
        }

        Gate::authorize('sales.create');
        $this->orderId = $orderId;
        $order = SalesOrder::query()->with('items')->findOrFail($orderId);

        foreach ($order->items as $item) {
            $remaining = $this->remainingForItem($item);
            if ($remaining <= 0) {
                continue;
            }

            $this->lines[$item->id] = [
                'sales_order_item_id' => (string) $item->id,
                'quantity' => (string) $remaining,
            ];
        }

        $this->warehouseId = $order->items->contains(fn ($item) => $item->item_type === 'product') ? '' : '0';
    }

    public function save(DeliveryNoteManagementService $service): void
    {
        $this->authorizeMutation();

        $attributes = [
            'warehouse_id' => $this->warehouseId !== '' && $this->warehouseId !== '0' ? (int) $this->warehouseId : null,
            'delivery_date' => $this->deliveryDate,
            'notes' => $this->notes,
            'items' => $this->lines,
        ];

        $note = $this->noteId === null
            ? $service->createForOrder(SalesOrder::query()->findOrFail($this->orderId), $attributes)
            : $service->updateDraft(DeliveryNote::query()->findOrFail($this->noteId), $attributes);

        $this->redirectRoute('sales.delivery-notes.show', ['deliveryNote' => $note], navigate: true);
    }

    public function with(): array
    {
        $order = $this->resolveOrder();

        return [
            'order' => $order,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
        ];
    }

    public function updatedWarehouseId(): void
    {
        $this->resetValidation('warehouse_id');
    }

    private function authorizeMutation(): void
    {
        Gate::authorize($this->noteId === null ? 'sales.create' : 'sales.update');
        if ($this->noteId !== null) {
            abort_unless(DeliveryNote::query()->findOrFail($this->noteId)->isEditable(), 403);
        }
    }

    private function resolveOrder(): SalesOrder
    {
        if ($this->noteId !== null) {
            $orderId = DeliveryNote::query()->findOrFail($this->noteId)->sales_order_id;
        } else {
            $orderId = $this->orderId;
        }

        return SalesOrder::query()->with('items')->findOrFail($orderId);
    }

    private function remainingForItem($item): float
    {
        return (float) BigDecimal::of((string) $item->ordered_quantity)->minus((string) $item->delivered_quantity)->toScale(3)->toFloat();
    }
}; ?>

<section class="space-y-6">
    <div class="flex justify-end">
        <a href="{{ route('sales.orders.show', $order) }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:text-indigo-900">{{ __('Retour à la commande') }}</a>
    </div>

    <form wire:submit="save" class="space-y-6">
        <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
            <h3 class="text-base font-semibold text-gray-900">{{ __('Bon de livraison') }}</h3>
            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <x-input-label for="delivery-note-order" :value="__('Commande')" />
                    <x-text-input id="delivery-note-order" value="{{ $order->number }}" disabled class="mt-1 block w-full bg-gray-50" />
                </div>
                <div>
                    <x-input-label for="delivery-note-date" :value="__('Date de livraison')" />
                    <x-text-input id="delivery-note-date" type="date" wire:model="deliveryDate" required class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('delivery_date')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="delivery-note-warehouse" :value="__('Dépôt')" />
                    <select id="delivery-note-warehouse" wire:model="warehouseId" class="mt-1 block w-full border-gray-300 shadow-sm">
                        <option value="">{{ __('À préciser si produit physique') }}</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('warehouse_id')" class="mt-2" />
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <x-input-label for="delivery-note-notes" :value="__('Notes')" />
                    <textarea id="delivery-note-notes" wire:model="notes" rows="3" class="mt-1 block w-full border-gray-300 shadow-sm"></textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                </div>
            </div>
        </section>

        <section class="border-y border-gray-200 bg-white">
            <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-5 py-4 sm:px-6">
                <h3 class="text-base font-semibold text-gray-900">{{ __('Lignes à livrer') }}</h3>
            </div>
            <div class="divide-y divide-gray-200">
                @foreach ($order->items as $item)
                    @php($remaining = $item->ordered_quantity - $item->delivered_quantity)
                    @if ((float) $remaining <= 0 || ! isset($lines[$item->id]))
                        @continue
                    @endif
                    <div wire:key="delivery-line-{{ $item->id }}" class="grid gap-4 p-5 sm:p-6 md:grid-cols-[minmax(0,1.4fr)_180px_120px] md:items-end">
                        <div>
                            <p class="font-medium text-gray-900">{{ $item->description }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ $item->reference ?? '—' }} · {{ $item->item_type === 'service' ? __('Service') : __('Produit') }} · {{ __('Reste') }} : {{ $remaining }}</p>
                        </div>
                        <div>
                            <x-input-label :for="'delivery-quantity-'.$item->id" :value="__('Quantité')" />
                            <x-text-input id="delivery-quantity-{{ $item->id }}" type="number" min="0.001" max="{{ $remaining }}" step="0.001" wire:model="lines.{{ $item->id }}.quantity" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('lines.'.$item->id.'.quantity')" class="mt-1" />
                        </div>
                        <div class="text-end text-sm text-gray-600">
                            <p>{{ __('Restant') }}</p>
                            <p class="mt-1 font-semibold text-gray-900">{{ $remaining }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <a href="{{ route('sales.orders.show', $order) }}" wire:navigate class="inline-flex min-h-10 items-center justify-center border border-gray-300 px-4 text-sm font-medium text-gray-700">{{ __('Annuler') }}</a>
            <x-primary-button type="submit" class="justify-center">{{ __('Enregistrer le bon de livraison') }}</x-primary-button>
        </div>
    </form>
</section>
