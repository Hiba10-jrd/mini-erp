<?php

namespace Tests\Feature;

use App\Models\OperationHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class SetLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_update_locale_stored_in_session(): void
    {
        $response = $this->post(route('locale.update'), [
            'locale' => 'en',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertSame('en', session('locale'));
    }

    public function test_authenticated_user_can_update_locale_and_saves_to_user_record(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);

        $response = $this->actingAs($user)
            ->from('/profile')
            ->post(route('locale.update'), [
                'locale' => 'ar',
            ]);

        $response->assertRedirect('/profile');
        $this->assertSame('ar', session('locale'));
        $this->assertSame('ar', $user->fresh()->locale);
    }

    public function test_invalid_locale_is_rejected_and_session_keeps_valid_locale(): void
    {
        $response = $this->post(route('locale.update'), [
            'locale' => 'de',
        ]);

        $response->assertSessionHasErrors('locale');
        $this->assertNotSame('de', session('locale'));
    }

    public function test_user_required_to_change_password_can_still_change_locale(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'locale' => 'fr',
        ]);

        $response = $this->actingAs($user)
            ->post(route('locale.update'), [
                'locale' => 'en',
            ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('en', session('locale'));
        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_updating_user_locale_does_not_create_operation_history(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);

        $initialCount = OperationHistory::count();

        $this->actingAs($user)
            ->post(route('locale.update'), [
                'locale' => 'ar',
            ]);

        $this->assertSame($initialCount, OperationHistory::count());
    }

    public function test_arabic_locale_renders_rtl_direction_and_language_attributes(): void
    {
        $response = $this->withSession(['locale' => 'ar'])
            ->get(route('login'));

        $response->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('lang="ar"', false);
    }

    public function test_french_default_locale_renders_ltr_direction(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('lang="fr"', false);
    }

    public function test_saved_user_locale_is_used_when_session_locale_is_invalid(): void
    {
        $user = User::factory()->create(['locale' => 'ar']);

        $this->actingAs($user)->withSession(['locale' => 'de'])->get(route('login'));

        $this->assertSame('ar', App::getLocale());
        $this->assertSame('ar', Carbon::getLocale());
    }

    public function test_valid_session_locale_takes_priority_over_user_preference(): void
    {
        $user = User::factory()->create(['locale' => 'ar']);

        $this->actingAs($user)->withSession(['locale' => 'en'])->get(route('login'));

        $this->assertSame('en', App::getLocale());
        $this->assertSame('en', Carbon::getLocale());
        $this->assertSame('ar', $user->fresh()->locale);
    }

    public function test_invalid_guest_session_locale_uses_configured_default(): void
    {
        $this->withSession(['locale' => 'de'])->get(route('login'))
            ->assertOk()->assertSee('lang="fr"', false)->assertSee('dir="ltr"', false);

        $this->assertSame('fr', App::getLocale());
    }
}
