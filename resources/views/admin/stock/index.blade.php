<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Inventaire') }}</p>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Stocks et dépôts') }}</h2><p class="mt-2 text-sm text-slate-500">{{ __('Consultez les quantités et les alertes par dépôt.') }}</p>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            <livewire:admin.stock-operations-manager />
            <livewire:admin.stock-balances />
            <livewire:admin.stock-movements-history />
            <livewire:admin.warehouses-manager />
        </div>
    </div>
</x-app-layout>
