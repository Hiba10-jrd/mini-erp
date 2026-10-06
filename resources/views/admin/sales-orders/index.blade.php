<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Ventes') }}</p>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Commandes clients') }}</h2><p class="mt-2 text-sm text-slate-500">Suivez les commandes et leur avancement.</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            <livewire:admin.sales-orders-manager />
        </div>
    </div>
</x-app-layout>