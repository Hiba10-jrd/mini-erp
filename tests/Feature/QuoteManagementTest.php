<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\QuoteCalculator;
use App\Services\QuoteManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use LogicException;
use Tests\TestCase;

class QuoteManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_line_calculations_use_exact_money_rounding(): void
    {
        $line = app(QuoteCalculator::class)->line('2', '1000.00', '10.00', '20.00');

        $this->assertSame('2000.00', $line['gross_ht']);
        $this->assertSame('200.00', $line['discount_amount']);
        $this->assertSame('1800.00', $line['subtotal_ht']);
        $this->assertSame('360.00', $line['tax_amount']);
        $this->assertSame('2160.00', $line['total_ttc']);
    }

    public function test_money_is_rounded_half_up_to_two_decimals(): void
    {
        $line = app(QuoteCalculator::class)->line('0.100', '0.05', '0.00', '0.00');

        $this->assertSame('0.01', $line['gross_ht']);
        $this->assertSame('0.01', $line['subtotal_ht']);
        $this->assertSame('0.01', $line['total_ttc']);
    }

    public function test_invalid_quantity_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(QuoteCalculator::class)->line('0', '100.00', '0.00', '20.00');
    }

    public function test_sales_permissions_are_enforced_on_routes_livewire_and_services(): void
    {
        $this->get(route('sales.quotes.index'))->assertRedirect(route('login'));

        $user = $this->createUserWithPermissions([]);
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $this->createSequence();
        $this->actingAs($user);

        $this->get(route('sales.quotes.index'))->assertForbidden();
        Volt::test('admin.quotes-manager')->assertForbidden();
        Volt::test('admin.quote-form')->assertForbidden();

        try {
            app(QuoteManagementService::class)->create($this->quoteAttributes($customer), [$this->line($product)]);
            $denied = false;
        } catch (AuthorizationException) {
            $denied = true;
        }

        $this->assertTrue($denied);
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_update_transition_and_archive_services_require_their_permissions(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create']));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $this->createSequence();
        $quote = app(QuoteManagementService::class)->create($this->quoteAttributes($customer), [$this->line($product)]);
        $this->actingAs($this->createUserWithPermissions([]));
        $service = app(QuoteManagementService::class);

        foreach ([
            fn () => $service->update($quote, $this->quoteAttributes($customer), [$this->line($product)]),
            fn () => $service->transition($quote, Quote::STATUS_SENT),
            fn () => $service->archiveDraft($quote),
        ] as $mutation) {
            try {
                $mutation();
                $denied = false;
            } catch (AuthorizationException) {
                $denied = true;
            }
            $this->assertTrue($denied);
        }

        $this->assertSame(Quote::STATUS_DRAFT, $quote->fresh()->status);
        $this->assertNull($quote->fresh()->archived_at);
    }

    public function test_super_admin_can_access_quote_list_and_create_routes(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());
        $this->actingAs($user);

        $this->get(route('sales.quotes.index'))->assertOk()->assertSeeVolt('admin.quotes-manager');
        $this->get(route('sales.quotes.create'))->assertOk()->assertSeeVolt('admin.quote-form');
    }

    public function test_creation_allocates_unique_number_and_keeps_catalog_snapshots_without_touching_stock(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update']));
        $customer = $this->createCustomer();
        $unit = Unit::query()->firstOrCreate(['symbol' => 'h'], ['name' => 'Heure']);
        $tax = $this->createTaxRate('TVA normale', '20.00');
        $physical = $this->createProduct([
            'type' => 'product',
            'reference' => 'BUREAU-01',
            'name' => 'Bureau professionnel en bois',
            'unit_id' => $unit->id,
            'tax_rate_id' => $tax->id,
            'selling_price' => '1350.00',
        ]);
        $serviceProduct = $this->createProduct([
            'type' => 'service',
            'reference' => 'POSE-01',
            'name' => 'Installation',
            'selling_price' => '85.00',
        ]);
        $warehouse = Warehouse::query()->create(['code' => 'WH-QUOTE', 'name' => 'Dépôt devis']);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $physical->id, 'quantity' => '17.500']);
        $this->createSequence();
        $stockBefore = WarehouseStock::query()->get()->toArray();
        $movementCount = StockMovement::query()->count();
        $service = app(QuoteManagementService::class);

        $quote = $service->create($this->quoteAttributes($customer), [
            $this->line($physical, '2.000', '1350.00', '10.00'),
            $this->line($serviceProduct, '1.500', '85.00', '0.00', null),
        ]);
        $secondQuote = $service->create($this->quoteAttributes($customer), [$this->line($physical, '1.000', '1350.00', '0.00')]);

        $this->assertSame('DEV-'.today()->year.'-00001', $quote->number);
        $this->assertSame('DEV-'.today()->year.'-00002', $secondQuote->number);
        $this->assertSame(2, $quote->items->count());
        $this->assertSame('BUREAU-01', $quote->items[0]->reference);
        $this->assertSame('Bureau professionnel en bois', $quote->items[0]->description);
        $this->assertSame('h', $quote->items[0]->unit_label);
        $this->assertSame('1350.00', $quote->items[0]->unit_price);
        $this->assertSame('20.00', $quote->items[0]->tax_rate_percent);
        $this->assertSame('service', $quote->items[1]->item_type);
        $this->assertSame('1.500', $quote->items[1]->quantity);
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementCount, StockMovement::query()->count());

        $physical->forceFill([
            'reference' => 'BUREAU-02',
            'name' => 'Bureau renommé',
            'selling_price' => '1500.00',
            'tax_rate_id' => null,
        ])->save();
        $tax->forceFill(['rate' => '10.00'])->save();

        $item = $quote->items[0]->fresh();
        $this->assertSame('BUREAU-01', $item->reference);
        $this->assertSame('Bureau professionnel en bois', $item->description);
        $this->assertSame('h', $item->unit_label);
        $this->assertSame('1350.00', $item->unit_price);
        $this->assertSame('20.00', $item->tax_rate_percent);
        $this->assertSame('2827.50', $quote->subtotal_ht);
        $this->assertSame('270.00', $quote->discount_total);
        $this->assertSame('486.00', $quote->tax_total);
        $this->assertSame('3043.50', $quote->total_ttc);
    }

    public function test_default_tax_rate_is_snapshotted_when_line_does_not_override_it(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create']));
        $customer = $this->createCustomer();
        $defaultTax = $this->createTaxRate('TVA par défaut', '10.00', true);
        $product = $this->createProduct(['selling_price' => '100.00']);
        $this->createSequence();

        $quote = app(QuoteManagementService::class)->create($this->quoteAttributes($customer), [[
            'product_id' => $product->id,
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'discount_percent' => '0.00',
        ]]);

        $this->assertSame($defaultTax->id, $quote->items->firstOrFail()->tax_rate_id);
        $this->assertSame('10.00', $quote->items->firstOrFail()->tax_rate_percent);
        $this->assertSame('110.00', $quote->total_ttc);
    }

    public function test_archived_customer_inactive_product_and_invalid_quantities_are_rejected(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create']));
        $customer = $this->createCustomer();
        $inactiveCustomer = $this->createCustomer(['status' => 'archived']);
        $activeProduct = $this->createProduct();
        $inactiveProduct = $this->createProduct(['is_active' => false]);
        $this->createSequence();
        $service = app(QuoteManagementService::class);

        try {
            $service->create($this->quoteAttributes($inactiveCustomer), [$this->line($activeProduct)]);
            $archivedCustomerRejected = false;
        } catch (ValidationException) {
            $archivedCustomerRejected = true;
        }
        $this->assertTrue($archivedCustomerRejected);

        try {
            $service->create($this->quoteAttributes($customer), [$this->line($inactiveProduct)]);
            $inactiveProductRejected = false;
        } catch (ValidationException) {
            $inactiveProductRejected = true;
        }
        $this->assertTrue($inactiveProductRejected);

        try {
            $service->create($this->quoteAttributes($customer), [$this->line($activeProduct, '0')]);
            $invalidQuantityRejected = false;
        } catch (ValidationException) {
            $invalidQuantityRejected = true;
        }
        $this->assertTrue($invalidQuantityRejected);
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_status_transitions_lock_commercial_content_and_archive_only_drafts(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.delete']));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $this->createSequence();
        $service = app(QuoteManagementService::class);
        $quote = $service->create($this->quoteAttributes($customer), [$this->line($product)]);

        $quote = $service->transition($quote, Quote::STATUS_SENT);
        $this->assertSame(Quote::STATUS_SENT, $quote->status);
        try {
            $service->update($quote, $this->quoteAttributes($customer, ['notes' => 'Modification interdite']), [$this->line($product)]);
            $locked = false;
        } catch (ValidationException) {
            $locked = true;
        }
        $this->assertTrue($locked);

        $accepted = $service->transition($quote, Quote::STATUS_ACCEPTED);
        $this->assertSame(Quote::STATUS_ACCEPTED, $accepted->status);
        $draft = $service->transition($accepted, Quote::STATUS_DRAFT);
        $this->assertSame(Quote::STATUS_DRAFT, $draft->status);
        $refused = $service->transition($service->transition($draft, Quote::STATUS_SENT), Quote::STATUS_REFUSED);
        $this->assertSame(Quote::STATUS_ACCEPTED, $service->transition($refused, Quote::STATUS_ACCEPTED)->status);

        $expiredAttributes = $this->quoteAttributes($customer, [
            'quote_date' => today()->subMonths(2)->toDateString(),
            'valid_until' => today()->subDay()->toDateString(),
        ]);
        $expired = $service->create($expiredAttributes, [$this->line($product)]);
        $expired = $service->transition($expired, Quote::STATUS_SENT);
        $expired = $service->transition($expired, Quote::STATUS_EXPIRED);
        $this->assertSame(Quote::STATUS_SENT, $service->transition($expired, Quote::STATUS_SENT)->status);

        $draftToArchive = $service->create($this->quoteAttributes($customer), [$this->line($product)]);
        $archived = $service->archiveDraft($draftToArchive);
        $this->assertNotNull($archived->archived_at);
        $this->assertDatabaseHas('quotes', ['id' => $draftToArchive->id]);

        try {
            $service->archiveDraft($accepted);
            $archiveRejected = false;
        } catch (ValidationException) {
            $archiveRejected = true;
        }
        $this->assertTrue($archiveRejected);
        $this->assertSame(3, Quote::query()->count());
    }

    public function test_archived_customer_keeps_historical_quote_consultable_and_invalid_transition_is_rejected(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.view', 'sales.update']));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $this->createSequence();
        $service = app(QuoteManagementService::class);
        $quote = $service->create($this->quoteAttributes($customer), [$this->line($product)]);
        $customer->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->get(route('sales.quotes.show', $quote))
            ->assertOk()
            ->assertSee($quote->number)
            ->assertSee($customer->name);

        try {
            $service->transition($quote, Quote::STATUS_ACCEPTED);
            $invalidTransitionRejected = false;
        } catch (ValidationException) {
            $invalidTransitionRejected = true;
        }

        $this->assertTrue($invalidTransitionRejected);
        $this->actingAs($this->createUserWithPermissions([]))
            ->get(route('sales.quotes.pdf', $quote))
            ->assertForbidden();
    }

    public function test_pdf_requires_sales_view_and_returns_a_pdf(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create']));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $this->createSequence();
        $quote = app(QuoteManagementService::class)->create($this->quoteAttributes($customer), [$this->line($product)]);

        $this->actingAs($this->createUserWithPermissions([]))
            ->get(route('sales.quotes.pdf', $quote))
            ->assertForbidden();

        $response = $this->actingAs($this->createUserWithPermissions(['sales.view']))
            ->get(route('sales.quotes.pdf', $quote));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_customer_details_are_snapshotted_on_quote_and_pdf(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.view']));
        $customer = $this->createCustomer([
            'name' => 'Client A',
            'address' => 'Adresse A',
            'email' => 'client-a@example.test',
            'ice' => 'ICE A',
        ]);
        $product = $this->createProduct();
        $this->createSequence();
        $quote = app(QuoteManagementService::class)->create($this->quoteAttributes($customer), [$this->line($product)]);

        $customer->forceFill([
            'name' => 'Client B',
            'address' => 'Adresse B',
            'email' => 'client-b@example.test',
            'ice' => 'ICE B',
        ])->save();

        $quote = $quote->fresh();
        $this->assertSame('Client A', $quote->customer_name);
        $this->assertSame('Adresse A', $quote->customer_address);
        $this->assertSame('client-a@example.test', $quote->customer_email);
        $this->assertSame('ICE A', $quote->customer_ice);

        $this->get(route('sales.quotes.show', $quote))
            ->assertOk()
            ->assertSee('Client A')
            ->assertSee('Adresse A')
            ->assertSee('client-a@example.test')
            ->assertSee('ICE A')
            ->assertDontSee('Client B')
            ->assertDontSee('Adresse B')
            ->assertDontSee('client-b@example.test')
            ->assertDontSee('ICE B');

        $pdfView = view('pdf.quote', [
            'quote' => $quote->load(['customer', 'creator', 'items']),
            'company' => null,
            'logoData' => null,
            'commercialSetting' => null,
        ])->render();
        $this->assertStringContainsString('Client A', $pdfView);
        $this->assertStringContainsString('Adresse A', $pdfView);
        $this->assertStringContainsString('client-a@example.test', $pdfView);
        $this->assertStringContainsString('ICE A', $pdfView);
        $this->assertStringNotContainsString('Client B', $pdfView);
        $this->assertStringNotContainsString('Adresse B', $pdfView);
        $this->assertStringNotContainsString('client-b@example.test', $pdfView);
        $this->assertStringNotContainsString('ICE B', $pdfView);

        $pdfResponse = $this->get(route('sales.quotes.pdf', $quote));
        $pdfResponse->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdfResponse->getContent());
    }

    public function test_model_rejects_physical_delete_and_illegal_direct_status_change(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update']));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $this->createSequence();
        $quote = app(QuoteManagementService::class)->create($this->quoteAttributes($customer), [$this->line($product)]);
        $quote = app(QuoteManagementService::class)->transition($quote, Quote::STATUS_SENT);

        try {
            $quote->forceFill(['status' => Quote::STATUS_DRAFT, 'notes' => 'Interdit'])->save();
            $directMutationRejected = false;
        } catch (LogicException) {
            $directMutationRejected = true;
        }
        $this->assertTrue($directMutationRejected);

        try {
            $quote->delete();
            $physicalDeleteRejected = false;
        } catch (LogicException) {
            $physicalDeleteRejected = true;
        }
        $this->assertTrue($physicalDeleteRejected);
        $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'status' => Quote::STATUS_SENT]);
    }

    public function test_direct_draft_to_sent_cannot_change_commercial_content(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create']));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $this->createSequence();
        $quote = app(QuoteManagementService::class)->create($this->quoteAttributes($customer), [$this->line($product)]);

        $this->expectException(LogicException::class);
        $quote->forceFill(['status' => Quote::STATUS_SENT, 'notes' => 'Contenu modifié pendant l’envoi'])->save();
    }

    public function test_archived_draft_cannot_be_modified_directly(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.delete']));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $this->createSequence();
        $quote = app(QuoteManagementService::class)->create($this->quoteAttributes($customer), [$this->line($product)]);
        $quote = app(QuoteManagementService::class)->archiveDraft($quote);

        $this->expectException(LogicException::class);
        $quote->forceFill(['notes' => 'Modification directe'])->save();
    }

    public function test_invalid_discount_is_rejected_by_the_business_service(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create']));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $this->createSequence();

        try {
            app(QuoteManagementService::class)->create($this->quoteAttributes($customer), [$this->line($product, '1.000', '100.00', '100.01')]);
            $discountRejected = false;
        } catch (ValidationException) {
            $discountRejected = true;
        }

        $this->assertTrue($discountRejected);
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_creating_updating_sending_and_accepting_a_quote_never_changes_stock(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update']));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $warehouse = Warehouse::query()->create(['code' => 'WH-NEUTRAL', 'name' => 'Dépôt neutre']);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '17.250']);
        $this->createSequence();
        $stockBefore = WarehouseStock::query()->get()->toArray();
        $movementsBefore = StockMovement::query()->get()->toArray();
        $service = app(QuoteManagementService::class);

        $quote = $service->create($this->quoteAttributes($customer), [$this->line($product)]);
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->get()->toArray());

        $quote = $service->update($quote, $this->quoteAttributes($customer, ['notes' => 'Mise à jour du brouillon']), [$this->line($product, '3.000')]);
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->get()->toArray());

        $quote = $service->transition($quote, Quote::STATUS_SENT);
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->get()->toArray());

        $service->transition($quote, Quote::STATUS_ACCEPTED);
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->get()->toArray());
    }

    public function test_quote_list_search_filters_and_pagination_are_available(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.view']));
        $customer = $this->createCustomer();

        foreach (range(1, 11) as $index) {
            Quote::query()->forceCreate([
                'number' => sprintf('DEV-%d-%05d', today()->year, $index),
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'status' => $index === 11 ? Quote::STATUS_SENT : Quote::STATUS_DRAFT,
                'quote_date' => today()->toDateString(),
                'subtotal_ht' => '10.00',
                'discount_total' => '0.00',
                'tax_total' => '2.00',
                'total_ttc' => '12.00',
            ]);
        }

        Volt::test('admin.quotes-manager')
            ->assertViewHas('quotes', fn ($quotes): bool => $quotes->perPage() === 10 && $quotes->total() === 11)
            ->set('search', '00011')
            ->assertViewHas('quotes', fn ($quotes): bool => $quotes->total() === 1)
            ->set('search', '')
            ->set('statusFilter', Quote::STATUS_SENT)
            ->assertViewHas('quotes', fn ($quotes): bool => $quotes->total() === 1);
    }

    private function createCustomer(array $attributes = []): Customer
    {
        return Customer::query()->forceCreate(array_merge([
            'code' => 'CLI-'.str()->upper(str()->random(8)),
            'customer_type' => 'company',
            'name' => 'Client de test',
            'status' => 'active',
        ], $attributes));
    }

    private function createProduct(array $attributes = []): Product
    {
        $unit = Unit::query()->firstOrCreate(['symbol' => 'pce'], ['name' => 'Pièce']);

        return Product::query()->forceCreate(array_merge([
            'type' => 'product',
            'reference' => 'PRD-'.str()->upper(str()->random(8)),
            'name' => 'Produit de test',
            'unit_id' => $unit->id,
            'purchase_price' => '0.00',
            'selling_price' => '100.00',
            'is_active' => true,
        ], $attributes));
    }

    private function createTaxRate(string $label, string $rate, bool $default = false): TaxRate
    {
        return TaxRate::query()->create(['label' => $label, 'rate' => $rate, 'is_default' => $default]);
    }

    private function createSequence(): DocumentSequence
    {
        return DocumentSequence::query()->create([
            'document_type' => 'quote',
            'prefix' => 'DEV',
            'year' => today()->year,
            'counter' => 0,
            'number_format' => '{prefix}-{year}-{counter:05d}',
        ]);
    }

    /** @return array{customer_id: int, quote_date: string, valid_until: ?string, terms: ?string, notes: ?string} */
    private function quoteAttributes(Customer $customer, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customer->id,
            'quote_date' => today()->toDateString(),
            'valid_until' => today()->addDays(30)->toDateString(),
            'terms' => 'Paiement à réception.',
            'notes' => null,
        ], $overrides);
    }

    /** @return array{product_id: int, description?: string, quantity: string, unit_price: string, discount_percent: string, tax_rate_id?: ?int} */
    private function line(Product $product, string $quantity = '1.000', string $price = '100.00', string $discount = '0.00', ?int $taxRateId = null): array
    {
        return [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $price,
            'discount_percent' => $discount,
            'tax_rate_id' => $taxRateId,
        ];
    }

    /** @param array<int, string> $permissions */
    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'Quote test '.$user->id, 'slug' => 'quote-test-'.$user->id]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }
}
