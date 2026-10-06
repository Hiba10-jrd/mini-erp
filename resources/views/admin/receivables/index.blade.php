<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Créances clients') }}</h2><p class="mt-2 text-sm text-slate-500">Suivez les créances et les relances clients.</p>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <livewire:admin.receivables-manager />
        </div>
    </div>
</x-app-layout>
