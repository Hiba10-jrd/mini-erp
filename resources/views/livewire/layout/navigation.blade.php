<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav x-data="{ open: false }" class="border-b border-gray-100 bg-white">
    <!-- Primary Navigation Menu -->
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 justify-between">
            <div class="flex">
                <!-- Logo -->
                <div class="flex shrink-0 items-center">
                    <a href="{{ route('dashboard') }}" wire:navigate>
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link
                        :href="route('dashboard')"
                        :active="request()->routeIs('dashboard')"
                        wire:navigate
                    >
                        {{ __('Dashboard') }}
                    </x-nav-link>

                    @can('audit.access')
                        <x-nav-link :href="route('admin.audit.index')" :active="request()->routeIs('admin.audit.*')" wire:navigate>Audit</x-nav-link>
                    @endcan
                    @can('reports.view')
                        <x-nav-link :href="route('reports.index')" :active="request()->routeIs('reports.*')" wire:navigate>Rapports</x-nav-link>
                    @endcan
                    @can('users.administer')
                        <x-nav-link
                            :href="route('admin.users.index')"
                            :active="request()->routeIs('admin.users.*')"
                            wire:navigate
                        >
                            {{ __('Administration') }}
                        </x-nav-link>
                    @endcan

                    @can('roles.administer')
                        <x-nav-link
                            :href="route('admin.roles.index')"
                            :active="request()->routeIs('admin.roles.*')"
                            wire:navigate
                        >
                            {{ __('Rôles et permissions') }}
                        </x-nav-link>
                    @endcan

                    @can('company.administer')
                        <x-nav-link
                            :href="route('admin.company.index')"
                            :active="request()->routeIs('admin.company.*')"
                            wire:navigate
                        >
                            {{ __('Paramètres de l’entreprise') }}
                        </x-nav-link>

                        <x-nav-link
                            :href="route('admin.commercial.index')"
                            :active="request()->routeIs('admin.commercial.*')"
                            wire:navigate
                        >
                            {{ __('Paramètres commerciaux') }}
                        </x-nav-link>
                    @endcan

                    @can('customers.access')
                        <x-nav-link
                            :href="route('admin.customers.index')"
                            :active="request()->routeIs('admin.customers.*')"
                            wire:navigate
                        >
                            {{ __('Clients') }}
                        </x-nav-link>
                    @endcan

                    @can('suppliers.access')
                        <x-nav-link
                            :href="route('admin.suppliers.index')"
                            :active="request()->routeIs('admin.suppliers.*')"
                            wire:navigate
                        >
                            {{ __('Fournisseurs') }}
                        </x-nav-link>
                    @endcan

                    @can('purchases.view')
                        <x-nav-link
                            :href="route('purchases.orders.index')"
                            :active="request()->routeIs('purchases.orders.*')"
                            wire:navigate
                        >
                            {{ __('Commandes fournisseurs') }}
                        </x-nav-link>

                        <x-nav-link
                            :href="route('purchases.receipts.index')"
                            :active="request()->routeIs('purchases.receipts.*')"
                            wire:navigate
                        >
                            {{ __('Réceptions fournisseurs') }}
                        </x-nav-link>

                        <x-nav-link
                            :href="route('purchases.invoices.index')"
                            :active="request()->routeIs('purchases.invoices.*')"
                            wire:navigate
                        >
                            {{ __('Factures fournisseurs') }}
                        </x-nav-link>
                    @endcan
@can('payments.view')
    <x-nav-link
        :href="route('purchases.payments.index')"
        :active="request()->routeIs('purchases.payments.*')"
        wire:navigate
    >
        {{ __('Paiements fournisseurs') }}
    </x-nav-link>
@endcan
                    @can('products.access')
                        <x-nav-link
                            :href="route('admin.products.index')"
                            :active="request()->routeIs('admin.products.*')"
                            wire:navigate
                        >
                            {{ __('Produits et services') }}
                        </x-nav-link>
                    @endcan

                    @can('sales.view')
                        <x-nav-link
                            :href="route('sales.quotes.index')"
                            :active="request()->routeIs('sales.quotes.*')"
                            wire:navigate
                        >
                            {{ __('Devis') }}
                        </x-nav-link>

                        <x-nav-link
                            :href="route('sales.orders.index')"
                            :active="request()->routeIs('sales.orders.*')"
                            wire:navigate
                        >
                            {{ __('Commandes clients') }}
                        </x-nav-link>

                        <x-nav-link
                            :href="route('sales.delivery-notes.index')"
                            :active="request()->routeIs('sales.delivery-notes.*')"
                            wire:navigate
                        >
                            {{ __('Bons de livraison') }}
                        </x-nav-link>
                    @endcan

                    @can('invoices.view')
                        <x-nav-link
                            :href="route('sales.invoices.index')"
                            :active="request()->routeIs('sales.invoices.*')"
                            wire:navigate
                        >
                            {{ __('Factures') }}
                        </x-nav-link>

                        <x-nav-link
                            :href="route('sales.credit-notes.index')"
                            :active="request()->routeIs('sales.credit-notes.*')"
                            wire:navigate
                        >
                            {{ __('Avoirs') }}
                        </x-nav-link>
                    @endcan

                    @can('payments.view')
                        <x-nav-link
                            :href="route('sales.payments.index')"
                            :active="request()->routeIs('sales.payments.*')"
                            wire:navigate
                        >
                            {{ __('Paiements clients') }}
                        </x-nav-link>
                    @endcan
  
                    @can('payments.view')
    <x-nav-link
        :href="route('finance.receivables.index')"
        :active="request()->routeIs('finance.receivables.*')"
        wire:navigate
    >
        {{ __('Créances clients') }}
    </x-nav-link>

    <x-nav-link
        :href="route('finance.expenses.index')"
        :active="request()->routeIs('finance.expenses.*')"
        wire:navigate
    >
        {{ __('Dépenses') }}
    </x-nav-link>

    <x-nav-link
        :href="route('finance.cash.index')"
        :active="request()->routeIs('finance.cash.*')"
        wire:navigate
    >
        {{ __('Caisse') }}
    </x-nav-link>
@endcan
                    @can('stock.access')
                        <x-nav-link
                            :href="route('admin.stock.index')"
                            :active="request()->routeIs('admin.stock.*')"
                            wire:navigate
                        >
                            {{ __('Stocks et dépôts') }}
                        </x-nav-link>

                        <x-nav-link
                            :href="route('admin.inventories.index')"
                            :active="request()->routeIs('admin.inventories.*')"
                            wire:navigate
                        >
                            {{ __('Inventaires') }}
                        </x-nav-link>
                    @endcan
                </div>
            </div>

            @can('erp.access')
                <div class="flex items-center"><livewire:notification-bell /></div>
            @endcan

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button
                            class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                        >
                            <div
                                x-data="{{ json_encode(['name' => auth()->user()->name]) }}"
                                x-text="name"
                                x-on:profile-updated.window="name = $event.detail.name"
                            ></div>

                            <div class="ms-1">
                                <svg
                                    class="h-4 w-4 fill-current"
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 20 20"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link
                            :href="route('profile')"
                            wire:navigate
                        >
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button
                    @click="open = ! open"
                    class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                >
                    <svg
                        class="h-6 w-6"
                        stroke="currentColor"
                        fill="none"
                        viewBox="0 0 24 24"
                    >
                        <path
                            :class="{ 'hidden': open, 'inline-flex': ! open }"
                            class="inline-flex"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16"
                        />

                        <path
                            :class="{ 'hidden': ! open, 'inline-flex': open }"
                            class="hidden"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div
        :class="{ 'block': open, 'hidden': ! open }"
        class="hidden sm:hidden"
    >
        <div class="space-y-1 pb-3 pt-2">
            <x-responsive-nav-link
                :href="route('dashboard')"
                :active="request()->routeIs('dashboard')"
                wire:navigate
            >
                {{ __('Dashboard') }}
            </x-responsive-nav-link>

            @can('erp.access')
                <x-responsive-nav-link :href="route('notifications.index')" :active="request()->routeIs('notifications.*')" wire:navigate>Notifications</x-responsive-nav-link>
            @endcan
            @can('audit.access')
                <x-responsive-nav-link :href="route('admin.audit.index')" :active="request()->routeIs('admin.audit.*')" wire:navigate>Audit</x-responsive-nav-link>
            @endcan
            @can('reports.view')
                <x-responsive-nav-link :href="route('reports.index')" :active="request()->routeIs('reports.*')" wire:navigate>Rapports</x-responsive-nav-link>
            @endcan
            @can('users.administer')
                <x-responsive-nav-link
                    :href="route('admin.users.index')"
                    :active="request()->routeIs('admin.users.*')"
                    wire:navigate
                >
                    {{ __('Administration') }}
                </x-responsive-nav-link>
            @endcan

            @can('roles.administer')
                <x-responsive-nav-link
                    :href="route('admin.roles.index')"
                    :active="request()->routeIs('admin.roles.*')"
                    wire:navigate
                >
                    {{ __('Rôles et permissions') }}
                </x-responsive-nav-link>
            @endcan

            @can('company.administer')
                <x-responsive-nav-link
                    :href="route('admin.company.index')"
                    :active="request()->routeIs('admin.company.*')"
                    wire:navigate
                >
                    {{ __('Paramètres de l’entreprise') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link
                    :href="route('admin.commercial.index')"
                    :active="request()->routeIs('admin.commercial.*')"
                    wire:navigate
                >
                    {{ __('Paramètres commerciaux') }}
                </x-responsive-nav-link>
            @endcan

            @can('customers.access')
                <x-responsive-nav-link
                    :href="route('admin.customers.index')"
                    :active="request()->routeIs('admin.customers.*')"
                    wire:navigate
                >
                    {{ __('Clients') }}
                </x-responsive-nav-link>
            @endcan

            @can('suppliers.access')
                <x-responsive-nav-link
                    :href="route('admin.suppliers.index')"
                    :active="request()->routeIs('admin.suppliers.*')"
                    wire:navigate
                >
                    {{ __('Fournisseurs') }}
                </x-responsive-nav-link>
            @endcan

            @can('purchases.view')
                <x-responsive-nav-link
                    :href="route('purchases.orders.index')"
                    :active="request()->routeIs('purchases.orders.*')"
                    wire:navigate
                >
                    {{ __('Commandes fournisseurs') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link
                    :href="route('purchases.receipts.index')"
                    :active="request()->routeIs('purchases.receipts.*')"
                    wire:navigate
                >
                    {{ __('Réceptions fournisseurs') }}
                </x-responsive-nav-link>
                @can('payments.view')
                    <x-responsive-nav-link
                        :href="route('purchases.payments.index')"
                        :active="request()->routeIs('purchases.payments.*')"
                        wire:navigate
                    >
                        {{ __('Paiements fournisseurs') }}
                    </x-responsive-nav-link>
                @endcan
                <x-responsive-nav-link
                    :href="route('purchases.invoices.index')"
                    :active="request()->routeIs('purchases.invoices.*')"
                    wire:navigate
                >
                    {{ __('Factures fournisseurs') }}
                </x-responsive-nav-link>
            @endcan

            @can('products.access')
                <x-responsive-nav-link
                    :href="route('admin.products.index')"
                    :active="request()->routeIs('admin.products.*')"
                    wire:navigate
                >
                    {{ __('Produits et services') }}
                </x-responsive-nav-link>
            @endcan

            @can('sales.view')
                <x-responsive-nav-link
                    :href="route('sales.quotes.index')"
                    :active="request()->routeIs('sales.quotes.*')"
                    wire:navigate
                >
                    {{ __('Devis') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link
                    :href="route('sales.orders.index')"
                    :active="request()->routeIs('sales.orders.*')"
                    wire:navigate
                >
                    {{ __('Commandes clients') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link
                    :href="route('sales.delivery-notes.index')"
                    :active="request()->routeIs('sales.delivery-notes.*')"
                    wire:navigate
                >
                    {{ __('Bons de livraison') }}
                </x-responsive-nav-link>
            @endcan

            @can('payments.view')
    <div class="mt-6">
        <p class="px-3 text-xs font-semibold uppercase tracking-wider text-gray-400">
            {{ __('Finance') }}
        </p>

        <div class="mt-2 space-y-1">
            <a
                href="{{ route('finance.receivables.index') }}"
                wire:navigate
                @class([
                    'block rounded-md px-3 py-2 text-sm font-medium transition',
                    'bg-gray-900 text-white' => request()->routeIs('finance.receivables.*'),
                    'text-gray-600 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('finance.receivables.*'),
                ])
            >
                {{ __('Créances clients') }}
            </a>

            <a
                href="{{ route('finance.expenses.index') }}"
                wire:navigate
                @class([
                    'block rounded-md px-3 py-2 text-sm font-medium transition',
                    'bg-gray-900 text-white' => request()->routeIs('finance.expenses.*'),
                    'text-gray-600 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('finance.expenses.*'),
                ])
            >
                {{ __('Dépenses') }}
            </a>

            <a
                href="{{ route('finance.cash.index') }}"
                wire:navigate
                @class([
                    'block rounded-md px-3 py-2 text-sm font-medium transition',
                    'bg-gray-900 text-white' => request()->routeIs('finance.cash.*'),
                    'text-gray-600 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('finance.cash.*'),
                ])
            >
                {{ __('Caisse') }}
            </a>
        </div>
    </div>
@endcan

            @can('invoices.view')
                <x-responsive-nav-link
                    :href="route('sales.invoices.index')"
                    :active="request()->routeIs('sales.invoices.*')"
                    wire:navigate
                >
                    {{ __('Factures') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link
                    :href="route('sales.credit-notes.index')"
                    :active="request()->routeIs('sales.credit-notes.*')"
                    wire:navigate
                >
                    {{ __('Avoirs') }}
                </x-responsive-nav-link>
            @endcan

            @can('payments.view')
                <x-responsive-nav-link
                    :href="route('sales.payments.index')"
                    :active="request()->routeIs('sales.payments.*')"
                    wire:navigate
                >
                    {{ __('Paiements clients') }}
                </x-responsive-nav-link>
            @endcan

            @can('stock.access')
                <x-responsive-nav-link
                    :href="route('admin.stock.index')"
                    :active="request()->routeIs('admin.stock.*')"
                    wire:navigate
                >
                    {{ __('Stocks et dépôts') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link
                    :href="route('admin.inventories.index')"
                    :active="request()->routeIs('admin.inventories.*')"
                    wire:navigate
                >
                    {{ __('Inventaires') }}
                </x-responsive-nav-link>
            @endcan
        </div>

        <!-- Responsive Settings Options -->
        <div class="border-t border-gray-200 pb-1 pt-4">
            <div class="px-4">
                <div
                    class="text-base font-medium text-gray-800"
                    x-data="{{ json_encode(['name' => auth()->user()->name]) }}"
                    x-text="name"
                    x-on:profile-updated.window="name = $event.detail.name"
                ></div>

                <div class="text-sm font-medium text-gray-500">
                    {{ auth()->user()->email }}
                </div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link
                    :href="route('profile')"
                    wire:navigate
                >
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <button
                    wire:click="logout"
                    class="w-full text-start"
                >
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
