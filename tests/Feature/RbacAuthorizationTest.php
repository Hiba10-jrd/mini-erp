<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_denied_erp_access_and_permissions(): void
    {
        $this->assertFalse(Gate::allows('erp.access'));
        $this->assertFalse(Gate::allows('customers.view'));
    }

    public function test_user_without_role_cannot_access_erp(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(
            Gate::forUser($user)->allows('erp.access')
        );

        $this->assertFalse(
            Gate::forUser($user)->allows('customers.view')
        );
    }

    public function test_user_with_permission_is_authorized(): void
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => 'Commercial',
            'slug' => 'commercial',
        ]);

        $permission = Permission::create([
            'name' => 'customers.view',
        ]);

        $role->permissions()->attach($permission);

        $user->roles()->attach($role);

        $this->assertTrue(
            Gate::forUser($user)->allows('erp.access')
        );

        $this->assertTrue(
            Gate::forUser($user)->allows('customers.view')
        );
    }

    public function test_user_without_required_permission_is_denied(): void
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => 'Commercial',
            'slug' => 'commercial',
        ]);

        $user->roles()->attach($role);

        $this->assertFalse(
            Gate::forUser($user)->allows('invoices.validate')
        );

        $this->assertFalse(
            $user->hasPermission('unknown.permission')
        );

        $this->assertFalse(
            Gate::forUser($user)->allows('unknown.permission')
        );
    }

    public function test_super_administrator_has_all_registered_permissions(): void
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => 'Super Administrateur',
            'slug' => 'super-admin',
        ]);

        $user->roles()->attach($role);

        $this->assertTrue(
            Gate::forUser($user)->allows('erp.access')
        );

        foreach (config('erp.permissions') as $permission) {
            $this->assertTrue(
                Gate::forUser($user)->allows($permission),
                "Super Administrator cannot access: {$permission}"
            );
        }
    }

    public function test_only_super_administrator_can_administer_users_in_lot_03_a(): void
    {
        $superAdministrator = User::factory()->create();
        $superAdministrator->roles()->attach(Role::create([
            'name' => 'Super Administrateur',
            'slug' => 'super-admin',
        ]));

        $administrator = User::factory()->create();
        $administratorRole = Role::create([
            'name' => 'Administrateur',
            'slug' => 'admin',
        ]);
        $administrator->roles()->attach($administratorRole);

        foreach (['users.view', 'users.manage'] as $permissionName) {
            $administratorRole->permissions()->attach(Permission::create([
                'name' => $permissionName,
            ]));
        }

        $this->assertTrue(
            Gate::forUser($superAdministrator)->allows('users.administer')
        );
        $this->assertFalse(
            Gate::forUser($administrator)->allows('users.administer')
        );
    }
}
