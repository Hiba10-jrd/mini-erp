<?php

namespace Tests\Feature;

use App\Models\CommercialSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Support\ReportFixtures;
use Tests\TestCase;

class DashboardReportingTest extends TestCase
{
    use RefreshDatabase, ReportFixtures;

    public function test_default_month_presets_and_custom_period_keep_current_balances(): void
    {
        $this->invoice();
        CommercialSetting::query()->create(['singleton' => true, 'currency_code' => 'EUR', 'currency_name' => 'Euro']);
        $component = Volt::test('dashboard-summary')->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-31')
            ->assertSee('100,00 EUR')->assertViewHas('current', fn ($current) => $current['Créances clients'] === '120.00');
        $component->call('preset', 'previous')->assertSet('from', '2026-09-01')->assertSet('to', '2026-09-30')
            ->assertViewHas('flows', fn ($flows) => $flows['CA net HT'] === '0.00')
            ->assertViewHas('current', fn ($current) => $current['Créances clients'] === '120.00');
        $component->set('from', '2026-10-05')->set('to', '2026-10-05')->call('applyPeriod')
            ->assertViewHas('flows', fn ($flows) => $flows['CA net HT'] === '100.00');
        $component->call('preset', 'today')->assertSet('from', '2026-10-05')->assertSet('to', '2026-10-05');
        $component->call('preset', 'year')->assertSet('from', '2026-01-01')->assertSet('to', '2026-12-31');
    }

    public function test_invalid_period_preserves_last_applied_period(): void
    {
        Volt::test('dashboard-summary')->set('from', '2026-11-01')->set('to', '2026-10-01')->call('applyPeriod')->assertHasErrors('to')->assertSet('appliedFrom', '2026-10-01');
        Volt::test('dashboard-summary')->set('from', 'invalid')->call('applyPeriod')->assertHasErrors('from');
    }

    public function test_dashboard_renders_recent_activity_and_bounded_lists(): void
    {
        $invoice = $this->invoice();
        $this->recordedPayment($invoice, '20.00');
        $this->get(route('dashboard'))->assertOk()->assertSee('Situation actuelle')->assertSee('Factures récentes')->assertSee('Paiements récents');
        Volt::test('dashboard-summary')->assertViewHas('activity', fn ($activity) => collect($activity)->every(fn ($rows) => $rows->count() <= 5));
    }
}
