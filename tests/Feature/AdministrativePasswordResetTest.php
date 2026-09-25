<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PasswordManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdministrativePasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_administrator_can_set_temporary_password_for_ordinary_user(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $target = $this->createOrdinaryUser();
        $otherUser = $this->createOrdinaryUser();
        $otherHash = $otherUser->password;
        $oldRememberToken = $target->remember_token;

        $this->actingAs($superAdministrator);

        Volt::test('admin.users-manager')
            ->call('preparePasswordReset', $target->id)
            ->set('temporaryPassword', 'Temporary-Password-2026!')
            ->set('temporaryPassword_confirmation', 'Temporary-Password-2026!')
            ->call('resetUserPassword')
            ->assertHasNoErrors();

        $target->refresh();

        $this->assertTrue(Hash::check('Temporary-Password-2026!', $target->password));
        $this->assertTrue($target->must_change_password);
        $this->assertNotSame($oldRememberToken, $target->remember_token);
        $this->assertSame($otherHash, $otherUser->fresh()->password);
    }

    public function test_administrative_reset_validates_password_confirmation(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $target = $this->createOrdinaryUser();
        $oldHash = $target->password;

        $this->actingAs($superAdministrator);

        Volt::test('admin.users-manager')
            ->call('preparePasswordReset', $target->id)
            ->set('temporaryPassword', 'Temporary-Password-2026!')
            ->set('temporaryPassword_confirmation', 'different')
            ->call('resetUserPassword')
            ->assertHasErrors(['temporaryPassword']);

        $this->assertSame($oldHash, $target->fresh()->password);
    }

    public function test_non_super_administrator_cannot_reset_another_users_password(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $unauthorizedUser = $this->createNonSuperAdministratorWithUserPermissions();
        $target = $this->createOrdinaryUser();
        $oldHash = $target->password;

        $this->actingAs($superAdministrator);
        $component = Volt::test('admin.users-manager')
            ->call('preparePasswordReset', $target->id)
            ->set('temporaryPassword', 'Temporary-Password-2026!')
            ->set('temporaryPassword_confirmation', 'Temporary-Password-2026!');

        $this->actingAs($unauthorizedUser);
        $component->call('resetUserPassword')->assertForbidden();

        $this->assertSame($oldHash, $target->fresh()->password);
    }

    public function test_super_administrator_password_cannot_be_reset_from_admin_interface(): void
    {
        $actingSuperAdministrator = $this->createSuperAdministrator();
        $targetSuperAdministrator = $this->createSuperAdministrator();
        $oldHash = $targetSuperAdministrator->password;

        $this->actingAs($actingSuperAdministrator);

        Volt::test('admin.users-manager')
            ->call('preparePasswordReset', $targetSuperAdministrator->id)
            ->assertForbidden();

        $this->assertSame($oldHash, $targetSuperAdministrator->fresh()->password);
    }

    public function test_database_sessions_are_invalidated_for_target_user_only(): void
    {
        config()->set('session.driver', 'database');

        $target = $this->createOrdinaryUser();
        $otherUser = $this->createOrdinaryUser();

        DB::table('sessions')->insert([
            [
                'id' => 'target-session',
                'user_id' => $target->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => 'payload',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'other-session',
                'user_id' => $otherUser->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => 'payload',
                'last_activity' => now()->timestamp,
            ],
        ]);

        app(PasswordManagementService::class)
            ->resetByAdministrator($target->id, 'Temporary-Password-2026!');

        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
    }

    public function test_password_hash_change_invalidates_an_existing_authenticated_session(): void
    {
        $target = $this->createOrdinaryUser();
        $oldPasswordHash = $target->password;

        app(PasswordManagementService::class)
            ->resetByAdministrator($target->id, 'Temporary-Password-2026!');

        $this->actingAs($target->fresh())
            ->withSession(['password_hash_web' => $oldPasswordHash])
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_password_target_identifier_is_locked_against_livewire_tampering(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $firstTarget = $this->createOrdinaryUser();
        $secondTarget = $this->createOrdinaryUser();

        $this->actingAs($superAdministrator);
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Volt::test('admin.users-manager')
            ->call('preparePasswordReset', $firstTarget->id)
            ->set('passwordUserId', $secondTarget->id);
    }

    private function createOrdinaryUser(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'commercial')->firstOrFail());

        return $user;
    }

    private function createSuperAdministrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());

        return $user;
    }

    private function createNonSuperAdministratorWithUserPermissions(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', 'admin')->firstOrFail();
        $permissions = Permission::query()->whereIn('name', ['users.view', 'users.manage'])->get();

        $role->permissions()->sync($permissions->modelKeys());
        $user->roles()->attach($role);

        return $user;
    }
}
