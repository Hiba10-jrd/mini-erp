<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Nouveau paiement') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <livewire:admin.payment-form
                :invoice-id="isset($invoice) ? $invoice->id : null"
            />
        </div>
    </div>
</x-app-layout>