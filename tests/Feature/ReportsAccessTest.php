<?php

namespace Tests\Feature;

use App\Services\ReportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Support\ReportFixtures;
use Tests\TestCase;

class ReportsAccessTest extends TestCase
{
    use RefreshDatabase, ReportFixtures;

    public function test_guest_and_user_without_report_permission_are_denied(): void
    {
        auth()->logout();
        $this->get(route('reports.index'))->assertRedirect(route('login'));
        $this->actingAs($this->userWithPermissions(['sales.view']));
        $this->get(route('reports.index'))->assertForbidden();
        Volt::test('admin.reports-manager')->assertForbidden();
        Volt::test('dashboard-summary')->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertSee('Bienvenue dans votre ERP')->assertDontSee('CA net HT')->assertDontSee('Situation actuelle')->assertDontSee('Factures récentes');
    }

    public function test_permission_alone_allows_reports_and_both_navigation_links(): void
    {
        $this->actingAs($this->userWithPermissions(['reports.view']));
        $response = $this->get(route('reports.index'))->assertOk()->assertSeeVolt('admin.reports-manager');
        $this->assertSame(2, substr_count($response->getContent(), 'href="'.route('reports.index').'"'));
        $this->get(route('dashboard'))->assertOk()->assertSee('CA net HT');
        $this->actingAs($this->userWithPermissions(['sales.view']));
        $this->get(route('dashboard'))->assertDontSee('href="'.route('reports.index').'"', false);
    }

    public function test_revocation_blocks_subsequent_renders_and_actions(): void
    {
        $user = $this->userWithPermissions(['reports.view']);
        $this->actingAs($user);
        $reports = Volt::test('admin.reports-manager');
        $dashboard = Volt::test('dashboard-summary');
        $user->roles()->first()->permissions()->detach();
        $reports->call('selectSection', 'expenses')->assertForbidden();
        $dashboard->call('preset', 'previous')->assertForbidden();
    }

    public function test_read_service_does_not_bypass_authorization(): void
    {
        $this->actingAs($this->userWithPermissions(['payments.view']));
        $this->expectException(AuthorizationException::class);
        app(ReportService::class)->receivablesSummary();
    }

    public function test_revocation_blocks_refresh_without_an_action(): void
    {
        $user = $this->userWithPermissions(['reports.view']);
        $this->actingAs($user);
        $reports = Volt::test('admin.reports-manager');
        $dashboard = Volt::test('dashboard-summary');
        $user->roles()->first()->permissions()->detach();
        $reports->call('$refresh')->assertForbidden();
        $dashboard->call('$refresh')->assertForbidden();
    }
}
