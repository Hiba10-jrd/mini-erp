<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">{{ __('Rapports') }}</h2><p class="mt-2 text-sm text-slate-500">{{ __('Analysez votre activité sur la période sélectionnée.') }}</p></x-slot>
    <div class="py-8"><div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"><livewire:admin.reports-manager /></div></div>
</x-app-layout>
