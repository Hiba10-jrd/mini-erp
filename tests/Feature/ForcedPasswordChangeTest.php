<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_first_login_redirects_to_required_password_change(): void
    {
        $user = $this->createUserRequiredToChangePassword();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'Initial-Password-2026!')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('password.change.required', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_required_user_is_redirected_from_protected_pages_and_denied_direct_livewire_actions(): void
    {
        $user = $this->createUserRequiredToChangePassword();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('password.change.required'));

        $this->actingAs($user)
            ->get(route('profile'))
            ->assertRedirect(route('password.change.required'));

        $this->actingAs($user);

        Volt::test('profile.update-profile-information-form')
            ->set('name', 'Blocked Change')
            ->set('email', 'blocked@example.com')
            ->call('updateProfileInformation')
            ->assertForbidden();

        $this->assertNotSame('Blocked Change', $user->fresh()->name);
    }

    public function test_required_password_page_remains_accessible(): void
    {
        $user = $this->createUserRequiredToChangePassword();

        $this->actingAs($user)
            ->get(route('password.change.required'))
            ->assertOk()
            ->assertSeeVolt('pages.auth.change-initial-password');
    }

    public function test_user_can_change_initial_password_and_obligation_is_cleared(): void
    {
        $user = $this->createUserRequiredToChangePassword();

        $this->actingAs($user);

        Volt::test('pages.auth.change-initial-password')
            ->set('current_password', 'Initial-Password-2026!')
            ->set('password', 'Replacement-Password-2026!')
            ->set('password_confirmation', 'Replacement-Password-2026!')
            ->call('updatePassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Replacement-Password-2026!', $user->password));
        $this->assertFalse(Hash::check('Initial-Password-2026!', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_initial_password_cannot_be_reused(): void
    {
        $user = $this->createUserRequiredToChangePassword();
        $originalHash = $user->password;

        $this->actingAs($user);

        Volt::test('pages.auth.change-initial-password')
            ->set('current_password', 'Initial-Password-2026!')
            ->set('password', 'Initial-Password-2026!')
            ->set('password_confirmation', 'Initial-Password-2026!')
            ->call('updatePassword')
            ->assertHasErrors(['password']);

        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertSame($originalHash, $user->fresh()->password);
    }

    private function createUserRequiredToChangePassword(): User
    {
        $user = User::factory()->create([
            'password' => 'Initial-Password-2026!',
            'must_change_password' => true,
        ]);
        $user->roles()->attach(Role::query()->where('slug', 'commercial')->firstOrFail());

        return $user;
    }
}
