<?php

namespace Tests\Feature;

use App\Models\CommercialSetting;
use App\Models\DocumentSequence;
use App\Models\Role;
use App\Models\TaxRate;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CommercialSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_only_super_administrator_can_access_commercial_settings(): void
    {
        $this->get(route('admin.commercial.index'))->assertRedirect(route('login'));

        $ordinaryUser = User::factory()->create();
        $this->actingAs($ordinaryUser)
            ->get(route('admin.commercial.index'))
            ->assertForbidden();

        $this->actingAs($this->createSuperAdministrator())
            ->get(route('admin.commercial.index'))
            ->assertOk()
            ->assertSeeVolt('admin.currency-settings')
            ->assertSeeVolt('admin.tax-rates-manager')
            ->assertSeeVolt('admin.payment-methods-manager')
            ->assertSeeVolt('admin.payment-terms-manager')
            ->assertSeeVolt('admin.document-sequences-manager');
    }

    public function test_livewire_actions_recheck_authorization(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $ordinaryUser = User::factory()->create();

        $this->actingAs($superAdministrator);
        $component = Volt::test('admin.currency-settings')
            ->set('currencyCode', 'MAD')
            ->set('currencyName', 'Dirham marocain');

        $this->actingAs($ordinaryUser);

        $component->call('saveCurrency')->assertForbidden();
        $this->assertDatabaseCount('commercial_settings', 0);
    }

    public function test_currency_can_be_created_modified_and_reloaded(): void
    {
        $this->actingAs($this->createSuperAdministrator());

        Volt::test('admin.currency-settings')
            ->set('currencyCode', 'mad')
            ->set('currencyName', 'Dirham marocain')
            ->call('saveCurrency')
            ->assertHasNoErrors()
            ->assertSet('currencyCode', 'MAD');

        Volt::test('admin.currency-settings')
            ->assertSet('currencyCode', 'MAD')
            ->assertSet('currencyName', 'Dirham marocain')
            ->set('currencyName', 'Dirham')
            ->call('saveCurrency')
            ->assertHasNoErrors();

        $this->assertSame('Dirham', CommercialSetting::query()->firstOrFail()->currency_name);
        $this->assertSame(1, CommercialSetting::query()->count());
    }

    public function test_invalid_currency_is_rejected_without_overwriting_existing_data(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        CommercialSetting::query()->create(['currency_code' => 'MAD', 'currency_name' => 'Dirham marocain']);

        Volt::test('admin.currency-settings')
            ->set('currencyCode', 'EURO')
            ->set('currencyName', '')
            ->call('saveCurrency')
            ->assertHasErrors(['currencyCode', 'currencyName']);

        $this->assertDatabaseHas('commercial_settings', ['currency_code' => 'MAD', 'currency_name' => 'Dirham marocain']);
    }

    public function test_tax_rates_can_be_created_modified_and_have_one_default(): void
    {
        $this->actingAs($this->createSuperAdministrator());

        Volt::test('admin.tax-rates-manager')
            ->set('label', 'Standard')
            ->set('rate', '20.00')
            ->set('isDefault', true)
            ->call('save')
            ->assertHasNoErrors();

        $standard = TaxRate::query()->firstOrFail();
        $this->assertTrue($standard->is_default);

        Volt::test('admin.tax-rates-manager')
            ->set('label', 'Réduit')
            ->set('rate', '10.50')
            ->set('isDefault', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($standard->fresh()->is_default);
        $this->assertSame(1, TaxRate::query()->where('is_default', true)->count());
    }

    public function test_invalid_tax_rate_and_inactive_default_are_rejected(): void
    {
        $this->actingAs($this->createSuperAdministrator());

        Volt::test('admin.tax-rates-manager')
            ->set('label', 'Invalide')
            ->set('rate', '101.00')
            ->call('save')
            ->assertHasErrors(['rate']);

        Volt::test('admin.tax-rates-manager')
            ->set('label', 'Inactif par défaut')
            ->set('rate', '5.00')
            ->set('isActive', false)
            ->set('isDefault', true)
            ->call('save')
            ->assertHasErrors(['isDefault']);

        $this->assertDatabaseCount('tax_rates', 0);
    }

    public function test_payment_methods_and_terms_can_be_configured(): void
    {
        $this->actingAs($this->createSuperAdministrator());

        Volt::test('admin.payment-methods-manager')
            ->set('name', 'Virement bancaire')
            ->set('sortOrder', '1')
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('admin.payment-methods-manager')
            ->set('name', 'Espèces')
            ->set('isActive', false)
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('admin.payment-terms-manager')
            ->set('label', 'Paiement à 30 jours')
            ->set('dueDays', '30')
            ->set('description', 'Condition de test')
            ->set('isDefault', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('payment_methods', ['name' => 'Virement bancaire', 'is_active' => true]);
        $this->assertDatabaseHas('payment_methods', ['name' => 'Espèces', 'is_active' => false]);
        $this->assertDatabaseHas('payment_terms', ['label' => 'Paiement à 30 jours', 'due_days' => 30, 'is_default' => true]);
    }

    public function test_document_sequences_validate_uniqueness_and_preview_without_incrementing(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $component = Volt::test('admin.document-sequences-manager')
            ->set('documentType', 'quote')
            ->set('prefix', 'DEV')
            ->set('year', '2026')
            ->set('counter', '0')
            ->set('numberFormat', '{prefix}-{year}-{counter:05d}');

        $component->assertSet('counter', '0');
        $component->call('previewNumber')
            ->assertReturned('DEV-2026-00001');
        $component->assertSet('counter', '0');

        $component->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('document_sequences', ['document_type' => 'quote', 'prefix' => 'DEV', 'counter' => 0]);

        Volt::test('admin.document-sequences-manager')
            ->set('documentType', 'quote')
            ->set('prefix', 'DEV2')
            ->set('year', '2026')
            ->set('counter', '0')
            ->set('numberFormat', '{prefix}-{year}-{counter:05d}')
            ->call('save')
            ->assertHasErrors(['documentType']);

        Volt::test('admin.document-sequences-manager')
            ->set('documentType', 'invoice')
            ->set('prefix', 'FAC')
            ->set('year', '2026')
            ->set('counter', '12')
            ->set('numberFormat', 'invalid-format')
            ->call('save')
            ->assertHasErrors(['numberFormat']);

        $this->assertSame(1, DocumentSequence::query()->count());
    }

    public function test_editing_preserves_existing_prefix_counter_and_format_until_type_changes(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $sequence = DocumentSequence::query()->create([
            'document_type' => 'quote',
            'prefix' => 'DEV-CUSTOM',
            'year' => 2026,
            'counter' => 42,
            'number_format' => '{prefix}/{year}/{counter:04d}',
        ]);

        $component = Volt::test('admin.document-sequences-manager')
            ->call('edit', $sequence->id)
            ->assertSet('prefix', 'DEV-CUSTOM')
            ->assertSet('counter', '42')
            ->assertSet('numberFormat', '{prefix}/{year}/{counter:04d}')
            ->assertSee('Mode modification')
            ->assertSee('Enregistrer les modifications')
            ->assertSeeHtml('bg-indigo-50');

        $component
            ->set('documentType', 'invoice')
            ->assertSet('prefix', 'FAC')
            ->assertSet('counter', '42')
            ->assertSet('numberFormat', '{prefix}/{year}/{counter:04d}');
    }

    public function test_prefix_suggestion_remains_customizable_for_new_sequences(): void
    {
        $this->actingAs($this->createSuperAdministrator());

        Volt::test('admin.document-sequences-manager')
            ->call('prepareCreate')
            ->set('documentType', 'order')
            ->assertSet('prefix', 'CMD')
            ->set('prefix', 'CMD-INT')
            ->set('year', '2027')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('document_sequences', [
            'document_type' => 'order',
            'prefix' => 'CMD-INT',
            'year' => 2027,
        ]);
    }

    public function test_duplicate_type_and_year_is_rejected_without_modifying_existing_sequences(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $quote = DocumentSequence::query()->create([
            'document_type' => 'quote',
            'prefix' => 'DEV-ORIGINAL',
            'year' => 2026,
            'counter' => 4,
            'number_format' => '{prefix}-{year}-{counter:05d}',
        ]);
        $order = DocumentSequence::query()->create([
            'document_type' => 'order',
            'prefix' => 'CMD-ORIGINAL',
            'year' => 2026,
            'counter' => 8,
            'number_format' => '{prefix}/{year}/{counter:03d}',
        ]);

        Volt::test('admin.document-sequences-manager')
            ->call('edit', $quote->id)
            ->set('documentType', 'order')
            ->set('prefix', 'CMD-CONFLICT')
            ->call('save')
            ->assertHasErrors(['documentType']);

        $this->assertSame('quote', $quote->fresh()->document_type);
        $this->assertSame('DEV-ORIGINAL', $quote->fresh()->prefix);
        $this->assertSame('CMD-ORIGINAL', $order->fresh()->prefix);
    }

    public function test_prepare_create_leaves_modification_mode_and_restores_creation_defaults(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $sequence = DocumentSequence::query()->create([
            'document_type' => 'credit_note',
            'prefix' => 'AV-CUSTOM',
            'year' => 2026,
            'counter' => 2,
            'number_format' => '{prefix}-{year}-{counter:02d}',
        ]);

        Volt::test('admin.document-sequences-manager')
            ->call('edit', $sequence->id)
            ->assertSee('Mode modification')
            ->call('prepareCreate')
            ->assertSet('editingId', null)
            ->assertSet('documentType', 'quote')
            ->assertSet('prefix', 'DEV')
            ->assertDontSee('Mode modification');
    }

    private function createSuperAdministrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());

        return $user;
    }
}
