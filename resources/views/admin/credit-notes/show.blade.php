<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm text-gray-500">{{ __('Facturation') }}</p>
            <h2 class="text-xl font-semibold text-gray-900">
                {{ __('Détail de l’avoir') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <livewire:admin.credit-note-details :credit-note-id="$creditNote->id" />
        </div>
    </div>
<div class="mx-auto max-w-7xl px-4 pb-8"><livewire:attachments-manager parent-type="credit-note" :parent-id="$creditNote->id" /></div>
</x-app-layout>