<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\SupplierPaymentManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SupplierPaymentUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_routes_and_rbac_are_enforced(): void
    {
        $fixture = $this->validatedSupplierInvoice();

        $this->get(route('purchases.payments.index'))
            ->assertRedirect(route('login'));

        $this->get(route('purchases.payments.create'))
            ->assertRedirect(route('login'));

        $this->get(
            route(
                'purchases.invoices.payments.create',
                $fixture['invoice']
            )
        )->assertRedirect(route('login'));

        $viewer = $this->createUserWithPermissions([
            'payments.view',
        ]);

        $this->actingAs($viewer);

        $this->get(route('purchases.payments.index'))
            ->assertOk()
            ->assertSeeVolt('admin.supplier-payments-manager');

        $this->get(route('purchases.payments.create'))
            ->assertForbidden();

        $creator = $this->paymentOperator();

        $this->actingAs($creator);

        $this->get(route('purchases.payments.create'))
            ->assertOk()
            ->assertSeeVolt('admin.supplier-payment-form');

        $this->get(
            route(
                'purchases.invoices.payments.create',
                $fixture['invoice']
            )
        )
            ->assertOk()
            ->assertSeeVolt('admin.supplier-payment-form');
    }

    public function test_payment_list_shows_search_filter_and_allocations(): void
    {
        $this->actingAs($this->paymentOperator());

        $fixture = $this->validatedSupplierInvoice();

        $payment = app(
            SupplierPaymentManagementService::class
        )->create(
            $this->paymentData(
                $fixture['supplier'],
                $fixture['method'],
                '50.00'
            ),
            [
                [
                    'supplier_invoice_id' => $fixture['invoice']->id,
                    'amount' => '50.00',
                ],
            ]
        );

        $component = Volt::test(
            'admin.supplier-payments-manager'
        );

        $component
            ->assertSee($fixture['supplier']->name)
            ->assertSee($fixture['invoice']->number)
            ->assertSee('PAY-SUP-TEST')
            ->assertSee('50');

        $component
            ->set(
                'supplierFilter',
                (string) $fixture['supplier']->id
            )
            ->assertSee($fixture['supplier']->name)
            ->assertSee($fixture['invoice']->number);

        $component
            ->set('search', $fixture['invoice']->number)
            ->assertSee($fixture['invoice']->number);

        $component
            ->set('search', 'PAY-SUP-TEST')
            ->assertSee($fixture['supplier']->name);

        $this->assertSame(
            $fixture['supplier']->id,
            $payment->supplier_id
        );
    }

    public function test_invoice_payment_form_is_prefilled_with_remaining_amount(): void
    {
        $this->actingAs($this->paymentOperator());

        $fixture = $this->validatedSupplierInvoice();

        Volt::test(
            'admin.supplier-payment-form',
            [
                'supplierInvoiceId' => $fixture['invoice']->id,
            ]
        )
            ->assertSet(
                'supplierInvoiceId',
                $fixture['invoice']->id
            )
            ->assertSet(
                'supplierId',
                $fixture['supplier']->id
            )
            ->assertSet('amount', '120.00')
            ->assertSee($fixture['invoice']->number)
            ->assertSee(
                $fixture['invoice']
                    ->supplier_invoice_number
            )
            ->assertSee('120,00');
    }

    public function test_general_form_creates_multi_invoice_bank_transfer(): void
    {
        $this->actingAs($this->paymentOperator());

        $first = $this->validatedSupplierInvoice();

        $second = $this->validatedSupplierInvoice(
            SupplierInvoice::STATUS_VALIDATED,
            $first['supplier']
        );

        $component = Volt::test(
            'admin.supplier-payment-form'
        )
            ->set(
                'supplierId',
                $first['supplier']->id
            )
            ->set(
                'paymentMethodId',
                $first['method']->id
            )
            ->call(
                'addAllocation',
                $first['invoice']->id
            )
            ->call(
                'addAllocation',
                $second['invoice']->id
            );

        $component
            ->set(
                'allocations.0.amount',
                '60.00'
            )
            ->set(
                'allocations.1.amount',
                '40.00'
            )
            ->set(
                'paymentDate',
                today()->toDateString()
            )
            ->set(
                'reference',
                'VIR-SUP-001'
            )
            ->set(
                'transactionReference',
                'TRX-SUP-001'
            )
            ->set(
                'bankName',
                'Banque test'
            )
            ->set(
                'notes',
                'Règlement multi-factures'
            )
            ->call('save')
            ->assertHasNoErrors();

        $payment = SupplierPayment::query()
            ->with('allocations')
            ->firstOrFail();

        $this->assertSame(
            $first['supplier']->id,
            $payment->supplier_id
        );

        $this->assertSame(
            '100.00',
            $payment->amount
        );

        $this->assertSame(
            'VIR-SUP-001',
            $payment->reference
        );

        $this->assertSame(
            'TRX-SUP-001',
            $payment->details[
                'transaction_reference'
            ]
        );

        $this->assertSame(
            'Banque test',
            $payment->details['bank_name']
        );

        $this->assertCount(
            2,
            $payment->allocations
        );

        $this->assertDatabaseHas(
            'supplier_payment_allocations',
            [
                'supplier_payment_id' => $payment->id,
                'supplier_invoice_id' => $first['invoice']->id,
                'amount' => '60.00',
            ]
        );

        $this->assertDatabaseHas(
            'supplier_payment_allocations',
            [
                'supplier_payment_id' => $payment->id,
                'supplier_invoice_id' => $second['invoice']->id,
                'amount' => '40.00',
            ]
        );
    }

    public function test_supplier_invoice_details_show_payment_state_and_history(): void
    {
        $this->actingAs($this->paymentOperator());

        $fixture = $this->validatedSupplierInvoice();

        Volt::test(
            'admin.supplier-invoice-details',
            [
                'invoiceId' => $fixture['invoice']->id,
            ]
        )
            ->assertSee('Non payée')
            ->assertSee('Enregistrer un paiement');

        $service = app(
            SupplierPaymentManagementService::class
        );

        $service->create(
            $this->paymentData(
                $fixture['supplier'],
                $fixture['method'],
                '40.00'
            ),
            [
                [
                    'supplier_invoice_id' => $fixture['invoice']->id,
                    'amount' => '40.00',
                ],
            ]
        );

        Volt::test(
            'admin.supplier-invoice-details',
            [
                'invoiceId' => $fixture['invoice']->id,
            ]
        )
            ->assertSee('Partiellement payée')
            ->assertSee('40,00')
            ->assertSee('80,00')
            ->assertSee('PAY-SUP-TEST')
            ->assertSee('Enregistrer un paiement');

        $service->create(
            $this->paymentData(
                $fixture['supplier'],
                $fixture['method'],
                '80.00'
            ),
            [
                [
                    'supplier_invoice_id' => $fixture['invoice']->id,
                    'amount' => '80.00',
                ],
            ]
        );

        Volt::test(
            'admin.supplier-invoice-details',
            [
                'invoiceId' => $fixture['invoice']->id,
            ]
        )
            ->assertSee('Payée')
            ->assertSee('120,00')
            ->assertSee('0,00')
            ->assertDontSee(
                'Enregistrer un paiement'
            );
    }

    public function test_payment_details_show_method_details_and_invoice_allocation(): void
    {
        $this->actingAs($this->paymentOperator());

        $fixture = $this->validatedSupplierInvoice();

        $payment = app(
            SupplierPaymentManagementService::class
        )->create(
            $this->paymentData(
                $fixture['supplier'],
                $fixture['method'],
                '50.00'
            ),
            [
                [
                    'supplier_invoice_id' => $fixture['invoice']->id,
                    'amount' => '50.00',
                ],
            ]
        );

        $this->get(
            route(
                'purchases.payments.show',
                $payment
            )
        )
            ->assertOk()
            ->assertSeeVolt(
                'admin.supplier-payment-details'
            )
            ->assertSee(
                $fixture['supplier']->name
            )
            ->assertSee(
                $fixture['invoice']->number
            )
            ->assertSee('TRX-TEST')
            ->assertSee('PAY-SUP-TEST')
            ->assertSee('50,00');
    }

    public function test_fully_paid_invoice_cannot_open_payment_form(): void
    {
        $this->actingAs($this->paymentOperator());

        $fixture = $this->validatedSupplierInvoice();

        app(
            SupplierPaymentManagementService::class
        )->create(
            $this->paymentData(
                $fixture['supplier'],
                $fixture['method'],
                '120.00'
            ),
            [
                [
                    'supplier_invoice_id' => $fixture['invoice']->id,
                    'amount' => '120.00',
                ],
            ]
        );

        $this->get(
            route(
                'purchases.invoices.payments.create',
                $fixture['invoice']
            )
        )->assertNotFound();
    }

    private function validatedSupplierInvoice(
        string $status =
            SupplierInvoice::STATUS_VALIDATED,
        ?Supplier $supplier = null
    ): array {
        $supplier ??= $this->createSupplier(
            'SUP-'.str()->upper(
                str()->random(8)
            )
        );

        $order = PurchaseOrder::query()
            ->forceCreate([
                'number' => 'BCF-'.str()->upper(
                    str()->random(8)
                ),
                'supplier_id' => $supplier->id,
                'status' => PurchaseOrder::STATUS_CONFIRMED,
                'order_date' => today(),
                'supplier_name' => $supplier->name,
                'subtotal_ht' => '100.00',
                'discount_total' => '0.00',
                'tax_total' => '20.00',
                'total_ttc' => '120.00',
            ]);

        $invoice = SupplierInvoice::query()
            ->forceCreate([
                'number' => $status
                        === SupplierInvoice::STATUS_VALIDATED
                            ? 'FAF-'.str()->upper(
                                str()->random(8)
                            )
                            : null,

                'supplier_invoice_number' => 'EXT-'.str()->upper(
                    str()->random(8)
                ),

                'purchase_order_id' => $order->id,
                'supplier_id' => $supplier->id,
                'status' => $status,
                'invoice_date' => today(),
                'due_date' => today()->addDays(30),

                'supplier_name' => $supplier->name,

                'subtotal_ht' => '100.00',
                'discount_total' => '0.00',
                'tax_total' => '20.00',
                'total_ttc' => '120.00',

                'validated_at' => $status
                        === SupplierInvoice::STATUS_VALIDATED
                            ? now()
                            : null,
            ]);

        $method = PaymentMethod::query()
            ->create([
                'name' => 'Virement test '.
                    str()->random(6),

                'payment_type' => PaymentMethod::TYPE_BANK_TRANSFER,

                'is_active' => true,
                'sort_order' => 0,
            ]);

        return [
            'supplier' => $supplier,
            'order' => $order,
            'invoice' => $invoice,
            'method' => $method,
        ];
    }

    private function createSupplier(
        string $code
    ): Supplier {
        return Supplier::query()
            ->forceCreate([
                'code' => $code,
                'name' => 'Fournisseur test '.$code,
                'status' => 'active',
            ]);
    }

    private function paymentData(
        Supplier $supplier,
        PaymentMethod $method,
        string $amount
    ): array {
        return [
            'supplier_id' => $supplier->id,
            'payment_method_id' => $method->id,
            'payment_date' => today()->toDateString(),
            'amount' => $amount,
            'reference' => 'PAY-SUP-TEST',

            'details' => [
                'transaction_reference' => 'TRX-TEST',
            ],

            'notes' => 'Paiement fournisseur test',
        ];
    }

    private function paymentOperator(): User
    {
        return $this->createUserWithPermissions([
            'payments.view',
            'payments.create',
            'purchases.view',
        ]);
    }

    private function createUserWithPermissions(
        array $permissions
    ): User {
        $user = User::factory()->create();

        $role = Role::query()->create([
            'name' => 'Supplier payment UI '.$user->id,

            'slug' => 'supplier-payment-ui-'.$user->id,
        ]);

        $role->permissions()->attach(
            Permission::query()
                ->whereIn(
                    'name',
                    $permissions
                )
                ->pluck('id')
        );

        $user->roles()->attach($role);

        return $user;
    }
}
