<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_guest_and_non_super_administrator_cannot_access_company_settings(): void
    {
        $this->get(route('admin.company.index'))->assertRedirect(route('login'));

        $ordinaryUser = User::factory()->create();
        $this->actingAs($ordinaryUser)
            ->get(route('admin.company.index'))
            ->assertForbidden();

        $admin = User::factory()->create();
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $settingsPermission = Permission::query()->where('name', 'settings.manage')->firstOrFail();
        $adminRole->permissions()->attach($settingsPermission);
        $admin->roles()->attach($adminRole);

        $this->actingAs($admin)
            ->get(route('admin.company.index'))
            ->assertForbidden();
    }

    public function test_super_administrator_can_create_and_modify_company_information(): void
    {
        $this->actingAs($this->createSuperAdministrator());

        Volt::test('admin.company-settings')
            ->set('legalName', 'Atlas Conseil')
            ->set('tradeName', 'Atlas')
            ->set('ice', 'ICE-TEST-001')
            ->set('taxId', 'IF-TEST-001')
            ->set('commercialRegister', 'RC-TEST-001')
            ->set('address', 'Adresse de test')
            ->set('phone', '+212600000000')
            ->set('email', 'contact@example.test')
            ->set('website', 'https://example.test')
            ->set('bankName', 'Banque de test')
            ->set('bankAccountHolder', 'Atlas Conseil')
            ->set('bankReference', 'REFERENCE-TEST')
            ->call('saveCompany')
            ->assertHasNoErrors()
            ->assertSet('feedback', 'Les informations de l’entreprise ont été enregistrées.');

        $this->assertDatabaseHas('companies', [
            'legal_name' => 'Atlas Conseil',
            'trade_name' => 'Atlas',
            'email' => 'contact@example.test',
            'bank_reference' => 'REFERENCE-TEST',
        ]);

        $company = Company::query()->firstOrFail();

        Volt::test('admin.company-settings')
            ->assertSet('legalName', 'Atlas Conseil')
            ->set('legalName', 'Atlas Conseil Modifié')
            ->call('saveCompany')
            ->assertHasNoErrors();

        $this->assertSame('Atlas Conseil Modifié', $company->fresh()->legal_name);
        $this->assertSame(1, Company::query()->count());
    }

    public function test_livewire_rechecks_authorization_before_saving(): void
    {
        $superAdministrator = $this->createSuperAdministrator();
        $ordinaryUser = User::factory()->create();

        $this->actingAs($superAdministrator);
        $component = Volt::test('admin.company-settings')
            ->set('legalName', 'Tentative non autorisée');

        $this->actingAs($ordinaryUser);

        $component->call('saveCompany')->assertForbidden();
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_invalid_information_is_rejected_without_overwriting_existing_data(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $company = Company::query()->create([
            'legal_name' => 'Nom conservé',
            'email' => 'existing@example.test',
        ]);

        Volt::test('admin.company-settings')
            ->set('legalName', '')
            ->set('email', 'invalid-email')
            ->set('website', 'not-a-url')
            ->call('saveCompany')
            ->assertHasErrors(['legalName', 'email', 'website']);

        $this->assertSame('Nom conservé', $company->fresh()->legal_name);
        $this->assertSame('existing@example.test', $company->fresh()->email);
    }

    public function test_repeated_saves_keep_one_company_record(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $component = Volt::test('admin.company-settings')
            ->set('legalName', 'Première fiche')
            ->call('saveCompany')
            ->set('legalName', 'Fiche mise à jour')
            ->call('saveCompany')
            ->assertHasNoErrors();

        $this->assertSame(1, Company::query()->count());
        $this->assertSame('Fiche mise à jour', Company::query()->firstOrFail()->legal_name);
        $component->assertSet('legalName', 'Fiche mise à jour');
    }

    public function test_valid_logo_is_stored_with_a_generated_path(): void
    {
        Storage::fake('public');
        $this->actingAs($this->createSuperAdministrator());
        $logo = UploadedFile::fake()->image('company-logo.png', 120, 80);

        Volt::test('admin.company-settings')
            ->set('legalName', 'Entreprise avec logo')
            ->set('logo', $logo)
            ->call('saveCompany')
            ->assertHasNoErrors();

        $company = Company::query()->firstOrFail();

        $this->assertNotSame('company-logo.png', $company->logo_path);
        Storage::disk('public')->assertExists($company->logo_path);
    }

    public function test_invalid_logo_is_rejected_and_existing_logo_is_preserved(): void
    {
        Storage::fake('public');
        $this->actingAs($this->createSuperAdministrator());
        $validLogo = UploadedFile::fake()->image('valid-logo.png');

        Volt::test('admin.company-settings')
            ->set('legalName', 'Entreprise avec logo')
            ->set('logo', $validLogo)
            ->call('saveCompany')
            ->assertHasNoErrors();

        $company = Company::query()->firstOrFail();
        $oldLogoPath = $company->logo_path;
        $invalidLogo = UploadedFile::fake()->create('script.php', 10, 'application/x-php');

        Volt::test('admin.company-settings')
            ->set('legalName', 'Nom qui ne doit pas être enregistré')
            ->set('logo', $invalidLogo)
            ->call('saveCompany')
            ->assertHasErrors(['logo']);

        $company->refresh();
        $this->assertSame('Entreprise avec logo', $company->legal_name);
        $this->assertSame($oldLogoPath, $company->logo_path);
        Storage::disk('public')->assertExists($oldLogoPath);
    }

    public function test_replacing_logo_removes_only_the_confirmed_old_logo(): void
    {
        Storage::fake('public');
        $this->actingAs($this->createSuperAdministrator());
        $firstLogo = UploadedFile::fake()->image('first-logo.png');
        $secondLogo = UploadedFile::fake()->image('second-logo.png');

        Volt::test('admin.company-settings')
            ->set('legalName', 'Entreprise logo')
            ->set('logo', $firstLogo)
            ->call('saveCompany');

        $oldLogoPath = Company::query()->firstOrFail()->logo_path;

        Volt::test('admin.company-settings')
            ->set('logo', $secondLogo)
            ->call('saveCompany')
            ->assertHasNoErrors();

        $newLogoPath = Company::query()->firstOrFail()->logo_path;

        $this->assertNotSame($oldLogoPath, $newLogoPath);
        Storage::disk('public')->assertMissing($oldLogoPath);
        Storage::disk('public')->assertExists($newLogoPath);
    }

    private function createSuperAdministrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());

        return $user;
    }
}
