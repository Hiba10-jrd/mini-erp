<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\DocumentSequenceManagementService;
use App\Services\QuoteManagementService;
use App\Services\SalesOrderManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use LogicException;
use Tests\TestCase;

class SalesOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_sales_permissions_and_super_admin_access_are_enforced(): void
    {
        $this->get(route('sales.orders.index'))->assertRedirect(route('login'));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $this->createSequence('order', 'CMD');

        $this->actingAs($this->createUserWithPermissions(['sales.create']));
        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product)]);
        $user = $this->createUserWithPermissions([]);
        $this->actingAs($user);
        $this->get(route('sales.orders.index'))->assertForbidden();
        $this->get(route('sales.orders.create'))->assertForbidden();
        $this->get(route('sales.orders.edit', $order))->assertForbidden();
        Volt::test('admin.sales-orders-manager')->assertForbidden();
        Volt::test('admin.sales-order-form')->assertForbidden();
        Volt::test('admin.sales-order-details', ['orderId' => $order->id])->assertForbidden();

        try {
            app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product)]);
            $createDenied = false;
        } catch (AuthorizationException) {
            $createDenied = true;
        }
        $this->assertTrue($createDenied);

        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());
        $this->actingAs($superAdmin);
        $this->get(route('sales.orders.index'))->assertOk()->assertSeeVolt('admin.sales-orders-manager');
        $this->get(route('sales.orders.create'))->assertOk()->assertSeeVolt('admin.sales-order-form');
    }

    public function test_update_and_delete_permissions_are_enforced_by_services_and_livewire(): void
    {
        $this->createSequence('order', 'CMD');
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $this->actingAs($this->createUserWithPermissions(['sales.create']));
        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [$this->line($product)]);
        $service = app(SalesOrderManagementService::class);
        $viewer = $this->createUserWithPermissions(['sales.view']);
        $this->actingAs($viewer);

        try {
            $service->updateDraft($order, $this->orderAttributes($customer), [$this->line($product)]);
            $updateDenied = false;
        } catch (AuthorizationException) {
            $updateDenied = true;
        }
        $this->assertTrue($updateDenied);

        foreach ([SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_CANCELLED] as $targetStatus) {
            try {
                $service->transition($order, $targetStatus);
                $transitionDenied = false;
            } catch (AuthorizationException) {
                $transitionDenied = true;
            }
            $this->assertTrue($transitionDenied);
        }

        Volt::test('admin.sales-order-details', ['orderId' => $order->id])
            ->call('confirmOrder')
            ->assertForbidden();
        Volt::test('admin.sales-order-details', ['orderId' => $order->id])
            ->call('cancelOrder')
            ->assertForbidden();
    }

    public function test_direct_order_calculates_multiple_lines_snapshots_and_does_not_touch_stock(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.view']));
        $customer = $this->createCustomer([
            'name' => 'Client initial',
            'trade_name' => 'Enseigne initiale',
            'address' => 'Adresse initiale',
            'city' => 'Rabat',
            'country' => 'Maroc',
            'email' => 'initial@example.test',
            'ice' => 'ICE-INITIAL',
        ]);
        $unit = Unit::query()->create(['symbol' => 'h', 'name' => 'Heure']);
        $tax = $this->createTaxRate('TVA 10', '10.00', true);
        $product = $this->createProduct([
            'type' => 'product',
            'reference' => 'BUREAU-01',
            'name' => 'Bureau initial',
            'unit_id' => $unit->id,
            'selling_price' => '1350.00',
            'tax_rate_id' => $tax->id,
        ]);
        $serviceProduct = $this->createProduct([
            'type' => 'service',
            'reference' => 'POSE-01',
            'name' => 'Installation',
            'selling_price' => '100.00',
        ]);
        $warehouse = Warehouse::query()->create(['code' => 'WH-ORDER', 'name' => 'Dépôt commandes']);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '17.250']);
        $this->createSequence('order', 'CMD');
        $stockBefore = WarehouseStock::query()->get()->toArray();
        $movementsBefore = StockMovement::query()->get()->toArray();

        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [
            $this->line($product, '2.000', '1350.00', '10.00'),
            $this->line($serviceProduct, '1.500', '100.00', '0.00', null),
        ]);

        $this->assertSame('CMD-'.today()->year.'-00001', $order->number);
        $this->assertSame(SalesOrder::STATUS_DRAFT, $order->status);
        $this->assertSame(2, $order->items->count());
        $this->assertSame('2.000', $order->items[0]->ordered_quantity);
        $this->assertSame('0.000', $order->items[0]->delivered_quantity);
        $this->assertSame('2.000', $order->remainingQuantity($order->items[0]));
        $this->assertSame('1350.00', $order->items[0]->unit_price);
        $this->assertSame('h', $order->items[0]->unit_label);
        $this->assertSame('10.00', $order->items[0]->tax_rate_percent);
        $this->assertSame('service', $order->items[1]->item_type);
        $this->assertSame('1.500', $order->items[1]->ordered_quantity);
        $this->assertSame('2850.00', $order->subtotal_ht);
        $this->assertSame('270.00', $order->discount_total);
        $this->assertSame('258.00', $order->tax_total);
        $this->assertSame('2838.00', $order->total_ttc);
        $this->assertSame('Client initial', $order->customer_name);
        $this->assertTrue($order->histories->contains('event', 'created'));

        $customer->forceFill(['name' => 'Client modifié', 'address' => 'Nouvelle adresse', 'ice' => 'ICE-MODIFIÉ'])->save();
        $product->forceFill(['name' => 'Bureau modifié', 'selling_price' => '2000.00'])->save();
        $this->assertSame('Client initial', $order->fresh()->customer_name);
        $this->assertSame('Bureau initial', $order->items[0]->fresh()->description);
        $this->assertSame('1350.00', $order->items[0]->fresh()->unit_price);

        $this->get(route('sales.orders.show', $order))
            ->assertOk()
            ->assertSee('Client initial')
            ->assertSee('Adresse initiale')
            ->assertSee('ICE-INITIAL')
            ->assertSee('Bureau initial')
            ->assertDontSee('Client modifié')
            ->assertDontSee('Bureau modifié');
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->get()->toArray());
    }

    public function test_accepted_quote_conversion_is_idempotent_and_keeps_quote_snapshots_without_stock_changes(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.view']));
        $customer = $this->createCustomer(['name' => 'Client accepté', 'address' => 'Adresse devis', 'ice' => 'ICE-DE-DEVIS']);
        $unit = Unit::query()->create(['symbol' => 'h', 'name' => 'Heure']);
        $tax = $this->createTaxRate('TVA devis', '10.00');
        $product = $this->createProduct([
            'reference' => 'BUREAU-DEVIS',
            'name' => 'Bureau au devis',
            'unit_id' => $unit->id,
            'selling_price' => '1350.00',
            'tax_rate_id' => $tax->id,
        ]);
        $warehouse = Warehouse::query()->create(['code' => 'WH-CONVERSION', 'name' => 'Dépôt conversion']);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '8.500']);
        $this->createSequence('quote', 'DEV');
        $this->createSequence('order', 'CMD');
        $quoteService = app(QuoteManagementService::class);
        $quote = $quoteService->create($this->quoteAttributes($customer), [$this->quoteLine($product, '2.000', '1350.00', '0.00', $tax->id)]);
        $quote = $quoteService->transition($quote, Quote::STATUS_SENT);
        $quote = $quoteService->transition($quote, Quote::STATUS_ACCEPTED);
        $stockBefore = WarehouseStock::query()->get()->toArray();
        $movementsBefore = StockMovement::query()->get()->toArray();
        $this->get(route('sales.quotes.show', $quote))->assertOk()->assertSee('Créer la commande');

        $changedTax = $this->createTaxRate('TVA catalogue modifiée', '20.00');
        $changedUnit = Unit::query()->create(['symbol' => 'kg', 'name' => 'Kilogramme']);
        $customer->forceFill(['name' => 'Client postérieur'])->save();
        $product->forceFill([
            'name' => 'Bureau actuel',
            'reference' => 'BUREAU-ACTUEL',
            'selling_price' => '2000.00',
            'tax_rate_id' => $changedTax->id,
            'unit_id' => $changedUnit->id,
        ])->save();
        $service = app(SalesOrderManagementService::class);

        $order = $service->createFromAcceptedQuote($quote);
        $item = $order->items->firstOrFail();
        $this->assertSame('CMD-'.today()->year.'-00001', $order->number);
        $this->assertSame($quote->id, $order->source_quote_id);
        $this->assertSame('Client accepté', $order->customer_name);
        $this->assertSame('ICE-DE-DEVIS', $order->customer_ice);
        $this->assertSame('Bureau au devis', $item->description);
        $this->assertSame('BUREAU-DEVIS', $item->reference);
        $this->assertSame('h', $item->unit_label);
        $this->assertSame('2.000', $item->ordered_quantity);
        $this->assertSame('0.000', $item->delivered_quantity);
        $this->assertSame('1350.00', $item->unit_price);
        $this->assertSame('10.00', $item->tax_rate_percent);
        $this->assertSame('2970.00', $order->total_ttc);

        $sameOrder = $service->createFromAcceptedQuote($quote);
        $this->assertSame($order->id, $sameOrder->id);
        $this->assertSame(1, SalesOrder::query()->where('source_quote_id', $quote->id)->count());
        $this->assertSame(1, DocumentSequence::query()->where('document_type', 'order')->value('counter'));
        $this->assertEqualsCanonicalizing(['created', 'created_from_quote'], $order->histories->pluck('event')->all());
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->get()->toArray());

        $service->transition($order, SalesOrder::STATUS_CONFIRMED);
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->get()->toArray());
        $this->get(route('sales.quotes.show', $quote))->assertOk()->assertSee('Voir la commande')->assertSee($order->number);
    }

    public function test_draft_updates_confirmation_locking_cancellation_and_history_are_enforced(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.delete']));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $warehouse = Warehouse::query()->create(['code' => 'WH-ORDER-STATES', 'name' => 'Dépôt états commande']);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '12.000']);
        $stockBefore = WarehouseStock::query()->get()->toArray();
        $movementsBefore = StockMovement::query()->get()->toArray();
        $this->createSequence('order', 'CMD');
        $service = app(SalesOrderManagementService::class);
        $order = $service->create($this->orderAttributes($customer), [$this->line($product)]);
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->get()->toArray());
        $order = $service->updateDraft($order, $this->orderAttributes($customer, ['notes' => 'Brouillon modifié']), [$this->line($product, '3.000')]);
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->get()->toArray());
        $this->assertSame('3.000', $order->items->firstOrFail()->ordered_quantity);
        $this->assertTrue($order->histories->contains('event', 'draft_updated'));

        $order = $service->transition($order, SalesOrder::STATUS_CONFIRMED);
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->get()->toArray());
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $order->status);
        $this->assertNotNull($order->confirmed_at);
        $this->assertTrue($order->histories->contains('event', 'confirmed'));

        foreach ([SalesOrder::STATUS_PARTIALLY_DELIVERED, SalesOrder::STATUS_DELIVERED] as $deliveryStatus) {
            try {
                $service->transition($order, $deliveryStatus);
                $deliveryTransitionAllowed = true;
            } catch (ValidationException) {
                $deliveryTransitionAllowed = false;
            }
            $this->assertFalse($deliveryTransitionAllowed);
        }

        try {
            $service->updateDraft($order, $this->orderAttributes($customer), [$this->line($product)]);
            $confirmedOrderEditable = true;
        } catch (ValidationException) {
            $confirmedOrderEditable = false;
        }
        $this->assertFalse($confirmedOrderEditable);

        try {
            $order->items->firstOrFail()->forceFill(['unit_price' => '1.00'])->save();
            $directCommercialMutationRejected = false;
        } catch (LogicException) {
            $directCommercialMutationRejected = true;
        }
        $this->assertTrue($directCommercialMutationRejected);

        $order = $service->transition($order, SalesOrder::STATUS_CANCELLED);
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->get()->toArray());
        $this->assertSame(SalesOrder::STATUS_CANCELLED, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertTrue($order->histories->contains('event', 'cancelled'));

        try {
            $service->transition($order, SalesOrder::STATUS_CONFIRMED);
            $invalidTransitionRejected = false;
        } catch (ValidationException) {
            $invalidTransitionRejected = true;
        }
        $this->assertTrue($invalidTransitionRejected);

        try {
            $order->histories->firstOrFail()->forceFill(['description' => 'Altération'])->save();
            $historyMutationRejected = false;
        } catch (LogicException) {
            $historyMutationRejected = true;
        }
        $this->assertTrue($historyMutationRejected);

        try {
            $order->delete();
            $physicalDeleteRejected = false;
        } catch (LogicException) {
            $physicalDeleteRejected = true;
        }
        $this->assertTrue($physicalDeleteRejected);
    }

    public function test_archived_customer_inactive_product_and_unaccepted_quote_are_rejected(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update']));
        $customer = $this->createCustomer();
        $archivedCustomer = $this->createCustomer(['status' => 'archived', 'archived_at' => now()]);
        $product = $this->createProduct();
        $inactiveProduct = $this->createProduct(['is_active' => false]);
        $this->createSequence('quote', 'DEV');
        $this->createSequence('order', 'CMD');
        $service = app(SalesOrderManagementService::class);

        foreach ([
            fn () => $service->create($this->orderAttributes($archivedCustomer), [$this->line($product)]),
            fn () => $service->create($this->orderAttributes($customer), [$this->line($inactiveProduct)]),
        ] as $attempt) {
            try {
                $attempt();
                $rejected = false;
            } catch (ValidationException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        }

        $quote = app(QuoteManagementService::class)->create($this->quoteAttributes($customer), [$this->quoteLine($product)]);
        try {
            $service->createFromAcceptedQuote($quote);
            $unacceptedRejected = false;
        } catch (ValidationException) {
            $unacceptedRejected = true;
        }
        $this->assertTrue($unacceptedRejected);

        $acceptedQuote = app(QuoteManagementService::class)->create($this->quoteAttributes($customer), [$this->quoteLine($product)]);
        $quoteService = app(QuoteManagementService::class);
        $acceptedQuote = $quoteService->transition($acceptedQuote, Quote::STATUS_SENT);
        $acceptedQuote = $quoteService->transition($acceptedQuote, Quote::STATUS_ACCEPTED);
        DB::table('quotes')->where('id', $acceptedQuote->id)->update(['archived_at' => now()]);
        try {
            $service->createFromAcceptedQuote($acceptedQuote);
            $archivedQuoteRejected = false;
        } catch (ValidationException) {
            $archivedQuoteRejected = true;
        }
        $this->assertTrue($archivedQuoteRejected);
        $this->assertSame(0, DocumentSequence::query()->where('document_type', 'order')->value('counter'));

        $order = $service->create($this->orderAttributes($customer), [$this->line($product)]);
        $customer->forceFill(['status' => 'archived', 'archived_at' => now(), 'name' => 'Client archivé après commande'])->save();
        $this->actingAs($this->createUserWithPermissions(['sales.view']))
            ->get(route('sales.orders.show', $order))
            ->assertOk()
            ->assertSee('Client de test')
            ->assertDontSee('Client archivé après commande');
    }

    public function test_sequence_preview_does_not_consume_numbers_and_order_list_searches_filters_and_paginates(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.view']));
        $customer = $this->createCustomer(['name' => 'Client filtres']);
        $product = $this->createProduct();
        $sequence = $this->createSequence('order', 'CMD');
        $service = app(SalesOrderManagementService::class);
        $first = $service->create($this->orderAttributes($customer), [$this->line($product)]);
        $this->assertSame('CMD-'.today()->year.'-00001', $first->number);

        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());
        $this->actingAs($superAdmin);
        $preview = app(DocumentSequenceManagementService::class)->preview([
            'prefix' => 'CMD',
            'year' => today()->year,
            'counter' => $sequence->fresh()->counter,
            'number_format' => '{prefix}-{year}-{counter:05d}',
        ]);
        $this->assertSame('CMD-'.today()->year.'-00002', $preview);
        $this->assertSame(1, $sequence->fresh()->counter);

        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.view']));
        $second = $service->create($this->orderAttributes($customer), [$this->line($product)]);
        $this->assertSame('CMD-'.today()->year.'-00002', $second->number);
        foreach (range(3, 11) as $index) {
            SalesOrder::query()->forceCreate([
                'number' => sprintf('CMD-%d-%05d', today()->year, $index),
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'status' => $index === 11 ? SalesOrder::STATUS_CONFIRMED : SalesOrder::STATUS_DRAFT,
                'order_date' => today()->toDateString(),
                'subtotal_ht' => '10.00',
                'discount_total' => '0.00',
                'tax_total' => '2.00',
                'total_ttc' => '12.00',
            ]);
        }

        Volt::test('admin.sales-orders-manager')
            ->assertViewHas('orders', fn ($orders): bool => $orders->perPage() === 10 && $orders->total() === 11)
            ->set('search', $first->number)
            ->assertViewHas('orders', fn ($orders): bool => $orders->total() === 1)
            ->set('search', '')
            ->set('statusFilter', SalesOrder::STATUS_CONFIRMED)
            ->assertViewHas('orders', fn ($orders): bool => $orders->total() === 1)
            ->set('statusFilter', 'all')
            ->set('customerFilter', (string) $customer->id)
            ->set('sourceFilter', 'direct')
            ->assertViewHas('orders', fn ($orders): bool => $orders->total() === 11);
    }

    public function test_confirmed_order_cannot_be_cancelled_after_delivery_and_delivered_quantity_is_nonnegative(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'sales.delete']));
        $customer = $this->createCustomer();
        $product = $this->createProduct();
        $this->createSequence('order', 'CMD');
        $service = app(SalesOrderManagementService::class);
        $order = $service->create($this->orderAttributes($customer), [$this->line($product, '3.000')]);

        try {
            $order->items->firstOrFail()->forceFill(['delivered_quantity' => '-0.001'])->save();
            $negativeQuantityRejected = false;
        } catch (LogicException) {
            $negativeQuantityRejected = true;
        }
        $this->assertTrue($negativeQuantityRejected);

        $order = $service->transition($order, SalesOrder::STATUS_CONFIRMED);
        $item = $order->items->firstOrFail();
        DB::table('sales_order_items')->where('id', $item->id)->update(['delivered_quantity' => '1.001']);
        $this->assertSame('1.999', $order->fresh()->remainingQuantity($item->fresh()));
        try {
            $service->transition($order, SalesOrder::STATUS_CANCELLED);
            $deliveredCancellationRejected = false;
        } catch (ValidationException) {
            $deliveredCancellationRejected = true;
        }
        $this->assertTrue($deliveredCancellationRejected);
    }

    public function test_order_money_uses_half_up_rounding_without_floats(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create']));
        $customer = $this->createCustomer();
        $product = $this->createProduct(['selling_price' => '0.05', 'tax_rate_id' => null]);
        $this->createSequence('order', 'CMD');

        $order = app(SalesOrderManagementService::class)->create($this->orderAttributes($customer), [
            $this->line($product, '0.100', '0.05', '0.00', null),
        ]);

        $this->assertSame('0.01', $order->subtotal_ht);
        $this->assertSame('0.01', $order->total_ttc);
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

    private function createSequence(string $type, string $prefix): DocumentSequence
    {
        return DocumentSequence::query()->create([
            'document_type' => $type,
            'prefix' => $prefix,
            'year' => today()->year,
            'counter' => 0,
            'number_format' => '{prefix}-{year}-{counter:05d}',
        ]);
    }

    /** @return array{customer_id: int, order_date: string, terms: ?string, notes: ?string} */
    private function orderAttributes(Customer $customer, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customer->id,
            'order_date' => today()->toDateString(),
            'terms' => 'Paiement à réception.',
            'notes' => null,
        ], $overrides);
    }

    /** @return array{customer_id: int, quote_date: string, valid_until: string, terms: string, notes: ?string} */
    private function quoteAttributes(Customer $customer): array
    {
        return [
            'customer_id' => $customer->id,
            'quote_date' => today()->toDateString(),
            'valid_until' => today()->addDays(30)->toDateString(),
            'terms' => 'Paiement à réception.',
            'notes' => null,
        ];
    }

    /** @return array{product_id: int, ordered_quantity: string, unit_price: string, discount_percent: string, tax_rate_id: ?int} */
    private function line(Product $product, string $quantity = '1.000', string $price = '100.00', string $discount = '0.00', ?int $taxRateId = null): array
    {
        return [
            'product_id' => $product->id,
            'ordered_quantity' => $quantity,
            'unit_price' => $price,
            'discount_percent' => $discount,
            'tax_rate_id' => $taxRateId,
        ];
    }

    /** @return array{product_id: int, quantity: string, unit_price: string, discount_percent: string, tax_rate_id: ?string} */
    private function quoteLine(Product $product, string $quantity = '1.000', string $price = '100.00', string $discount = '0.00', ?int $taxRateId = null): array
    {
        return [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $price,
            'discount_percent' => $discount,
            'tax_rate_id' => $taxRateId === null ? null : (string) $taxRateId,
        ];
    }

    /** @param array<int, string> $permissions */
    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'Order test '.$user->id, 'slug' => 'order-test-'.$user->id]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }
}
