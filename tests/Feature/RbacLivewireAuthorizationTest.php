<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RbacLivewireAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_role_cannot_update_profile_information_directly(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('profile.update-profile-information-form')
            ->set('name', 'Unauthorized Change')
            ->set('email', 'unauthorized@example.com')
            ->call('updateProfileInformation')
            ->assertForbidden();

        $this->assertNotSame('Unauthorized Change', $user->fresh()->name);
        $this->assertNotSame('unauthorized@example.com', $user->email);
    }

    public function test_user_without_role_cannot_update_password_directly(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertForbidden();

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_user_without_role_cannot_delete_account_directly(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertForbidden();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
    }

    public function test_user_without_role_cannot_send_verification_directly(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user);

        Volt::test('profile.update-profile-information-form')
            ->call('sendVerification')
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }
}
