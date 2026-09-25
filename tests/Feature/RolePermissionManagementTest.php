<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RolePermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_only_super_administrator_can_access_role_permissions_page(): void
    {
        $this->get(route('admin.roles.index'))->assertRedirect(route('login'));

        $ordinaryUser = User::factory()->create();
        $this->actingAs($ordinaryUser)
            ->get(route('admin.roles.index'))
            ->assertForbidden();

        $this->actingAs($this->createSuperAdministrator())
            ->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSeeVolt('admin.roles-permissions-manager')
            ->assertSee('Rôles et permissions');
    }

    public function test_livewire_rechecks_authorization_before_saving(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $ordinaryUser = User::factory()->create();
        $role = Role::query()->where('slug', 'commercial')->firstOrFail();

        $this->actingAs($superAdministrator);
        $component = Volt::test('admin.roles-permissions-manager')
            ->set('selectedRoleId', $role->id)
            ->set('selectedPermissionNames', ['customers.view']);

        $this->actingAs($ordinaryUser);

        $component->call('savePermissions')->assertForbidden();
        $this->assertDatabaseCount('permission_role', 0);
    }

    public function test_super_administrator_can_assign_and_remove_a_valid_permission(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $role = Role::query()->where('slug', 'commercial')->firstOrFail();
        $permission = Permission::query()->where('name', 'customers.view')->firstOrFail();

        Volt::test('admin.roles-permissions-manager')
            ->call('selectRole', $role->id)
            ->set('selectedPermissionNames', [$permission->name])
            ->call('savePermissions')
            ->assertHasNoErrors()
            ->assertSet('feedback', 'Les permissions du rôle ont été enregistrées.');

        $this->assertTrue($role->fresh()->permissions()->whereKey($permission)->exists());

        Volt::test('admin.roles-permissions-manager')
            ->call('selectRole', $role->id)
            ->set('selectedPermissionNames', [])
            ->call('savePermissions')
            ->assertHasNoErrors();

        $this->assertFalse($role->fresh()->permissions()->whereKey($permission)->exists());
    }

    public function test_unknown_permission_is_rejected_without_changing_existing_associations(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $role = Role::query()->where('slug', 'commercial')->firstOrFail();
        $permission = Permission::query()->where('name', 'customers.view')->firstOrFail();
        $role->permissions()->attach($permission);

        Volt::test('admin.roles-permissions-manager')
            ->call('selectRole', $role->id)
            ->set('selectedPermissionNames', ['customers.view', 'unknown.permission'])
            ->call('savePermissions')
            ->assertHasErrors(['selectedPermissionNames.1']);

        $this->assertEquals([$permission->id], $role->fresh()->permissions()->pluck('permissions.id')->all());
    }

    public function test_super_administrator_role_cannot_be_modified(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $role = Role::query()->where('slug', 'super-admin')->firstOrFail();

        Volt::test('admin.roles-permissions-manager')
            ->call('selectRole', $role->id)
            ->set('selectedPermissionNames', ['customers.view'])
            ->call('savePermissions')
            ->assertHasErrors(['selectedRoleId']);

        $this->assertDatabaseCount('permission_role', 0);
    }

    public function test_consultation_rejects_write_permissions_but_accepts_read_permissions(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $role = Role::query()->where('slug', 'consultation')->firstOrFail();

        $component = Volt::test('admin.roles-permissions-manager')
            ->call('selectRole', $role->id)
            ->assertSee('lecture seule')
            ->assertSee('Non autorisé — lecture seule')
            ->assertSeeHtml('bg-gray-100')
            ->assertSeeHtml('aria-hidden="true"')
            ->set('selectedPermissionNames', ['customers.manage'])
            ->call('savePermissions')
            ->assertHasErrors(['selectedPermissionNames']);

        $this->assertDatabaseCount('permission_role', 0);

        $component
            ->set('selectedPermissionNames', ['customers.view'])
            ->call('savePermissions')
            ->assertHasNoErrors();

        $this->assertTrue($role->fresh()->permissions()->where('name', 'customers.view')->exists());
    }

    public function test_updated_permissions_are_used_by_has_permission(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $role = Role::query()->where('slug', 'commercial')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->attach($role);

        Volt::test('admin.roles-permissions-manager')
            ->call('selectRole', $role->id)
            ->set('selectedPermissionNames', ['sales.create'])
            ->call('savePermissions');

        $this->assertTrue($user->fresh()->hasPermission('sales.create'));

        $role->permissions()->detach();
        $this->assertFalse($user->fresh()->hasPermission('sales.create'));
    }

    public function test_permissions_from_multiple_roles_are_effective(): void
    {
        $commercial = Role::query()->where('slug', 'commercial')->firstOrFail();
        $consultation = Role::query()->where('slug', 'consultation')->firstOrFail();
        $commercial->permissions()->attach(Permission::query()->where('name', 'sales.create')->firstOrFail());
        $consultation->permissions()->attach(Permission::query()->where('name', 'customers.view')->firstOrFail());

        $user = User::factory()->create();
        $user->roles()->attach([$commercial->id, $consultation->id]);

        $this->assertTrue($user->hasPermission('sales.create'));
        $this->assertTrue($user->hasPermission('customers.view'));
    }

    private function createSuperAdministrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());

        return $user;
    }
}
