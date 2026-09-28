<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Stocks') }}</p>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Fiche d’inventaire') }}</h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <livewire:admin.inventory-details :inventory-id="$stockInventory->id" />
        </div>
    </div>
</x-app-layout>
