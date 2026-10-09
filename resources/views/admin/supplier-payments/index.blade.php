<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Paiements fournisseurs') }}
        </h2><p class="mt-2 text-sm text-slate-500">{{ __('Suivez les règlements fournisseurs.') }}</p>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <livewire:admin.supplier-payments-manager />
        </div>
    </div>
</x-app-layout>