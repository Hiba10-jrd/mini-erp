<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ __('Documents') }}</h2></x-slot>
    <div class="mx-auto max-w-7xl px-4 py-8"><livewire:attachments-manager :parent-type="$parentType" :parent-id="$parentId" /></div>
</x-app-layout>
