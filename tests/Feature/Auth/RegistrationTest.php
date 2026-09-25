<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_route_is_disabled(): void
    {
        $this->assertFalse(
            Route::has('register')
        );

        $this->get('/register')
            ->assertNotFound();
    }

    public function test_public_registration_action_is_forbidden(): void
    {
        $component = Volt::test('pages.auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register')
            ->assertForbidden();

        $this->assertDatabaseCount('users', 0);

        $this->assertGuest();
    }

    public function test_login_page_remains_accessible(): void
    {
        $this->get('/login')
            ->assertOk();
    }
}
