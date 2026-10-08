<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">
                {{ __('Facturation') }}
            </p>

            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ __('Factures clients') }}
            </h2><p class="mt-2 text-sm text-slate-500">{{ __('Consultez et gérez les factures clients.') }}</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            <livewire:admin.invoices-manager />
        </div>
    </div>
</x-app-layout>