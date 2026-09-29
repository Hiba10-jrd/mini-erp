<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Ventes') }}</p>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ isset($salesOrder) ? __('Modifier le brouillon de commande') : __('Nouvelle commande client') }}</h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            <livewire:admin.sales-order-form :order-id="$salesOrder->id ?? null" />
        </div>
    </div>
</x-app-layout>