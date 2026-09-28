<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Services\CatalogManagementService;
use App\Services\ProductManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_catalog_routes_require_authentication_and_stock_view_permission(): void
    {
        $unit = $this->createUnit();
        $product = $this->createProduct($unit, ['reference' => 'PRD-001', 'name' => 'Clavier']);

        $this->get(route('admin.products.index'))->assertRedirect(route('login'));
        $this->get(route('admin.products.show', $product))->assertRedirect(route('login'));

        $unauthorized = $this->createUserWithPermissions([]);
        $this->actingAs($unauthorized)
            ->get(route('admin.products.index'))
            ->assertForbidden();

        $viewer = $this->createUserWithPermissions(['stock.view']);
        $this->actingAs($viewer)
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSeeVolt('admin.products-manager')
            ->assertSeeVolt('admin.catalog-settings-manager')
            ->assertSee('Clavier')
            ->assertDontSee('Nouvel article');

        $this->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSeeVolt('admin.product-details')
            ->assertSee('Clavier');
    }

    public function test_stock_manage_is_required_for_direct_livewire_mutations(): void
    {
        $viewer = $this->createUserWithPermissions(['stock.view']);
        $unit = $this->createUnit();
        $product = $this->createProduct($unit, ['reference' => 'PRD-001']);
        $this->actingAs($viewer);

        Volt::test('admin.products-manager')
            ->call('prepareCreate')
            ->assertForbidden();

        Volt::test('admin.products-manager')
            ->set('reference', 'UNAUTHORIZED')
            ->set('name', 'Unauthorized')
            ->set('unitId', (string) $unit->id)
            ->call('saveProduct')
            ->assertForbidden();

        Volt::test('admin.products-manager')
            ->call('toggleProduct', $product->id)
            ->assertForbidden();

        Volt::test('admin.catalog-settings-manager')
            ->set('categoryName', 'Unauthorized category')
            ->call('saveCategory')
            ->assertForbidden();

        Volt::test('admin.catalog-settings-manager')
            ->set('unitName', 'Unauthorized unit')
            ->set('unitSymbol', 'XX')
            ->call('saveUnit')
            ->assertForbidden();

        $this->assertDatabaseMissing('products', ['reference' => 'UNAUTHORIZED']);
        $this->assertDatabaseMissing('categories', ['name' => 'Unauthorized category']);
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_product_and_catalog_services_recheck_stock_manage_permission(): void
    {
        $viewer = $this->createUserWithPermissions(['stock.view']);
        $unit = $this->createUnit();
        $product = $this->createProduct($unit, ['reference' => 'PRD-001']);
        $this->actingAs($viewer);

        $productServiceDenied = false;
        try {
            app(ProductManagementService::class)->setActive($product->id, false);
        } catch (AuthorizationException) {
            $productServiceDenied = true;
        }

        $catalogServiceDenied = false;
        try {
            app(CatalogManagementService::class)->setUnitActive($unit->id, false);
        } catch (AuthorizationException) {
            $catalogServiceDenied = true;
        }

        $this->assertTrue($productServiceDenied);
        $this->assertTrue($catalogServiceDenied);
        $this->assertTrue($product->fresh()->is_active);
        $this->assertTrue($unit->fresh()->is_active);
    }

    public function test_categories_and_units_are_configurable_without_physical_deletion(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));
        $component = Volt::test('admin.catalog-settings-manager');

        $component
            ->set('categoryName', 'Informatique')
            ->set('categoryDescription', 'Matériel et accessoires')
            ->call('saveCategory')
            ->assertHasNoErrors()
            ->set('unitName', 'Pièce')
            ->set('unitSymbol', 'pce')
            ->call('saveUnit')
            ->assertHasNoErrors();

        $category = Category::query()->firstOrFail();
        $unit = Unit::query()->firstOrFail();

        $component
            ->call('editCategory', $category->id)
            ->set('categoryName', 'Informatique et réseau')
            ->call('saveCategory')
            ->assertHasNoErrors()
            ->call('editUnit', $unit->id)
            ->set('unitSymbol', 'pc')
            ->call('saveUnit')
            ->assertHasNoErrors();

        $component
            ->call('toggleCategory', $category->id)
            ->call('toggleUnit', $unit->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Informatique et réseau',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('units', [
            'id' => $unit->id,
            'symbol' => 'pc',
            'is_active' => false,
        ]);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('units', 1);
    }

    public function test_super_administrator_can_create_a_physical_product_with_catalog_relations(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $category = Category::query()->create(['name' => 'Informatique']);
        $unit = $this->createUnit();
        $taxRate = TaxRate::query()->create(['label' => 'TVA 20%', 'rate' => '20.00']);

        Volt::test('admin.products-manager')
            ->call('prepareCreate')
            ->set('type', 'product')
            ->set('reference', ' prd-001 ')
            ->set('barcode', '123456789')
            ->set('name', 'Ordinateur portable')
            ->set('categoryId', (string) $category->id)
            ->set('brand', 'Atlas Tech')
            ->set('unitId', (string) $unit->id)
            ->set('purchasePrice', '7500.50')
            ->set('sellingPrice', '8999.99')
            ->set('taxRateId', (string) $taxRate->id)
            ->set('minimumStock', '2.500')
            ->set('maximumStock', '15.000')
            ->call('saveProduct')
            ->assertHasNoErrors();

        $product = Product::query()->where('reference', 'PRD-001')->firstOrFail();
        $this->assertSame('product', $product->type);
        $this->assertSame('7500.50', $product->purchase_price);
        $this->assertSame('8999.99', $product->selling_price);
        $this->assertSame('2.500', $product->minimum_stock);
        $this->assertSame('15.000', $product->maximum_stock);
        $this->assertTrue($product->category->is($category));
        $this->assertTrue($product->unit->is($unit));
        $this->assertTrue($product->taxRate->is($taxRate));
        $this->assertTrue($product->is_active);
    }

    public function test_service_rejects_stock_fields_and_service_layer_always_clears_them(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));
        $unit = Unit::query()->create(['name' => 'Heure', 'symbol' => 'h']);
        $component = Volt::test('admin.products-manager')
            ->call('prepareCreate')
            ->set('type', 'service')
            ->set('reference', 'SRV-001')
            ->set('name', 'Maintenance informatique')
            ->set('unitId', (string) $unit->id)
            ->set('purchasePrice', '0.00')
            ->set('sellingPrice', '500.00')
            ->set('minimumStock', '1')
            ->set('maximumStock', '5')
            ->call('saveProduct')
            ->assertHasErrors(['minimumStock', 'maximumStock']);

        $component
            ->set('minimumStock', '')
            ->set('maximumStock', '')
            ->call('saveProduct')
            ->assertHasNoErrors();

        $serviceProduct = Product::query()->where('reference', 'SRV-001')->firstOrFail();
        $this->assertTrue($serviceProduct->isService());
        $this->assertNull($serviceProduct->minimum_stock);
        $this->assertNull($serviceProduct->maximum_stock);

        app(ProductManagementService::class)->save($serviceProduct->id, [
            'type' => 'service',
            'reference' => 'SRV-001',
            'barcode' => null,
            'name' => 'Maintenance informatique',
            'description' => null,
            'category_id' => null,
            'brand' => null,
            'unit_id' => $unit->id,
            'purchase_price' => '0.00',
            'selling_price' => '500.00',
            'tax_rate_id' => null,
            'minimum_stock' => '99.000',
            'maximum_stock' => '100.000',
        ]);

        $this->assertNull($serviceProduct->fresh()->minimum_stock);
        $this->assertNull($serviceProduct->fresh()->maximum_stock);
    }

    public function test_product_can_be_modified_deactivated_and_reactivated(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));
        $unit = $this->createUnit();
        $product = $this->createProduct($unit, [
            'reference' => 'PRD-001',
            'name' => 'Original name',
            'selling_price' => '100.00',
        ]);
        $component = Volt::test('admin.products-manager');

        $component
            ->call('editProduct', $product->id)
            ->set('name', 'Updated name')
            ->set('sellingPrice', '125.50')
            ->call('saveProduct')
            ->assertHasNoErrors();

        $this->assertSame('Updated name', $product->fresh()->name);
        $this->assertSame('125.50', $product->fresh()->selling_price);
        $this->assertSame('PRD-001', $product->fresh()->reference);

        $component->call('toggleProduct', $product->id)->assertHasNoErrors();
        $this->assertFalse($product->fresh()->is_active);
        $component->call('toggleProduct', $product->id)->assertHasNoErrors();
        $this->assertTrue($product->fresh()->is_active);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_reference_barcode_prices_and_stock_ranges_are_validated(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));
        $unit = $this->createUnit();
        $this->createProduct($unit, [
            'reference' => 'PRD-001',
            'barcode' => 'BARCODE-001',
        ]);

        Volt::test('admin.products-manager')
            ->call('prepareCreate')
            ->set('reference', 'prd-001')
            ->set('barcode', 'BARCODE-001')
            ->set('name', 'Duplicate')
            ->set('unitId', (string) $unit->id)
            ->set('purchasePrice', '-1')
            ->set('sellingPrice', '12.345')
            ->set('minimumStock', '10')
            ->set('maximumStock', '5')
            ->call('saveProduct')
            ->assertHasErrors(['reference', 'barcode', 'purchasePrice', 'sellingPrice']);

        $component = Volt::test('admin.products-manager')
            ->call('prepareCreate')
            ->set('reference', 'PRD-002')
            ->set('name', 'Invalid range')
            ->set('unitId', (string) $unit->id)
            ->set('minimumStock', '10')
            ->set('maximumStock', '5')
            ->call('saveProduct')
            ->assertHasErrors(['maximumStock']);

        $this->assertDatabaseCount('products', 1);
        $component->assertSet('reference', 'PRD-002');
    }

    public function test_search_type_category_status_filters_and_pagination_work(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view']));
        $unit = $this->createUnit();
        $hardware = Category::query()->create(['name' => 'Hardware']);
        $services = Category::query()->create(['name' => 'Services']);
        $this->createProduct($unit, [
            'reference' => 'KEY-001',
            'barcode' => '999-KEY',
            'name' => 'Mechanical Keyboard',
            'category_id' => $hardware->id,
        ]);
        $this->createProduct($unit, [
            'type' => 'service',
            'reference' => 'SRV-001',
            'name' => 'Consulting',
            'category_id' => $services->id,
        ]);
        $inactive = $this->createProduct($unit, [
            'reference' => 'OLD-001',
            'name' => 'Inactive Product',
        ]);
        $inactive->forceFill(['is_active' => false])->save();

        foreach (range(1, 9) as $index) {
            $this->createProduct($unit, [
                'reference' => sprintf('PRD-%03d', $index + 10),
                'name' => "Product {$index}",
            ]);
        }

        Volt::test('admin.products-manager')
            ->set('search', '999-KEY')
            ->assertSee('Mechanical Keyboard')
            ->assertDontSee('Consulting')
            ->set('search', '')
            ->set('typeFilter', 'service')
            ->assertSee('Consulting')
            ->assertDontSee('Mechanical Keyboard')
            ->set('typeFilter', 'all')
            ->set('categoryFilter', (string) $hardware->id)
            ->assertSee('Mechanical Keyboard')
            ->assertDontSee('Consulting')
            ->set('categoryFilter', 'all')
            ->set('statusFilter', 'inactive')
            ->assertSee('Inactive Product')
            ->set('statusFilter', 'all')
            ->assertViewHas('products', fn ($products): bool => $products->perPage() === 10 && $products->total() === 12);
    }

    public function test_valid_product_photo_is_stored_outside_the_database(): void
    {
        Storage::fake('public');
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));
        $unit = $this->createUnit();
        $image = UploadedFile::fake()->image('keyboard.png', 300, 200);

        Volt::test('admin.products-manager')
            ->call('prepareCreate')
            ->set('reference', 'PRD-IMG')
            ->set('name', 'Product with image')
            ->set('unitId', (string) $unit->id)
            ->set('image', $image)
            ->call('saveProduct')
            ->assertHasNoErrors();

        $product = Product::query()->where('reference', 'PRD-IMG')->firstOrFail();
        $this->assertNotSame('keyboard.png', $product->image_path);
        $this->assertStringStartsWith("products/{$product->id}/images/", $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_invalid_photo_is_rejected_and_existing_product_is_preserved(): void
    {
        Storage::fake('public');
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));
        $unit = $this->createUnit();
        $product = $this->createProduct($unit, [
            'reference' => 'PRD-001',
            'name' => 'Preserved product',
            'image_path' => 'products/1/images/existing.png',
        ]);
        Storage::disk('public')->put($product->image_path, 'existing');
        $invalidImage = UploadedFile::fake()->create('payload.php', 20, 'application/x-php');

        Volt::test('admin.products-manager')
            ->call('editProduct', $product->id)
            ->set('name', 'Must not be saved')
            ->set('image', $invalidImage)
            ->call('saveProduct')
            ->assertHasErrors(['image']);

        $this->assertSame('Preserved product', $product->fresh()->name);
        $this->assertSame('products/1/images/existing.png', $product->fresh()->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_replacing_photo_deletes_only_the_previous_product_photo(): void
    {
        Storage::fake('public');
        $this->actingAs($this->createUserWithPermissions(['stock.view', 'stock.manage']));
        $unit = $this->createUnit();
        $product = $this->createProduct($unit, ['reference' => 'PRD-001']);
        $oldPath = "products/{$product->id}/images/old.png";
        $unrelatedPath = 'products/999/images/unrelated.png';
        $product->forceFill(['image_path' => $oldPath])->save();
        Storage::disk('public')->put($oldPath, 'old');
        Storage::disk('public')->put($unrelatedPath, 'unrelated');
        $newImage = UploadedFile::fake()->image('new-image.webp');

        Volt::test('admin.products-manager')
            ->call('editProduct', $product->id)
            ->set('image', $newImage)
            ->call('saveProduct')
            ->assertHasNoErrors();

        $newPath = $product->fresh()->image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertExists($unrelatedPath);
    }

    public function test_product_details_show_thresholds_and_computed_zero_stock(): void
    {
        $viewer = $this->createUserWithPermissions(['stock.view']);
        $unit = $this->createUnit();
        $product = $this->createProduct($unit, [
            'reference' => 'PRD-001',
            'name' => 'Stock prepared product',
            'minimum_stock' => '2.000',
            'maximum_stock' => '10.000',
        ]);

        $this->actingAs($viewer)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('Stock prepared product')
            ->assertSee('2.000')
            ->assertSee('10.000')
            ->assertSee('Stock total')
            ->assertSee('0,000')
            ->assertSee('Aucun dépôt configuré.');
    }

    public function test_product_identifier_is_locked_on_details_component(): void
    {
        $this->actingAs($this->createUserWithPermissions(['stock.view']));
        $unit = $this->createUnit();
        $product = $this->createProduct($unit, ['reference' => 'PRD-001']);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('admin.product-details', ['productId' => $product->id])
            ->set('productId', 99999);
    }

    private function createSuperAdministrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());

        return $user;
    }

    private function createUnit(): Unit
    {
        return Unit::query()->create(['name' => 'Pièce', 'symbol' => 'pce']);
    }

    /** @param array<string, mixed> $attributes */
    private function createProduct(Unit $unit, array $attributes = []): Product
    {
        return Product::query()->forceCreate(array_merge([
            'type' => 'product',
            'reference' => 'PRD-DEFAULT',
            'name' => 'Test product',
            'unit_id' => $unit->id,
            'purchase_price' => '0.00',
            'selling_price' => '0.00',
        ], $attributes));
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
