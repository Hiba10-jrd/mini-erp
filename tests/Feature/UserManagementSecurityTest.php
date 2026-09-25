<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UserManagementSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_authorization_is_rechecked_when_sensitive_livewire_action_executes(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $unauthorizedUser = $this->createNonSuperAdministratorWithUserPermissions();
        $target = User::factory()->create();
        $target->roles()->attach(
            Role::query()->where('slug', 'commercial')->firstOrFail()
        );

        $this->actingAs($superAdministrator);

        $component = Volt::test('admin.users-manager')
            ->call('editUser', $target->id)
            ->set('name', 'Forbidden Change');

        $this->actingAs($unauthorizedUser);

        $component
            ->call('saveUser')
            ->assertForbidden();

        $this->assertNotSame('Forbidden Change', $target->fresh()->name);
    }

    public function test_forged_role_identifier_is_rejected_and_existing_data_is_preserved(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $commercial = Role::query()->where('slug', 'commercial')->firstOrFail();
        $target = User::factory()->create(['name' => 'Original Name']);
        $target->roles()->attach($commercial);

        $this->actingAs($superAdministrator);

        Volt::test('admin.users-manager')
            ->call('editUser', $target->id)
            ->set('name', 'Tampered Name')
            ->set('selectedRoleIds', [999999])
            ->call('saveUser')
            ->assertHasErrors(['selectedRoleIds.0']);

        $target->refresh();

        $this->assertSame('Original Name', $target->name);
        $this->assertTrue($target->roles()->whereKey($commercial->id)->exists());
    }

    public function test_locked_user_identifier_cannot_be_modified_by_client(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $firstTarget = User::factory()->create();
        $secondTarget = User::factory()->create();

        $this->actingAs($superAdministrator);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Volt::test('admin.users-manager')
            ->call('editUser', $firstTarget->id)
            ->set('editingUserId', $secondTarget->id);
    }

    public function test_last_super_administrator_role_cannot_be_removed(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $commercial = Role::query()->where('slug', 'commercial')->firstOrFail();

        $this->actingAs($superAdministrator);

        Volt::test('admin.users-manager')
            ->call('editUser', $superAdministrator->id)
            ->set('selectedRoleIds', [$commercial->id])
            ->call('saveUser')
            ->assertHasErrors(['selectedRoleIds']);

        $this->assertTrue($superAdministrator->fresh()->isSuperAdministrator());
    }

    public function test_super_administrator_role_can_be_removed_when_another_one_exists(): void
    {
        $firstSuperAdministrator = $this->createSuperAdministrator();
        $secondSuperAdministrator = $this->createSuperAdministrator();
        $commercial = Role::query()->where('slug', 'commercial')->firstOrFail();

        $this->actingAs($firstSuperAdministrator);

        Volt::test('admin.users-manager')
            ->call('editUser', $secondSuperAdministrator->id)
            ->set('selectedRoleIds', [$commercial->id])
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertFalse($secondSuperAdministrator->fresh()->isSuperAdministrator());
        $this->assertTrue($firstSuperAdministrator->fresh()->isSuperAdministrator());
    }

    public function test_super_administrator_is_redirected_after_authorized_self_demotion(): void
    {
        $actingSuperAdministrator = $this->createSuperAdministrator();
        $remainingSuperAdministrator = $this->createSuperAdministrator();
        $commercial = Role::query()->where('slug', 'commercial')->firstOrFail();

        $this->actingAs($actingSuperAdministrator);

        Volt::test('admin.users-manager')
            ->call('editUser', $actingSuperAdministrator->id)
            ->set('selectedRoleIds', [$commercial->id])
            ->call('saveUser')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertFalse($actingSuperAdministrator->fresh()->isSuperAdministrator());
        $this->assertTrue($remainingSuperAdministrator->fresh()->isSuperAdministrator());
    }

    public function test_non_super_administrator_cannot_assign_super_administrator_role(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $unauthorizedUser = $this->createNonSuperAdministratorWithUserPermissions();
        $target = User::factory()->create();
        $commercial = Role::query()->where('slug', 'commercial')->firstOrFail();
        $superAdministratorRole = Role::query()->where('slug', 'super-admin')->firstOrFail();
        $target->roles()->attach($commercial);

        $this->actingAs($superAdministrator);

        $component = Volt::test('admin.users-manager')
            ->call('editUser', $target->id)
            ->set('selectedRoleIds', [$superAdministratorRole->id]);

        $this->actingAs($unauthorizedUser);

        $component
            ->call('saveUser')
            ->assertForbidden();

        $this->assertFalse($target->fresh()->isSuperAdministrator());
        $this->assertTrue($target->roles()->whereKey($commercial->id)->exists());
    }

    private function createSuperAdministrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(
            Role::query()->where('slug', 'super-admin')->firstOrFail()
        );

        return $user;
    }

    private function createNonSuperAdministratorWithUserPermissions(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', 'admin')->firstOrFail();
        $permissions = Permission::query()
            ->whereIn('name', ['users.view', 'users.manage'])
            ->get();

        $role->permissions()->sync($permissions->modelKeys());
        $user->roles()->attach($role);

        return $user;
    }
}
