<?php

namespace Tests\Feature;

use App\Models\DocumentSequence;
use App\Models\PaymentTerm;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\DocumentSequenceManagementService;
use App\Services\PurchaseOrderManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use LogicException;
use Tests\TestCase;

class PurchaseOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_purchase_permissions_routes_services_livewire_and_super_admin_are_enforced(): void
    {
        $this->get(route('purchases.orders.index'))->assertRedirect(route('login'));
        $this->createSequence();
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $this->actingAs($this->userWithPermissions(['purchases.create']));
        $order = app(PurchaseOrderManagementService::class)->create($this->attributes($supplier), [$this->line($product)]);

        $this->actingAs($this->userWithPermissions([]));
        $this->get(route('purchases.orders.index'))->assertForbidden();
        $this->get(route('purchases.orders.create'))->assertForbidden();
        $this->get(route('purchases.orders.show', $order))->assertForbidden();
        $this->get(route('purchases.orders.edit', $order))->assertForbidden();
        Volt::test('admin.purchase-orders-manager')->assertForbidden();
        Volt::test('admin.purchase-order-form')->assertForbidden();
        Volt::test('admin.purchase-order-details', ['orderId' => $order->id])->assertForbidden();

        foreach ([
            fn () => app(PurchaseOrderManagementService::class)->create($this->attributes($supplier), [$this->line($product)]),
            fn () => app(PurchaseOrderManagementService::class)->updateDraft($order, $this->attributes($supplier), [$this->line($product)]),
            fn () => app(PurchaseOrderManagementService::class)->confirm($order),
            fn () => app(PurchaseOrderManagementService::class)->cancelDraft($order),
        ] as $attempt) {
            try {
                $attempt();
                $denied = false;
            } catch (AuthorizationException) {
                $denied = true;
            }
            $this->assertTrue($denied);
        }

        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());
        $this->actingAs($superAdmin);
        $this->get(route('purchases.orders.index'))->assertOk()->assertSeeVolt('admin.purchase-orders-manager');
        $this->get(route('purchases.orders.create'))->assertOk()->assertSeeVolt('admin.purchase-order-form');
        Volt::test('admin.document-sequences-manager')
            ->set('documentType', 'purchase_order')
            ->assertSet('prefix', 'BCF');
    }

    public function test_valid_draft_uses_purchase_prices_exact_money_and_immutable_snapshots_without_stock_changes(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.create', 'purchases.view']));
        $term = PaymentTerm::query()->create(['label' => 'À 30 jours', 'due_days' => 30]);
        $supplier = $this->createSupplier([
            'name' => 'Atlas initial', 'trade_name' => 'Atlas Pro', 'address' => 'Rue initiale', 'city' => 'Rabat',
            'country' => 'Maroc', 'email' => 'atlas@example.test', 'phone' => '0500000000', 'ice' => 'ICE-ATLAS',
            'tax_id' => 'IF-ATLAS', 'commercial_register' => 'RC-ATLAS', 'payment_term_id' => $term->id,
        ]);
        $unit = Unit::query()->create(['symbol' => 'kg', 'name' => 'Kilogramme']);
        $tax = TaxRate::query()->create(['label' => 'TVA 20', 'rate' => '20.00', 'is_default' => true]);
        $product = $this->createProduct(['reference' => 'MAT-001', 'name' => 'Matière initiale', 'unit_id' => $unit->id, 'purchase_price' => '10.05', 'tax_rate_id' => $tax->id]);
        $service = $this->createProduct(['type' => 'service', 'reference' => 'SRV-001', 'name' => 'Transport', 'purchase_price' => '5.00', 'tax_rate_id' => null]);
        $warehouse = Warehouse::query()->create(['code' => 'WH-PO', 'name' => 'Dépôt achats']);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '12.000']);
        $stockBefore = WarehouseStock::query()->get()->toArray();
        $movementsBefore = StockMovement::query()->get()->toArray();
        $this->createSequence();

        $order = app(PurchaseOrderManagementService::class)->create($this->attributes($supplier, ['payment_term_id' => $term->id]), [
            $this->line($product, '2.000', '10.05', '10.00', (string) $tax->id),
            $this->line($service, '1.500', '5.00', '0.00', 'none'),
        ]);

        $this->assertSame('BCF-'.today()->year.'-00001', $order->number);
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $order->status);
        $this->assertSame('27.60', $order->subtotal_ht);
        $this->assertSame('2.01', $order->discount_total);
        $this->assertSame('3.62', $order->tax_total);
        $this->assertSame('29.21', $order->total_ttc);
        $this->assertSame('Atlas initial', $order->supplier_name);
        $this->assertSame('À 30 jours', $order->payment_term_label);
        $this->assertSame(30, $order->payment_term_days);
        $this->assertSame('MAT-001', $order->items[0]->reference);
        $this->assertSame('Matière initiale', $order->items[0]->description);
        $this->assertSame('kg', $order->items[0]->unit_label);
        $this->assertSame('service', $order->items[1]->item_type);
        $this->assertTrue($order->histories->contains('event', 'created'));

        $supplier->forceFill(['name' => 'Atlas modifié'])->save();
        $product->forceFill(['name' => 'Matière modifiée', 'purchase_price' => '99.00'])->save();
        $term->forceFill(['label' => 'À 60 jours', 'due_days' => 60])->save();
        $this->assertSame('Atlas initial', $order->fresh()->supplier_name);
        $this->assertSame('Matière initiale', $order->items[0]->fresh()->description);
        $this->assertSame('À 30 jours', $order->fresh()->payment_term_label);
        $this->get(route('purchases.orders.show', $order))
            ->assertOk()
            ->assertSeeVolt('admin.purchase-order-details')
            ->assertSee('Atlas initial')
            ->assertSee('Matière initiale')
            ->assertSee('À 30 jours')
            ->assertDontSee('Atlas modifié')
            ->assertDontSee('Matière modifiée');
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame($movementsBefore, StockMovement::query()->get()->toArray());
    }

    public function test_inactive_supplier_product_and_invalid_lines_are_rejected_without_consuming_number(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.create']));
        $supplier = $this->createSupplier();
        $archived = $this->createSupplier(['status' => 'archived', 'archived_at' => now()]);
        $product = $this->createProduct();
        $inactive = $this->createProduct(['is_active' => false]);
        $sequence = $this->createSequence();

        foreach ([
            fn () => app(PurchaseOrderManagementService::class)->create($this->attributes($archived), [$this->line($product)]),
            fn () => app(PurchaseOrderManagementService::class)->create($this->attributes($supplier), [$this->line($inactive)]),
            fn () => app(PurchaseOrderManagementService::class)->create($this->attributes($supplier), [$this->line($product, '0.000')]),
            fn () => app(PurchaseOrderManagementService::class)->create($this->attributes($supplier), [$this->line($product, '1.000', '-0.01')]),
            fn () => app(PurchaseOrderManagementService::class)->create($this->attributes($supplier), [$this->line($product, '1.000', '1.00', '100.01')]),
            fn () => app(PurchaseOrderManagementService::class)->create($this->attributes($supplier), []),
        ] as $attempt) {
            try {
                $attempt();
                $rejected = false;
            } catch (ValidationException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        }

        $this->assertSame(0, $sequence->fresh()->counter);
        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    public function test_draft_update_confirmation_and_cancellation_preserve_history_and_immutability(): void
    {
        $this->actingAs($user = $this->userWithPermissions(['purchases.create', 'purchases.update', 'purchases.delete']));
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $this->createSequence();
        $service = app(PurchaseOrderManagementService::class);
        $order = $service->create($this->attributes($supplier), [$this->line($product)]);
        $order = $service->updateDraft($order, $this->attributes($supplier, ['notes' => 'Modifié']), [$this->line($product, '3.000')]);
        $this->assertSame('3.000', $order->items->firstOrFail()->quantity);
        $this->assertTrue($order->histories->contains('event', 'draft_updated'));

        $order = $service->confirm($order);
        $this->assertSame(PurchaseOrder::STATUS_CONFIRMED, $order->status);
        $this->assertSame($user->id, $order->confirmed_by);
        $this->assertNotNull($order->confirmed_at);
        $historyCount = $order->histories()->count();

        foreach ([
            fn () => $service->confirm($order),
            fn () => $service->updateDraft($order, $this->attributes($supplier), [$this->line($product)]),
            fn () => $service->cancelDraft($order),
        ] as $attempt) {
            try {
                $attempt();
                $rejected = false;
            } catch (ValidationException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        }
        $this->assertSame($historyCount, $order->histories()->count());

        try {
            $order->items->firstOrFail()->forceFill(['unit_price' => '1.00'])->save();
            $lineMutationRejected = false;
        } catch (LogicException) {
            $lineMutationRejected = true;
        }
        $this->assertTrue($lineMutationRejected);
        try {
            $order->delete();
            $deleteRejected = false;
        } catch (LogicException) {
            $deleteRejected = true;
        }
        $this->assertTrue($deleteRejected);

        $draft = $service->create($this->attributes($supplier), [$this->line($product)]);
        $cancelled = $service->cancelDraft($draft);
        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertTrue($cancelled->histories->contains('event', 'cancelled'));
    }

    public function test_numbering_preview_is_non_consuming_unique_and_confirmation_never_touches_stock(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.create', 'purchases.update']));
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $warehouse = Warehouse::query()->create(['code' => 'WH-NUM', 'name' => 'Dépôt numéro']);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '8.000']);
        $stockBefore = WarehouseStock::query()->get()->toArray();
        $sequence = $this->createSequence();
        $service = app(PurchaseOrderManagementService::class);
        $first = $service->create($this->attributes($supplier), [$this->line($product)]);

        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());
        $this->actingAs($superAdmin);
        $preview = app(DocumentSequenceManagementService::class)->preview([
            'document_type' => 'purchase_order', 'prefix' => 'BCF', 'year' => today()->year,
            'counter' => $sequence->fresh()->counter, 'number_format' => '{prefix}-{year}-{counter:05d}',
        ]);
        $this->assertSame('BCF-'.today()->year.'-00002', $preview);
        $this->assertSame(1, $sequence->fresh()->counter);

        $this->actingAs($this->userWithPermissions(['purchases.create', 'purchases.update']));
        $second = $service->create($this->attributes($supplier), [$this->line($product)]);
        $this->assertSame('BCF-'.today()->year.'-00001', $first->number);
        $this->assertSame('BCF-'.today()->year.'-00002', $second->number);
        $service->confirm($first);
        $this->assertSame($stockBefore, WarehouseStock::query()->get()->toArray());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_lines_relations_search_filters_pagination_and_livewire_tampering_are_safe(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.create', 'purchases.view', 'purchases.update']));
        $supplier = $this->createSupplier(['name' => 'Atlas filtres']);
        $otherSupplier = $this->createSupplier(['name' => 'Rif fournitures']);
        $product = $this->createProduct();
        $otherProduct = $this->createProduct();
        $this->createSequence();
        $service = app(PurchaseOrderManagementService::class);
        $first = $service->create($this->attributes($supplier), [$this->line($product), $this->line($otherProduct)]);
        $second = $service->create($this->attributes($otherSupplier), [$this->line($product)]);
        $service->confirm($second);

        foreach (range(3, 11) as $index) {
            PurchaseOrder::query()->forceCreate([
                'number' => sprintf('BCF-%d-%05d', today()->year, $index), 'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name, 'status' => PurchaseOrder::STATUS_DRAFT,
                'order_date' => today(), 'subtotal_ht' => '10.00', 'discount_total' => '0.00', 'tax_total' => '2.00', 'total_ttc' => '12.00',
            ]);
        }

        $this->assertTrue($first->supplier->is($supplier));
        $this->assertTrue($first->items[0]->purchaseOrder->is($first));
        $this->assertTrue($first->items[0]->product->is($product));
        $this->assertSame(2, $first->items()->count());
        $this->assertSame(1, $second->items()->count());

        Volt::test('admin.purchase-orders-manager')
            ->assertViewHas('orders', fn ($orders): bool => $orders->perPage() === 10 && $orders->total() === 11)
            ->set('search', $first->number)->assertViewHas('orders', fn ($orders): bool => $orders->total() === 1)
            ->set('search', '')->set('statusFilter', PurchaseOrder::STATUS_CONFIRMED)->assertViewHas('orders', fn ($orders): bool => $orders->total() === 1)
            ->set('statusFilter', 'all')->set('supplierFilter', (string) $supplier->id)->assertViewHas('orders', fn ($orders): bool => $orders->total() === 10);

        try {
            Volt::test('admin.purchase-order-form', ['orderId' => $first->id])->set('orderId', $second->id);
            $tamperingRejected = false;
        } catch (CannotUpdateLockedPropertyException) {
            $tamperingRejected = true;
        }
        $this->assertTrue($tamperingRejected);
    }

    public function test_half_up_rounding_uses_decimal_math(): void
    {
        $this->actingAs($this->userWithPermissions(['purchases.create']));
        $supplier = $this->createSupplier();
        $product = $this->createProduct(['purchase_price' => '0.05', 'tax_rate_id' => null]);
        $this->createSequence();
        $order = app(PurchaseOrderManagementService::class)->create($this->attributes($supplier), [$this->line($product, '0.100', '0.05', '0.00', 'none')]);
        $this->assertSame('0.01', $order->subtotal_ht);
        $this->assertSame('0.01', $order->total_ttc);
    }

    private function createSupplier(array $attributes = []): Supplier
    {
        return Supplier::query()->forceCreate(array_merge([
            'code' => 'FOU-'.str()->upper(str()->random(8)), 'name' => 'Fournisseur test', 'status' => 'active',
        ], $attributes));
    }

    private function createProduct(array $attributes = []): Product
    {
        $unit = Unit::query()->firstOrCreate(['symbol' => 'pce'], ['name' => 'Pièce']);

        return Product::query()->forceCreate(array_merge([
            'type' => 'product', 'reference' => 'PRD-'.str()->upper(str()->random(8)), 'name' => 'Produit test',
            'unit_id' => $unit->id, 'purchase_price' => '100.00', 'selling_price' => '150.00', 'is_active' => true,
        ], $attributes));
    }

    private function createSequence(): DocumentSequence
    {
        return DocumentSequence::query()->create([
            'document_type' => 'purchase_order', 'prefix' => 'BCF', 'year' => today()->year,
            'counter' => 0, 'number_format' => '{prefix}-{year}-{counter:05d}',
        ]);
    }

    private function attributes(Supplier $supplier, array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $supplier->id, 'payment_term_id' => $supplier->payment_term_id,
            'order_date' => today()->toDateString(), 'expected_date' => today()->addDays(7)->toDateString(),
            'terms' => 'Conditions achat.', 'notes' => null,
        ], $overrides);
    }

    private function line(Product $product, string $quantity = '1.000', string $price = '100.00', string $discount = '0.00', ?string $taxRateId = null): array
    {
        return [
            'product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price,
            'discount_percent' => $discount, 'tax_rate_id' => $taxRateId,
        ];
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'Purchase test '.$user->id, 'slug' => 'purchase-test-'.$user->id]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }
}
