<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Nouveau paiement fournisseur') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <livewire:admin.supplier-payment-form
                :supplier-invoice-id="isset($supplierInvoice) ? $supplierInvoice->id : null"
            />
        </div>
    </div>
</x-app-layout>