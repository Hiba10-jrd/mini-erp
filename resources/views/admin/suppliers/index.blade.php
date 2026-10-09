<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Administration') }}</p>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Fournisseurs') }}</h2><p class="mt-2 text-sm text-slate-500">{{ __('Retrouvez vos partenaires et leurs informations.') }}</p>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <livewire:admin.suppliers-manager />
        </div>
    </div>
</x-app-layout>
