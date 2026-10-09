<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Catalogue') }}</p>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Produits et services') }}</h2><p class="mt-2 text-sm text-slate-500">{{ __('Gérez votre catalogue de produits et services.') }}</p>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            <livewire:admin.products-manager />
            <livewire:admin.catalog-settings-manager />
        </div>
    </div>
</x-app-layout>
