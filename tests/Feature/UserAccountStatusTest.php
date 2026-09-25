<?php

namespace Tests\Feature;

use App\Enums\UserAccountStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UserAccountStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_disabled_and_archived_users_cannot_log_in(): void
    {
        foreach ([UserAccountStatus::Disabled, UserAccountStatus::Archived] as $status) {
            $user = User::factory()->create([
                'email' => $status->value.'@example.com',
                'account_status' => $status,
            ]);

            Volt::test('pages.auth.login')
                ->set('form.email', $user->email)
                ->set('form.password', 'password')
                ->call('login')
                ->assertHasErrors(['form.email']);

            $this->assertGuest();
        }
    }

    public function test_database_defaults_preserve_existing_accounts_as_active_without_forced_change(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Existing Account',
            'email' => 'existing-account@example.com',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->findOrFail($userId);

        $this->assertSame(UserAccountStatus::Active, $user->account_status);
        $this->assertFalse($user->must_change_password);
    }

    public function test_disabled_user_with_existing_session_is_logged_out_and_cannot_use_livewire_action(): void
    {
        $user = $this->createOrdinaryUser();
        $this->actingAs($user);

        $user->forceFill(['account_status' => UserAccountStatus::Disabled])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($user->fresh());

        Volt::test('profile.update-profile-information-form')
            ->set('name', 'Forbidden Disabled Change')
            ->set('email', 'disabled-change@example.com')
            ->call('updateProfileInformation')
            ->assertForbidden();

        $this->assertNotSame('Forbidden Disabled Change', $user->fresh()->name);
    }

    public function test_super_administrator_can_disable_and_reactivate_user_without_losing_roles(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $target = $this->createOrdinaryUser();
        $roleIds = $target->roles()->pluck('roles.id')->all();

        $this->actingAs($superAdministrator);

        Volt::test('admin.users-manager')
            ->call('disableUser', $target->id)
            ->assertHasNoErrors();

        $this->assertSame(UserAccountStatus::Disabled, $target->fresh()->account_status);
        $this->assertEquals($roleIds, $target->fresh()->roles()->pluck('roles.id')->all());

        Volt::test('admin.users-manager')
            ->call('reactivateUser', $target->id)
            ->assertHasNoErrors();

        $this->assertSame(UserAccountStatus::Active, $target->fresh()->account_status);
        $this->assertEquals($roleIds, $target->fresh()->roles()->pluck('roles.id')->all());
    }

    public function test_archiving_preserves_user_and_roles_and_hides_it_from_current_list(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $target = $this->createOrdinaryUser(['name' => 'Archived Target']);
        $roleIds = $target->roles()->pluck('roles.id')->all();

        $this->actingAs($superAdministrator);

        Volt::test('admin.users-manager')
            ->call('archiveUser', $target->id)
            ->assertHasNoErrors()
            ->assertDontSee('Archived Target')
            ->set('statusFilter', 'archived')
            ->assertSee('Archived Target');

        $target->refresh();

        $this->assertSame(UserAccountStatus::Archived, $target->account_status);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertEquals($roleIds, $target->roles()->pluck('roles.id')->all());
    }

    public function test_super_administrator_cannot_be_disabled_or_archived(): void
    {
        $superAdministrator = $this->createSuperAdministrator();

        $this->actingAs($superAdministrator);

        Volt::test('admin.users-manager')
            ->call('disableUser', $superAdministrator->id)
            ->assertHasErrors(['accountStatus']);

        Volt::test('admin.users-manager')
            ->call('archiveUser', $superAdministrator->id)
            ->assertHasErrors(['accountStatus']);

        $this->assertSame(UserAccountStatus::Active, $superAdministrator->fresh()->account_status);
        $this->assertTrue($superAdministrator->fresh()->isSuperAdministrator());
    }

    private function createOrdinaryUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::query()->where('slug', 'commercial')->firstOrFail());

        return $user;
    }

    private function createSuperAdministrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());

        return $user;
    }
}
