<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Ventes') }}</p>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ isset($deliveryNote) ? __('Modifier le bon de livraison') : __('Nouveau bon de livraison') }}</h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            <livewire:admin.delivery-note-form :note-id="$deliveryNote->id ?? null" :order-id="$salesOrder->id ?? $deliveryNote->sales_order_id ?? null" />
        </div>
    </div>
</x-app-layout>
