<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_is_limited_after_five_failures(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $key = 'password-confirm:'.$user->id.':127.0.0.1';
        RateLimiter::clear($key);
        try {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                Volt::test('pages.auth.confirm-password')->set('password', 'invalid')->call('confirmPassword')->assertHasErrors('password');
            }
            $this->assertTrue(RateLimiter::tooManyAttempts($key, 5));
            Volt::test('pages.auth.confirm-password')->set('password', 'password')->call('confirmPassword')->assertHasErrors('password');
            $this->assertNull(session('auth.password_confirmed_at'));
        } finally {
            RateLimiter::clear($key);
        }
    }

    public function test_forgot_response_is_identical_for_existing_unknown_and_throttled_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        foreach ([$user->email, 'unknown@example.test', $user->email] as $email) {
            Volt::test('pages.auth.forgot-password')->set('email', $email)->call('sendPasswordResetLink')->assertHasNoErrors()->assertSet('email', '')->assertSee(__(Password::RESET_LINK_SENT));
        }
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_unknown_account_and_invalid_token_have_identical_errors(): void
    {
        $user = User::factory()->create();
        foreach ([$user->email, 'unknown@example.test'] as $email) {
            $component = Volt::test('pages.auth.reset-password', ['token' => 'invalid-token'])->set('email', $email)->set('password', 'new-password-2026')->set('password_confirmation', 'new-password-2026')->call('resetPassword')->assertHasErrors('email');
            $this->assertSame(__(Password::INVALID_TOKEN), $component->instance()->getErrorBag()->first('email'));
        }
    }
}
