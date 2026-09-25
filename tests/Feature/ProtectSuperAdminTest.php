<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProtectSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_administrator_cannot_delete_their_own_account(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'Super Administrateur',
            'slug' => 'super-admin',
        ]);

        $user->roles()->attach($role);

        $this->actingAs($user);

        Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertForbidden();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
        $this->assertTrue(
            $user->fresh()->roles()->whereKey($role->id)->exists()
        );
    }

    public function test_delete_account_interface_is_hidden_from_super_administrator(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'Super Administrateur',
            'slug' => 'super-admin',
        ]);

        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get(route('profile'))
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertSeeVolt('profile.update-password-form')
            ->assertDontSeeVolt('profile.delete-user-form')
            ->assertDontSee('Delete Account');

        Volt::test('profile.delete-user-form')
            ->assertDontSee('Delete Account');
    }
}
