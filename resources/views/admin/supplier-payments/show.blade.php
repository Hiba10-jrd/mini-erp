<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Détail du paiement fournisseur') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <livewire:admin.supplier-payment-details
                :supplier-payment-id="$supplierPayment->id"
            />
        </div>
    </div>
<div class="mx-auto max-w-7xl px-4 pb-8"><livewire:attachments-manager parent-type="supplier-payment" :parent-id="$supplierPayment->id" /></div>
</x-app-layout>