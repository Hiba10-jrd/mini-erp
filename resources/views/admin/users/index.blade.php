<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Administration') }}</p>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Gestion des utilisateurs') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:admin.users-manager />
        </div>
    </div>
</x-app-layout>
