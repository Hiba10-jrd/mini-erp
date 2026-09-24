<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {

            // 1. Rôles définis dans le CDC

            $roles = [
                'super-admin' => 'Super Administrateur',
                'admin' => 'Administrateur',
                'commercial' => 'Commercial',
                'magasinier' => 'Magasinier',
                'comptable' => 'Comptable',
                'consultation' => 'Consultation',
            ];

            foreach ($roles as $slug => $name) {
                Role::query()->firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $name]
                );
            }

            // 2. Permissions explicitement définies
            // dans la section 27 du CDC

            $permissions = [
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

                // Stock
                'stock.view',
                'stock.manage',

                // Factures
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
            ];

            foreach ($permissions as $permission) {
                Permission::query()->firstOrCreate([
                    'name' => $permission,
                ]);
            }
        });
    }
}