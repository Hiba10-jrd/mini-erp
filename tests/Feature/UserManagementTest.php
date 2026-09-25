<?php

namespace Tests\Feature;

use App\Enums\UserAccountStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_administrator_can_list_search_and_filter_users(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $commercial = Role::query()->where('slug', 'commercial')->firstOrFail();
        $active = User::factory()->create(['name' => 'Alice Active', 'email' => 'alice@example.com']);
        $disabled = User::factory()->create(['name' => 'Bruno Disabled', 'account_status' => UserAccountStatus::Disabled]);
        $archived = User::factory()->create(['name' => 'Chloé Archived', 'account_status' => UserAccountStatus::Archived]);
        $active->roles()->attach($commercial);
        $disabled->roles()->attach($commercial);
        $archived->roles()->attach($commercial);

        $this->actingAs($superAdministrator);

        Volt::test('admin.users-manager')
            ->assertSee('Alice Active')
            ->assertSee('Bruno Disabled')
            ->assertDontSee('Chloé Archived')
            ->set('search', 'alice@example.com')
            ->assertSee('Alice Active')
            ->set('search', '')
            ->set('statusFilter', 'archived')
            ->assertSee('Chloé Archived')
            ->assertDontSee('Alice Active');
    }

    public function test_user_list_is_paginated(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $commercial = Role::query()->where('slug', 'commercial')->firstOrFail();

        User::factory(11)->create()->each(
            fn (User $user) => $user->roles()->attach($commercial)
        );

        $this->actingAs($superAdministrator);

        Volt::test('admin.users-manager')
            ->assertViewHas('users', function ($users): bool {
                return $users->perPage() === 10
                    && $users->total() === 12
                    && $users->hasPages();
            });
    }

    public function test_super_administrator_can_create_user_with_hashed_initial_password_and_multiple_roles(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $commercial = Role::query()->where('slug', 'commercial')->firstOrFail();
        $consultation = Role::query()->where('slug', 'consultation')->firstOrFail();
        $initialPassword = 'Initial-Password-2026!';

        $this->actingAs($superAdministrator);

        Volt::test('admin.users-manager')
            ->call('prepareCreate')
            ->set('name', 'New Internal User')
            ->set('email', 'NEW.USER@example.com')
            ->set('password', $initialPassword)
            ->set('password_confirmation', $initialPassword)
            ->set('selectedRoleIds', [$commercial->id, $consultation->id])
            ->call('saveUser')
            ->assertHasNoErrors()
            ->assertSet('password', '')
            ->assertSet('password_confirmation', '');

        $createdUser = User::query()->where('email', 'new.user@example.com')->firstOrFail();

        $this->assertSame('New Internal User', $createdUser->name);
        $this->assertTrue(Hash::check($initialPassword, $createdUser->password));
        $this->assertNotSame($initialPassword, $createdUser->password);
        $this->assertTrue($createdUser->must_change_password);
        $this->assertSame(UserAccountStatus::Active, $createdUser->account_status);
        $this->assertArrayNotHasKey('password', $createdUser->toArray());
        $this->assertEqualsCanonicalizing(
            [$commercial->id, $consultation->id],
            $createdUser->roles()->pluck('roles.id')->all()
        );
    }

    public function test_creation_validates_password_confirmation_and_unique_email(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $existingUser = User::factory()->create(['email' => 'existing@example.com']);
        $commercial = Role::query()->where('slug', 'commercial')->firstOrFail();

        $this->actingAs($superAdministrator);

        Volt::test('admin.users-manager')
            ->call('prepareCreate')
            ->set('name', 'Invalid Password')
            ->set('email', 'invalid@example.com')
            ->set('password', 'short')
            ->set('password_confirmation', 'different')
            ->set('selectedRoleIds', [$commercial->id])
            ->call('saveUser')
            ->assertHasErrors(['password']);

        Volt::test('admin.users-manager')
            ->call('prepareCreate')
            ->set('name', 'Duplicate Email')
            ->set('email', $existingUser->email)
            ->set('password', 'Valid-Password-2026!')
            ->set('password_confirmation', 'Valid-Password-2026!')
            ->set('selectedRoleIds', [$commercial->id])
            ->call('saveUser')
            ->assertHasErrors(['email']);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_super_administrator_can_update_user_information_and_roles_without_changing_password(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $commercial = Role::query()->where('slug', 'commercial')->firstOrFail();
        $comptable = Role::query()->where('slug', 'comptable')->firstOrFail();
        $target = User::factory()->create();
        $target->roles()->attach($commercial);
        $passwordHash = $target->password;

        $this->actingAs($superAdministrator);

        Volt::test('admin.users-manager')
            ->call('editUser', $target->id)
            ->set('name', 'Updated User')
            ->set('email', 'UPDATED@example.com')
            ->set('selectedRoleIds', [$comptable->id])
            ->call('saveUser')
            ->assertHasNoErrors()
            ->assertSet('feedback', 'Utilisateur mis à jour.');

        $target->refresh();

        $this->assertSame('Updated User', $target->name);
        $this->assertSame('updated@example.com', $target->email);
        $this->assertNull($target->email_verified_at);
        $this->assertSame($passwordHash, $target->password);
        $this->assertEquals([$comptable->id], $target->roles()->pluck('roles.id')->all());
    }

    private function createSuperAdministrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());

        return $user;
    }
}
