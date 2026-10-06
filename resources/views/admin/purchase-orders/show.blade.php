<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Achats') }}</p>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Fiche commande fournisseur') }}</h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            <livewire:admin.purchase-order-details :order-id="$purchaseOrder->id" />
        </div>
    </div>
<div class="mx-auto max-w-7xl px-4 pb-8"><livewire:attachments-manager parent-type="purchase-order" :parent-id="$purchaseOrder->id" /></div>
</x-app-layout>
