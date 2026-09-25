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

            $permissions = config('erp.permissions', []);

            foreach ($permissions as $permission) {
                Permission::query()->firstOrCreate([
                    'name' => $permission,
                ]);
            }
        });
    }
}
