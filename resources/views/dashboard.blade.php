<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @can('reports.view')
                <livewire:dashboard-summary />
            @else
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="text-lg font-semibold">Bienvenue dans votre ERP</h3>
                    <p class="mt-2 text-gray-600">Accédez à vos modules depuis la navigation.</p>
                    <div class="mt-4 flex flex-wrap gap-4">
                        @can('sales.view')<a href="{{ route('sales.quotes.index') }}" wire:navigate class="text-indigo-700">Devis</a>@endcan
                        @can('purchases.view')<a href="{{ route('purchases.orders.index') }}" wire:navigate class="text-indigo-700">Achats</a>@endcan
                        @can('stock.access')<a href="{{ route('admin.stock.index') }}" wire:navigate class="text-indigo-700">Stock</a>@endcan
                    </div>
                </div>
            @endcan
        </div>
    </div>
</x-app-layout>
