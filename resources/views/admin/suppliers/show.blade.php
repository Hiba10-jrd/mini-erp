<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Administration') }}</p>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Fiche fournisseur') }}</h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
            <livewire:admin.supplier-details :supplier-id="$supplier->id" />
        </div>
    </div>
</x-app-layout>
