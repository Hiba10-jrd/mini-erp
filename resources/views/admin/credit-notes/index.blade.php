<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm text-gray-500">{{ __('Facturation') }}</p>
            <h2 class="text-xl font-semibold text-gray-900">
                {{ __('Avoirs clients') }}
            </h2><p class="mt-2 text-sm text-slate-500">Retrouvez les avoirs et leurs justificatifs.</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <livewire:admin.credit-notes-manager />
        </div>
    </div>
</x-app-layout>