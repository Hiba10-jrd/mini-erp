<x-app-layout>
    <x-slot name="header"><div><p class="text-sm font-medium text-indigo-600">{{ __('Achats') }}</p><h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Réceptions fournisseurs') }}</h2><p class="mt-2 text-sm text-slate-500">{{ __('Suivez les réceptions et leur validation.') }}</p></div></x-slot>
    <div class="py-8"><div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8"><livewire:admin.goods-receipts-manager /></div></div>
</x-app-layout>
