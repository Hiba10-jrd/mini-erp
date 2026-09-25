<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_role_cannot_access_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_super_admin_can_access_dashboard(): void
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => 'Super Administrateur',
            'slug' => 'super-admin',
        ]);

        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_user_without_role_cannot_access_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile'))
            ->assertForbidden();
    }

    public function test_super_admin_can_access_profile(): void
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => 'Super Administrateur',
            'slug' => 'super-admin',
        ]);

        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get(route('profile'))
            ->assertOk();
    }
}
