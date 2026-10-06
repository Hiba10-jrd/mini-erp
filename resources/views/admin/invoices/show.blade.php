<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">
                {{ __('Facturation') }}
            </p>

            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ __('Fiche facture') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            <livewire:admin.invoice-details :invoice-id="$invoice->id" />
        </div>
    </div>
<div class="mx-auto max-w-7xl px-4 pb-8"><livewire:attachments-manager parent-type="invoice" :parent-id="$invoice->id" /></div>
</x-app-layout>