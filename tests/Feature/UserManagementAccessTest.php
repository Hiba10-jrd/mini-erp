<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_guest_is_redirected_from_user_administration(): void
    {
        $this->get(route('admin.users.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_role_is_forbidden_from_user_administration(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_non_super_administrator_is_forbidden_even_with_user_permissions(): void
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', 'admin')->firstOrFail();
        $permissions = Permission::query()
            ->whereIn('name', ['users.view', 'users.manage'])
            ->get();

        $role->permissions()->sync($permissions->modelKeys());
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.users.index'))
            ->assertDontSee('Administration');
    }

    public function test_super_administrator_can_view_user_administration_and_navigation(): void
    {
        $user = $this->createSuperAdministrator();

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSeeVolt('admin.users-manager')
            ->assertSee('Gestion des utilisateurs');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('admin.users.index'))
            ->assertSee('Administration');
    }

    private function createSuperAdministrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(
            Role::query()->where('slug', 'super-admin')->firstOrFail()
        );

        return $user;
    }
}
