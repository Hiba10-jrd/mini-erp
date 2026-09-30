<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm text-gray-500">{{ __('Facturation') }}</p>
            <h2 class="text-xl font-semibold text-gray-900">
                {{ $creditNote ? __('Modifier l’avoir') : __('Créer un avoir') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <livewire:admin.credit-note-form
                :invoice-id="$invoice->id"
                :credit-note-id="$creditNote?->id"
            />
        </div>
    </div>
</x-app-layout>