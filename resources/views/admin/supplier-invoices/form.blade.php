<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Achats') }}</p>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ isset($supplierInvoice) ? __('Modifier la facture fournisseur') : __('Nouvelle facture fournisseur') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            <livewire:admin.supplier-invoice-form
                :invoice-id="$supplierInvoice->id ?? null"
                :order-id="$purchaseOrder->id ?? null"
            />
        </div>
    </div>
</x-app-layout>
