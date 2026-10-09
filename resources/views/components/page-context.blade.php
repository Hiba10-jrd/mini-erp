@php
    $catalog = [
        'dashboard' => ['Vue d’ensemble', '', 'dashboard', 'erp.access'],
        'sales.quotes' => ['Devis', 'Ventes', 'sales.quotes.index', 'sales.view'],
        'sales.orders' => ['Commandes clients', 'Ventes', 'sales.orders.index', 'sales.view'],
        'sales.delivery-notes' => ['Bons de livraison', 'Ventes', 'sales.delivery-notes.index', 'sales.view'],
        'sales.invoices' => ['Factures clients', 'Ventes', 'sales.invoices.index', 'invoices.view'],
        'sales.credit-notes' => ['Avoirs', 'Ventes', 'sales.credit-notes.index', 'invoices.view'],
        'sales.payments' => ['Paiements clients', 'Ventes', 'sales.payments.index', 'payments.view'],
        'purchases.orders' => ['Commandes fournisseurs', 'Achats', 'purchases.orders.index', 'purchases.view'],
        'purchases.receipts' => ['Réceptions fournisseurs', 'Achats', 'purchases.receipts.index', 'purchases.view'],
        'purchases.invoices' => ['Factures fournisseurs', 'Achats', 'purchases.invoices.index', 'purchases.view'],
        'purchases.payments' => ['Paiements fournisseurs', 'Achats', 'purchases.payments.index', 'payments.view'],
        'finance.receivables' => ['Créances clients', 'Ventes', 'finance.receivables.index', 'payments.view'],
        'finance.expenses' => ['Dépenses', 'Finance', 'finance.expenses.index', 'payments.view'],
        'finance.cash' => ['Caisse', 'Finance', 'finance.cash.index', 'payments.view'],
        'reports' => ['Rapports', 'Finance', 'reports.index', 'reports.view'],
        'admin.customers' => ['Clients', 'Ventes', 'admin.customers.index', 'customers.access'],
        'admin.suppliers' => ['Fournisseurs', 'Achats', 'admin.suppliers.index', 'suppliers.access'],
        'admin.products' => ['Produits et services', 'Stock', 'admin.products.index', 'products.access'],
        'admin.stock' => ['Stocks et dépôts', 'Stock', 'admin.stock.index', 'stock.access'],
        'admin.inventories' => ['Inventaires', 'Stock', 'admin.inventories.index', 'stock.access'],
        'admin.users' => ['Utilisateurs', 'Administration', 'admin.users.index', 'users.administer'],
        'admin.roles' => ['Rôles et permissions', 'Administration', 'admin.roles.index', 'roles.administer'],
        'admin.company' => ['Paramètres entreprise', 'Administration', 'admin.company.index', 'company.administer'],
        'admin.commercial' => ['Paramètres commerciaux', 'Administration', 'admin.commercial.index', 'company.administer'],
        'admin.audit' => ['Audit des opérations', 'Administration', 'admin.audit.index', 'audit.access'],
        'notifications' => ['Notifications', '', 'notifications.index', 'erp.access'],
        'profile' => ['Mon profil', '', 'profile', 'erp.access'],
        'attachments' => ['Documents', '', null, 'erp.access'],
    ];
    $context = ['Mini ERP', '', null, 'erp.access'];
    foreach ($catalog as $pattern => $item) { if (request()->routeIs($pattern, $pattern.'.*')) { $context = $item; break; } }
    $subject = collect(request()->route()?->parameters() ?? [])->first(fn ($value) => $value instanceof \Illuminate\Database\Eloquent\Model);
    $identifier = $subject ? ($subject->number ?? $subject->name ?? $subject->code ?? '#'.$subject->getKey()) : null;
@endphp
<nav aria-label="{{ __('Fil d’Ariane') }}" class="erp-breadcrumb">
    @if($context[1])<span>{{ __($context[1]) }}</span><span aria-hidden="true">/</span>@endif
    @if($identifier && $context[2] && \Illuminate\Support\Facades\Gate::allows($context[3]))<a href="{{ route($context[2]) }}">{{ __($context[0]) }}</a><span aria-hidden="true">/</span><span aria-current="page"><bdi class="erp-ltr">{{ $identifier }}</bdi></span>
    @elseif($context[2] && \Illuminate\Support\Facades\Gate::allows($context[3]))<a href="{{ route($context[2]) }}" aria-current="page">{{ __($context[0]) }}</a>
    @else<span aria-current="page">{{ __($context[0]) }}</span>@endif
</nav>
