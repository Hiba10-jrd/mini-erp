<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Dépenses') }}
        </h2><p class="mt-2 text-sm text-slate-500">Consultez les dépenses de votre entreprise.</p>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <livewire:admin.expenses-manager />
        </div>
    </div>
</x-app-layout>