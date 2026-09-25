<?php

return [

    'permissions' => [

        // Clients
        'customers.view',
        'customers.manage',

        // Fournisseurs
        'suppliers.view',
        'suppliers.manage',

        // Ventes
        'sales.view',
        'sales.create',
        'sales.update',
        'sales.delete',

        // Achats
        'purchases.view',
        'purchases.create',
        'purchases.update',
        'purchases.delete',

        // Stocks
        'stock.view',
        'stock.manage',

        // Facturation
        'invoices.view',
        'invoices.create',
        'invoices.validate',

        // Paiements
        'payments.view',
        'payments.create',

        // Rapports
        'reports.view',

        // Utilisateurs
        'users.view',
        'users.manage',

        // Paramètres
        'settings.manage',

    ],

];
