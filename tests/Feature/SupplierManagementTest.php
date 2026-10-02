<?php

namespace Tests\Feature;

use App\Models\PaymentTerm;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_supplier_routes_require_authentication_and_view_permission(): void
    {
        $supplier = $this->createSupplier(['code' => 'FOU-00001', 'name' => 'Atlas Supply']);

        $this->get(route('admin.suppliers.index'))->assertRedirect(route('login'));
        $this->get(route('admin.suppliers.show', $supplier))->assertRedirect(route('login'));

        $unauthorized = $this->createUserWithPermissions([]);
        $this->actingAs($unauthorized)
            ->get(route('admin.suppliers.index'))
            ->assertForbidden();

        $viewer = User::factory()->create();
        $consultation = Role::query()->where('slug', 'consultation')->firstOrFail();
        $consultation->permissions()->attach(
            Permission::query()->where('name', 'suppliers.view')->firstOrFail()
        );
        $viewer->roles()->attach($consultation);
        $this->actingAs($viewer)
            ->get(route('admin.suppliers.index'))
            ->assertOk()
            ->assertSeeVolt('admin.suppliers-manager')
            ->assertSee('Atlas Supply')
            ->assertDontSee('Nouveau fournisseur');

        $this->get(route('admin.suppliers.show', $supplier))
            ->assertOk()
            ->assertSeeVolt('admin.supplier-details')
            ->assertSee('Atlas Supply');
    }

    public function test_manage_permission_is_required_for_direct_livewire_mutations(): void
    {
        $viewer = $this->createUserWithPermissions(['suppliers.view']);
        $supplier = $this->createSupplier(['code' => 'FOU-00001', 'name' => 'Atlas Supply']);
        $this->actingAs($viewer);

        Volt::test('admin.suppliers-manager')
            ->call('prepareCreate')
            ->assertForbidden();

        Volt::test('admin.suppliers-manager')
            ->set('name', 'Unauthorized supplier')
            ->call('saveSupplier')
            ->assertForbidden();

        Volt::test('admin.suppliers-manager')
            ->call('archiveSupplier', $supplier->id)
            ->assertForbidden();

        Volt::test('admin.supplier-contacts-manager', ['supplierId' => $supplier->id])
            ->set('firstName', 'Unauthorized')
            ->set('lastName', 'Contact')
            ->call('saveContact')
            ->assertForbidden();

        $this->assertDatabaseMissing('suppliers', ['name' => 'Unauthorized supplier']);
        $this->assertDatabaseCount('supplier_contacts', 0);
        $this->assertSame('active', $supplier->fresh()->status);
    }

    public function test_super_administrator_can_create_and_update_a_supplier_with_payment_term(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $paymentTerm = PaymentTerm::query()->create([
            'label' => 'Paiement à 30 jours',
            'due_days' => 30,
        ]);

        Volt::test('admin.suppliers-manager')
            ->call('prepareCreate')
            ->set('name', 'Atlas Distribution SARL')
            ->set('tradeName', 'Atlas Pro')
            ->set('email', 'CONTACT@ATLAS.TEST')
            ->set('ice', 'ICE-ATLAS')
            ->set('taxId', 'IF-ATLAS')
            ->set('commercialRegister', 'RC-ATLAS')
            ->set('paymentTermId', (string) $paymentTerm->id)
            ->call('saveSupplier')
            ->assertHasNoErrors();

        $supplier = Supplier::query()->where('name', 'Atlas Distribution SARL')->firstOrFail();
        $this->assertSame('FOU-00001', $supplier->code);
        $this->assertSame('contact@atlas.test', $supplier->email);
        $this->assertTrue($supplier->paymentTerm->is($paymentTerm));

        Volt::test('admin.suppliers-manager')
            ->call('editSupplier', $supplier->id)
            ->set('name', 'Atlas Distribution Modifiée')
            ->set('city', 'Casablanca')
            ->call('saveSupplier')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'code' => 'FOU-00001',
            'name' => 'Atlas Distribution Modifiée',
            'city' => 'Casablanca',
            'payment_term_id' => $paymentTerm->id,
        ]);
    }

    public function test_supplier_validation_rejects_invalid_data_without_overwriting_existing_values(): void
    {
        $this->actingAs($this->createUserWithPermissions(['suppliers.view', 'suppliers.manage']));
        $supplier = $this->createSupplier([
            'code' => 'FOU-00001',
            'name' => 'Original Supplier',
            'email' => 'valid@example.test',
        ]);

        Volt::test('admin.suppliers-manager')
            ->call('editSupplier', $supplier->id)
            ->set('name', '')
            ->set('email', 'invalid')
            ->set('paymentTermId', '99999')
            ->call('saveSupplier')
            ->assertHasErrors(['name', 'email', 'paymentTermId']);

        $this->assertSame('Original Supplier', $supplier->fresh()->name);
        $this->assertSame('valid@example.test', $supplier->fresh()->email);
    }

    public function test_codes_are_unique_stable_and_not_reused_after_logical_archiving(): void
    {
        $this->actingAs($this->createUserWithPermissions(['suppliers.view', 'suppliers.manage']));
        $component = Volt::test('admin.suppliers-manager')
            ->call('prepareCreate')
            ->set('name', 'First supplier')
            ->call('saveSupplier');
        $first = Supplier::query()->where('name', 'First supplier')->firstOrFail();
        SupplierContact::query()->forceCreate([
            'supplier_id' => $first->id,
            'first_name' => 'Nora',
            'last_name' => 'Diallo',
        ]);

        $component->call('archiveSupplier', $first->id)->assertHasNoErrors();
        $component->call('prepareCreate')->set('name', 'Second supplier')->call('saveSupplier')->assertHasNoErrors();
        $second = Supplier::query()->where('name', 'Second supplier')->firstOrFail();

        $this->assertSame('FOU-00001', $first->code);
        $this->assertSame('FOU-00002', $second->code);
        $this->assertNotSame($first->code, $second->code);
        $this->assertSame('archived', $first->fresh()->status);
        $this->assertNotNull($first->fresh()->archived_at);
        $this->assertDatabaseCount('suppliers', 2);
        $this->assertDatabaseHas('supplier_contacts', [
            'supplier_id' => $first->id,
            'first_name' => 'Nora',
        ]);

        Volt::test('admin.supplier-contacts-manager', ['supplierId' => $first->id])
            ->set('firstName', 'Blocked')
            ->set('lastName', 'Contact')
            ->call('saveContact')
            ->assertHasErrors(['supplier']);
    }

    public function test_search_status_filter_and_pagination_work(): void
    {
        $this->actingAs($this->createUserWithPermissions(['suppliers.view']));
        $this->createSupplier([
            'code' => 'FOU-00001',
            'name' => 'Atlas Active',
            'email' => 'atlas@example.test',
        ]);
        $archived = $this->createSupplier([
            'code' => 'FOU-00002',
            'name' => 'Rif Archived',
            'email' => 'rif@example.test',
        ]);
        $archived->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        foreach (range(1, 10) as $index) {
            $this->createSupplier([
                'code' => sprintf('FOU-%05d', $index + 2),
                'name' => "Supplier {$index}",
            ]);
        }

        Volt::test('admin.suppliers-manager')
            ->set('search', 'FOU-00001')
            ->assertSee('Atlas Active')
            ->assertDontSee('Rif Archived')
            ->set('search', 'atlas@example.test')
            ->assertSee('Atlas Active')
            ->set('search', '')
            ->set('statusFilter', 'archived')
            ->assertSee('Rif Archived')
            ->assertDontSee('Atlas Active')
            ->set('statusFilter', 'all')
            ->assertViewHas('suppliers', fn ($suppliers): bool => $suppliers->perPage() === 10 && $suppliers->total() === 12);
    }

    public function test_supplier_details_show_legal_information_contacts_and_no_fake_purchase_data(): void
    {
        $paymentTerm = PaymentTerm::query()->create(['label' => 'Comptant', 'due_days' => 0]);
        $supplier = $this->createSupplier([
            'code' => 'FOU-00001',
            'name' => 'Atlas Supply',
            'ice' => 'ICE-123',
            'payment_term_id' => $paymentTerm->id,
        ]);
        $viewer = $this->createUserWithPermissions(['suppliers.view']);

        $this->actingAs($viewer)
            ->get(route('admin.suppliers.show', $supplier))
            ->assertOk()
            ->assertSee('Atlas Supply')
            ->assertSee('ICE-123')
            ->assertSee('Comptant')
            ->assertSee('Contacts')
            ->assertDontSee('Aucun historique d’achats ni solde n’est calculé')
            ->assertDontSee('Ajouter un contact');
    }

    public function test_contacts_can_be_created_updated_made_primary_and_disabled(): void
    {
        $this->actingAs($this->createUserWithPermissions(['suppliers.view', 'suppliers.manage']));
        $supplier = $this->createSupplier(['code' => 'FOU-00001', 'name' => 'Atlas Supply']);
        $component = Volt::test('admin.supplier-contacts-manager', ['supplierId' => $supplier->id]);

        $component
            ->set('firstName', 'Nora')
            ->set('lastName', 'Diallo')
            ->set('jobTitle', 'Achats')
            ->set('email', 'NORA@EXAMPLE.TEST')
            ->set('isPrimary', true)
            ->call('saveContact')
            ->assertHasNoErrors();
        $nora = SupplierContact::query()->where('first_name', 'Nora')->firstOrFail();

        $component
            ->set('firstName', 'Samir')
            ->set('lastName', 'Amrani')
            ->set('isPrimary', true)
            ->call('saveContact')
            ->assertHasNoErrors();
        $samir = SupplierContact::query()->where('first_name', 'Samir')->firstOrFail();

        $this->assertFalse($nora->fresh()->is_primary);
        $this->assertTrue($samir->fresh()->is_primary);
        $this->assertSame('nora@example.test', $nora->fresh()->email);

        $component
            ->call('editContact', $samir->id)
            ->set('jobTitle', 'Direction achats')
            ->call('saveContact')
            ->assertHasNoErrors();
        $this->assertSame('Direction achats', $samir->fresh()->job_title);

        $component->call('archiveContact', $samir->id)->assertHasNoErrors();
        $this->assertFalse($samir->fresh()->is_active);
        $this->assertFalse($samir->fresh()->is_primary);
        $this->assertDatabaseCount('supplier_contacts', 2);
    }

    public function test_contacts_are_isolated_between_suppliers(): void
    {
        $this->actingAs($this->createUserWithPermissions(['suppliers.view', 'suppliers.manage']));
        $supplier = $this->createSupplier(['code' => 'FOU-00001', 'name' => 'Atlas Supply']);
        $otherSupplier = $this->createSupplier(['code' => 'FOU-00002', 'name' => 'Other Supply']);
        $otherContact = SupplierContact::query()->forceCreate([
            'supplier_id' => $otherSupplier->id,
            'first_name' => 'Other',
            'last_name' => 'Contact',
        ]);

        $this->expectException(ModelNotFoundException::class);
        Volt::test('admin.supplier-contacts-manager', ['supplierId' => $supplier->id])
            ->call('editContact', $otherContact->id);
    }

    public function test_supplier_and_contact_identifiers_are_locked(): void
    {
        $this->actingAs($this->createUserWithPermissions(['suppliers.view', 'suppliers.manage']));
        $supplier = $this->createSupplier(['code' => 'FOU-00001', 'name' => 'Atlas Supply']);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('admin.supplier-contacts-manager', ['supplierId' => $supplier->id])
            ->set('supplierId', 99999);
    }

    private function createSuperAdministrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function createSupplier(array $attributes): Supplier
    {
        return Supplier::query()->forceCreate($attributes);
    }

    /** @param array<int, string> $permissions */
    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'Test role '.$user->id,
            'slug' => 'test-role-'.$user->id,
        ]);
        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissions)->pluck('id')
        );
        $user->roles()->attach($role);

        return $user;
    }
}
