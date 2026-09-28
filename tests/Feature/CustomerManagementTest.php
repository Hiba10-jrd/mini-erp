<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\PaymentTerm;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_guests_are_denied_and_view_permission_can_consult_clients(): void
    {
        $this->get(route('admin.customers.index'))->assertRedirect(route('login'));

        $viewer = $this->createUserWithPermissions(['customers.view']);
        $this->actingAs($viewer)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSeeVolt('admin.customers-manager');

        Volt::test('admin.customers-manager')
            ->call('prepareCreate')
            ->assertForbidden();
    }

    public function test_manage_permission_is_required_for_livewire_mutations(): void
    {
        $viewer = $this->createUserWithPermissions(['customers.view']);
        $customer = $this->createCustomer(['code' => 'CLI-00001', 'customer_type' => 'company', 'name' => 'Atlas']);
        $this->actingAs($viewer);

        Volt::test('admin.customers-manager')
            ->set('name', 'Unauthorized customer')
            ->call('saveCustomer')
            ->assertForbidden();

        Volt::test('admin.customer-contacts-manager', ['customerId' => $customer->id])
            ->set('firstName', 'Unauthorized')
            ->set('lastName', 'Contact')
            ->call('saveContact')
            ->assertForbidden();

        $this->assertDatabaseMissing('customers', ['name' => 'Unauthorized customer']);
        $this->assertDatabaseCount('customer_contacts', 0);
    }

    public function test_super_administrator_can_create_individual_and_company_clients(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $paymentTerm = PaymentTerm::query()->create(['label' => 'Paiement à 30 jours', 'due_days' => 30]);

        Volt::test('admin.customers-manager')
            ->call('prepareCreate')
            ->set('customerType', 'individual')
            ->set('name', 'Alice Martin')
            ->set('email', 'alice@example.test')
            ->set('paymentTermId', (string) $paymentTerm->id)
            ->set('creditLimit', '1250.50')
            ->call('saveCustomer')
            ->assertHasNoErrors();

        Volt::test('admin.customers-manager')
            ->call('prepareCreate')
            ->set('customerType', 'company')
            ->set('name', 'Atlas Conseil SARL')
            ->set('tradeName', 'Atlas')
            ->set('ice', 'ICE-TEST')
            ->call('saveCustomer')
            ->assertHasNoErrors();

        $company = Customer::query()->where('name', 'Atlas Conseil SARL')->firstOrFail();
        Volt::test('admin.customers-manager')
            ->call('editCustomer', $company->id)
            ->set('name', 'Atlas Conseil Modifié')
            ->call('saveCustomer')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', ['code' => 'CLI-00001', 'customer_type' => 'individual', 'name' => 'Alice Martin']);
        $this->assertDatabaseHas('customers', ['code' => 'CLI-00002', 'customer_type' => 'company', 'name' => 'Atlas Conseil Modifié', 'trade_name' => 'Atlas']);
        $this->assertSame('1250.50', Customer::query()->where('name', 'Alice Martin')->firstOrFail()->credit_limit);
    }

    public function test_client_validation_preserves_existing_data_and_checks_payment_term_and_credit_limit(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $customer = $this->createCustomer(['code' => 'CLI-00001', 'customer_type' => 'individual', 'name' => 'Original']);

        Volt::test('admin.customers-manager')
            ->call('editCustomer', $customer->id)
            ->set('email', 'invalid')
            ->set('creditLimit', '-1')
            ->set('paymentTermId', '99999')
            ->call('saveCustomer')
            ->assertHasErrors(['email', 'creditLimit', 'paymentTermId']);

        $this->assertSame('Original', $customer->fresh()->name);
        $this->assertNull($customer->fresh()->email);
    }

    public function test_codes_are_unique_stable_and_not_reused_after_archiving(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $component = Volt::test('admin.customers-manager')
            ->call('prepareCreate')
            ->set('name', 'First client')
            ->call('saveCustomer');
        $first = Customer::query()->where('name', 'First client')->firstOrFail();

        $component->call('archiveCustomer', $first->id);
        $component->call('prepareCreate')->set('name', 'Second client')->call('saveCustomer');
        $second = Customer::query()->where('name', 'Second client')->firstOrFail();

        $this->assertSame('CLI-00001', $first->code);
        $this->assertSame('CLI-00002', $second->code);
        $this->assertSame('archived', $first->fresh()->status);
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_search_filters_and_pagination_work(): void
    {
        $this->actingAs($this->createUserWithPermissions(['customers.view']));
        $this->createCustomer(['code' => 'CLI-00001', 'customer_type' => 'individual', 'name' => 'Alice Active']);
        $archived = $this->createCustomer(['code' => 'CLI-00002', 'customer_type' => 'company', 'name' => 'Atlas Company']);
        $archived->forceFill(['status' => 'archived'])->save();
        foreach (range(1, 10) as $index) {
            $this->createCustomer(['code' => sprintf('CLI-%05d', $index + 2), 'customer_type' => 'individual', 'name' => "Customer {$index}"]);
        }

        Volt::test('admin.customers-manager')
            ->set('search', 'Alice')
            ->assertSee('Alice Active')
            ->assertDontSee('Atlas Company')
            ->set('search', '')
            ->set('statusFilter', 'archived')
            ->assertSee('Atlas Company')
            ->set('statusFilter', 'all')
            ->set('typeFilter', 'company')
            ->assertSee('Atlas Company')
            ->assertViewHas('customers', fn ($customers): bool => $customers->perPage() === 10 && $customers->total() === 1);
    }

    public function test_customer_details_are_consultable_without_showing_a_fake_balance(): void
    {
        $customer = $this->createCustomer(['code' => 'CLI-00001', 'customer_type' => 'company', 'name' => 'Atlas Company']);
        $viewer = $this->createUserWithPermissions(['customers.view', 'customers.manage']);

        $this->actingAs($viewer)
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee('Atlas Company')
            ->assertSee('Contacts')
            ->assertSee('Ajouter un contact')
            ->assertSee('Aucun solde n’est calculé');
    }

    public function test_individual_customer_hides_contacts_and_shows_an_informative_message(): void
    {
        $customer = $this->createCustomer(['code' => 'CLI-00001', 'customer_type' => 'individual', 'name' => 'Alice Martin']);
        CustomerContact::query()->forceCreate([
            'customer_id' => $customer->id,
            'first_name' => 'Legacy',
            'last_name' => 'Contact',
        ]);
        $viewer = $this->createUserWithPermissions(['customers.view']);

        $this->actingAs($viewer)
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee('Les coordonnées de ce client particulier sont directement enregistrées dans sa fiche.')
            ->assertDontSee('Ajouter un contact')
            ->assertDontSee('Aucun contact enregistré.')
            ->assertDontSee('Legacy Contact');

        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customer->id,
            'first_name' => 'Legacy',
            'last_name' => 'Contact',
        ]);
    }

    public function test_contacts_can_be_created_updated_and_disabled_for_their_customer_only(): void
    {
        $manager = $this->createUserWithPermissions(['customers.view', 'customers.manage']);
        $customer = $this->createCustomer(['code' => 'CLI-00001', 'customer_type' => 'company', 'name' => 'Atlas']);
        $otherCustomer = $this->createCustomer(['code' => 'CLI-00002', 'customer_type' => 'company', 'name' => 'Other']);
        $this->actingAs($manager);

        Volt::test('admin.customer-contacts-manager', ['customerId' => $customer->id])
            ->set('firstName', 'Nora')
            ->set('lastName', 'Diallo')
            ->set('jobTitle', 'Direction')
            ->set('email', 'nora@example.test')
            ->set('isPrimary', true)
            ->call('saveContact')
            ->assertHasNoErrors();

        $contact = CustomerContact::query()->where('customer_id', $customer->id)->firstOrFail();
        Volt::test('admin.customer-contacts-manager', ['customerId' => $customer->id])
            ->call('editContact', $contact->id)
            ->set('jobTitle', 'Direction générale')
            ->call('saveContact')
            ->assertHasNoErrors();
        $this->assertSame('Direction générale', $contact->fresh()->job_title);

        Volt::test('admin.customer-contacts-manager', ['customerId' => $customer->id])
            ->call('archiveContact', $contact->id)
            ->assertHasNoErrors();
        $this->assertFalse($contact->fresh()->is_active);

        $otherContact = CustomerContact::query()->forceCreate(['customer_id' => $otherCustomer->id, 'first_name' => 'Other', 'last_name' => 'Contact']);
        $this->expectException(ModelNotFoundException::class);
        Volt::test('admin.customer-contacts-manager', ['customerId' => $customer->id])->call('editContact', $otherContact->id);
    }

    public function test_contact_customer_identifier_is_locked_and_archiving_preserves_the_contact(): void
    {
        $manager = $this->createUserWithPermissions(['customers.view', 'customers.manage']);
        $customer = $this->createCustomer(['code' => 'CLI-00001', 'customer_type' => 'company', 'name' => 'Client']);
        $contact = CustomerContact::query()->forceCreate(['customer_id' => $customer->id, 'first_name' => 'A', 'last_name' => 'B']);
        $this->actingAs($manager);

        Volt::test('admin.customer-contacts-manager', ['customerId' => $customer->id])
            ->call('archiveContact', $contact->id)
            ->assertHasNoErrors();
        $this->assertFalse($contact->fresh()->is_active);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('admin.customer-contacts-manager', ['customerId' => $customer->id])
            ->set('customerId', 99999);
    }

    public function test_direct_contact_mutations_are_refused_for_an_individual_customer(): void
    {
        $manager = $this->createUserWithPermissions(['customers.view', 'customers.manage']);
        $customer = $this->createCustomer(['code' => 'CLI-00001', 'customer_type' => 'individual', 'name' => 'Alice Martin']);
        $contact = CustomerContact::query()->forceCreate([
            'customer_id' => $customer->id,
            'first_name' => 'Legacy',
            'last_name' => 'Contact',
        ]);
        $this->actingAs($manager);

        Volt::test('admin.customer-contacts-manager', ['customerId' => $customer->id])
            ->set('firstName', 'New')
            ->set('lastName', 'Contact')
            ->call('saveContact')
            ->assertHasErrors(['customer']);

        Volt::test('admin.customer-contacts-manager', ['customerId' => $customer->id])
            ->call('editContact', $contact->id)
            ->assertHasErrors(['customer']);

        $customer->forceFill(['customer_type' => 'company'])->save();
        $editComponent = Volt::test('admin.customer-contacts-manager', ['customerId' => $customer->id])
            ->call('editContact', $contact->id)
            ->assertHasNoErrors();

        $customer->forceFill(['customer_type' => 'individual'])->save();
        $editComponent
            ->set('firstName', 'Changed')
            ->call('saveContact')
            ->assertHasErrors(['customer']);

        $this->assertDatabaseCount('customer_contacts', 1);
        $this->assertDatabaseHas('customer_contacts', [
            'id' => $contact->id,
            'first_name' => 'Legacy',
            'last_name' => 'Contact',
        ]);
    }

    private function createSuperAdministrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function createCustomer(array $attributes): Customer
    {
        return Customer::query()->forceCreate($attributes);
    }

    /** @param array<int, string> $permissions */
    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', 'commercial')->firstOrFail();
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('name', $permissions)->pluck('id')
        );
        $user->roles()->attach($role);

        return $user;
    }
}
