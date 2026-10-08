@php
    $groups = [
        'Ventes' => [
            ['Clients', 'admin.customers.index', 'admin.customers.*', 'customers.access'],
            ['Devis', 'sales.quotes.index', 'sales.quotes.*', 'sales.view'],
            ['Commandes clients', 'sales.orders.index', 'sales.orders.*', 'sales.view'],
            ['Bons de livraison', 'sales.delivery-notes.index', 'sales.delivery-notes.*', 'sales.view'],
            ['Factures clients', 'sales.invoices.index', 'sales.invoices.*', 'invoices.view'],
            ['Avoirs', 'sales.credit-notes.index', 'sales.credit-notes.*', 'invoices.view'],
            ['Paiements clients', 'sales.payments.index', 'sales.payments.*', 'payments.view'],
            ['Créances clients', 'finance.receivables.index', 'finance.receivables.*', 'payments.view'],
        ],
        'Achats' => [
            ['Fournisseurs', 'admin.suppliers.index', 'admin.suppliers.*', 'suppliers.access'],
            ['Commandes fournisseurs', 'purchases.orders.index', 'purchases.orders.*', 'purchases.view'],
            ['Réceptions fournisseurs', 'purchases.receipts.index', 'purchases.receipts.*', 'purchases.view'],
            ['Factures fournisseurs', 'purchases.invoices.index', 'purchases.invoices.*', 'purchases.view'],
            ['Paiements fournisseurs', 'purchases.payments.index', 'purchases.payments.*', 'payments.view'],
        ],
        'Finance' => [
            ['Dépenses', 'finance.expenses.index', 'finance.expenses.*', 'payments.view'],
            ['Caisse', 'finance.cash.index', 'finance.cash.*', 'payments.view'],
            ['Rapports', 'reports.index', 'reports.*', 'reports.view'],
        ],
        'Stock' => [
            ['Produits et services', 'admin.products.index', 'admin.products.*', 'products.access'],
            ['Stocks et dépôts', 'admin.stock.index', 'admin.stock.*', 'stock.access'],
            ['Inventaires', 'admin.inventories.index', 'admin.inventories.*', 'stock.access'],
        ],
        'Administration' => [
            ['Utilisateurs', 'admin.users.index', 'admin.users.*', 'users.administer'],
            ['Rôles et permissions', 'admin.roles.index', 'admin.roles.*', 'roles.administer'],
            ['Paramètres de l’entreprise', 'admin.company.index', 'admin.company.*', 'company.administer'],
            ['Paramètres commerciaux', 'admin.commercial.index', 'admin.commercial.*', 'company.administer'],
            ['Audit', 'admin.audit.index', 'admin.audit.*', 'audit.access'],
        ],
    ];
@endphp
<div
    x-cloak
    x-show="sidebarOpen && !desktop"
    :style="{ pointerEvents: sidebarOpen && !desktop ? 'auto' : 'none' }"
    style="display: none; pointer-events: none;"
    class="erp-drawer-backdrop"
    @click="sidebarOpen = false; $refs.navToggle?.focus()"
    aria-hidden="true"
></div>
<aside id="erp-sidebar" class="erp-sidebar" :class="{ 'is-open': sidebarOpen }" :inert="!sidebarOpen && !desktop"
    @keydown.tab="if (!desktop) { const nodes = $el.querySelectorAll('a, button, summary'); const first = nodes[0]; const last = nodes[nodes.length - 1]; if ($event.shiftKey && document.activeElement === first) { $event.preventDefault(); last.focus(); } else if (!$event.shiftKey && document.activeElement === last) { $event.preventDefault(); first.focus(); } }">
    <div class="erp-brand">
        <a href="{{ route('dashboard') }}" aria-label="{{ __('Mini ERP — Dashboard') }}"><x-application-logo class="h-16 w-16 rounded-xl" /></a>
        <div><span class="text-lg font-semibold tracking-tight text-white">Mini ERP</span><p class="text-xs text-[#D7E3F4]">{{ __('Votre espace de gestion') }}</p></div>
        <button x-ref="sidebarClose" type="button" @click="sidebarOpen = false; $refs.navToggle.focus()" class="ms-auto rounded p-2 text-white lg:hidden" aria-label="{{ __('Fermer la navigation') }}">✕</button>
    </div>
    <nav class="erp-sidebar-links" aria-label="{{ __('Navigation principale') }}">
        <a href="{{ route('dashboard') }}" @class(['erp-sidebar-link erp-sidebar-dashboard', 'is-active' => request()->routeIs('dashboard')]) @if(request()->routeIs('dashboard')) aria-current="page" @endif>
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z" /></svg>{{ __('Dashboard') }}
        </a>
        @foreach ($groups as $group => $items)
            @php
                $visible = array_values(array_filter($items, fn ($item) => \Illuminate\Support\Facades\Gate::allows($item[3])));
                $active = collect($visible)->contains(fn ($item) => request()->routeIs($item[2]));
            @endphp
            @if(count($visible))
                <details class="erp-nav-group" @if($active) open @endif>
                    <summary>{{ __($group) }}<span class="erp-group-toggle" aria-hidden="true"><span class="erp-group-plus">+</span><span class="erp-group-minus">−</span></span></summary>
                    <div class="space-y-1 pb-2">
                        @foreach ($visible as [$label, $route, $pattern, $permission])
                            <a href="{{ route($route) }}" @class(['erp-sidebar-link', 'is-active' => request()->routeIs($pattern)]) @if(request()->routeIs($pattern)) aria-current="page" @endif>{{ __($label) }}</a>
                        @endforeach
                    </div>
                </details>
            @endif
        @endforeach
    </nav>
    <p class="erp-sidebar-footer">Mini ERP <span class="opacity-50">/</span> {{ __('Espace professionnel') }}</p>
</aside>
