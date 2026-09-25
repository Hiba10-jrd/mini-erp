<?php

namespace Tests\Feature;

use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_rbac_seeder_creates_expected_records_idempotently(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(RbacSeeder::class);

        $this->assertDatabaseCount('roles', 6);
        $this->assertDatabaseCount(
            'permissions',
            count(config('erp.permissions', []))
        );

        foreach (config('erp.permissions', []) as $permission) {
            $this->assertDatabaseHas('permissions', [
                'name' => $permission,
            ]);
        }

        foreach ([
            'super-admin',
            'admin',
            'commercial',
            'magasinier',
            'comptable',
            'consultation',
        ] as $role) {
            $this->assertDatabaseHas('roles', ['slug' => $role]);
        }

        $this->assertDatabaseCount('permission_role', 0);
    }
}
