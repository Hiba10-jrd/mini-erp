<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\SupplierPaymentManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierPaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_supplier_payment_create_permission_is_enforced(): void
    {
        $fixture = $this->validatedSupplierInvoice();

        $this->actingAs(
            $this->createUserWithPermissions(['payments.view'])
        );

        try {
            app(SupplierPaymentManagementService::class)->create(
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

            $this->fail('Supplier payment creation must require payments.create.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('supplier_payments', 0);
        }
    }

    public function test_partial_and_full_supplier_payments_compute_remaining_amount(): void
    {
        $this->actingAs($this->paymentOperator());

        $fixture = $this->validatedSupplierInvoice();

        $service = app(SupplierPaymentManagementService::class);

        $this->assertSame('120.00', $service->remainingAmount($fixture['invoice']));
        $this->assertSame('unpaid', $service->paymentState($fixture['invoice']));

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

        $this->assertSame('40.00', $service->paidAmount($fixture['invoice']));
        $this->assertSame('80.00', $service->remainingAmount($fixture['invoice']));
        $this->assertSame('partially_paid', $service->paymentState($fixture['invoice']));

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

        $this->assertSame('120.00', $service->paidAmount($fixture['invoice']));
        $this->assertSame('0.00', $service->remainingAmount($fixture['invoice']));
        $this->assertSame('paid', $service->paymentState($fixture['invoice']));
    }

    public function test_overpayment_is_rejected_atomically(): void
    {
        $this->actingAs($this->paymentOperator());

        $fixture = $this->validatedSupplierInvoice();

        $this->expectValidationException(
            fn () => app(SupplierPaymentManagementService::class)->create(
                $this->paymentData(
                    $fixture['supplier'],
                    $fixture['method'],
                    '120.01'
                ),
                [
                    [
                        'supplier_invoice_id' => $fixture['invoice']->id,
                        'amount' => '120.01',
                    ],
                ]
            )
        );

        $this->assertDatabaseCount('supplier_payments', 0);
        $this->assertDatabaseCount('supplier_payment_allocations', 0);
    }

    public function test_draft_supplier_invoice_cannot_receive_payment(): void
    {
        $this->actingAs($this->paymentOperator());

        $fixture = $this->validatedSupplierInvoice(
            SupplierInvoice::STATUS_DRAFT
        );

        $this->expectValidationException(
            fn () => app(SupplierPaymentManagementService::class)->create(
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
            )
        );

        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_supplier_invoice_from_another_supplier_is_rejected(): void
    {
        $this->actingAs($this->paymentOperator());

        $fixture = $this->validatedSupplierInvoice();

        $otherSupplier = $this->createSupplier('SUP-OTHER');

        $this->expectValidationException(
            fn () => app(SupplierPaymentManagementService::class)->create(
                $this->paymentData(
                    $otherSupplier,
                    $fixture['method'],
                    '50.00'
                ),
                [
                    [
                        'supplier_invoice_id' => $fixture['invoice']->id,
                        'amount' => '50.00',
                    ],
                ]
            )
        );

        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_duplicate_supplier_invoice_allocation_is_rejected(): void
    {
        $this->actingAs($this->paymentOperator());

        $fixture = $this->validatedSupplierInvoice();

        $this->expectValidationException(
            fn () => app(SupplierPaymentManagementService::class)->create(
                $this->paymentData(
                    $fixture['supplier'],
                    $fixture['method'],
                    '100.00'
                ),
                [
                    [
                        'supplier_invoice_id' => $fixture['invoice']->id,
                        'amount' => '50.00',
                    ],
                    [
                        'supplier_invoice_id' => $fixture['invoice']->id,
                        'amount' => '50.00',
                    ],
                ]
            )
        );

        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_inactive_payment_method_is_rejected(): void
    {
        $this->actingAs($this->paymentOperator());

        $fixture = $this->validatedSupplierInvoice();

        $fixture['method']->update([
            'is_active' => false,
        ]);

        $this->expectValidationException(
            fn () => app(SupplierPaymentManagementService::class)->create(
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
            )
        );

        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_multi_invoice_supplier_payment_is_supported(): void
    {
        $this->actingAs($this->paymentOperator());

        $first = $this->validatedSupplierInvoice();

        $second = $this->validatedSupplierInvoice(
            SupplierInvoice::STATUS_VALIDATED,
            $first['supplier']
        );

        $service = app(SupplierPaymentManagementService::class);

        $service->create(
            $this->paymentData(
                $first['supplier'],
                $first['method'],
                '100.00'
            ),
            [
                [
                    'supplier_invoice_id' => $first['invoice']->id,
                    'amount' => '60.00',
                ],
                [
                    'supplier_invoice_id' => $second['invoice']->id,
                    'amount' => '40.00',
                ],
            ]
        );

        $this->assertSame('60.00', $service->paidAmount($first['invoice']));
        $this->assertSame('60.00', $service->remainingAmount($first['invoice']));

        $this->assertSame('40.00', $service->paidAmount($second['invoice']));
        $this->assertSame('80.00', $service->remainingAmount($second['invoice']));
    }

    public function test_fully_paid_supplier_invoice_rejects_new_payment(): void
    {
        $this->actingAs($this->paymentOperator());

        $fixture = $this->validatedSupplierInvoice();

        $service = app(SupplierPaymentManagementService::class);

        $service->create(
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

        $this->assertSame('paid', $service->paymentState($fixture['invoice']));

        $this->expectValidationException(
            fn () => $service->create(
                $this->paymentData(
                    $fixture['supplier'],
                    $fixture['method'],
                    '0.01'
                ),
                [
                    [
                        'supplier_invoice_id' => $fixture['invoice']->id,
                        'amount' => '0.01',
                    ],
                ]
            )
        );
    }

    private function validatedSupplierInvoice(
        string $status = SupplierInvoice::STATUS_VALIDATED,
        ?Supplier $supplier = null
    ): array {
        $supplier ??= $this->createSupplier(
            'SUP-'.str()->upper(str()->random(8))
        );

        $order = PurchaseOrder::query()->forceCreate([
            'number' => 'BCF-'.str()->upper(str()->random(8)),
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrder::STATUS_CONFIRMED,
            'order_date' => today(),
            'supplier_name' => $supplier->name,
            'subtotal_ht' => '100.00',
            'discount_total' => '0.00',
            'tax_total' => '20.00',
            'total_ttc' => '120.00',
        ]);

        $invoice = SupplierInvoice::query()->forceCreate([
            'number' => $status === SupplierInvoice::STATUS_VALIDATED
                ? 'FAF-'.str()->upper(str()->random(8))
                : null,

            'supplier_invoice_number' => 'EXT-'.str()->upper(str()->random(8)),

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

            'validated_at' => $status === SupplierInvoice::STATUS_VALIDATED
                    ? now()
                    : null,
        ]);

        $method = PaymentMethod::query()->create([
            'name' => 'Virement test '.str()->random(6),
            'payment_type' => 'bank_transfer',
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

    private function createSupplier(string $code): Supplier
    {
        return Supplier::query()->forceCreate([
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

    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        $role = Role::query()->create([
            'name' => 'Supplier payment test '.$user->id,
            'slug' => 'supplier-payment-test-'.$user->id,
        ]);

        $role->permissions()->attach(
            Permission::query()
                ->whereIn('name', $permissions)
                ->pluck('id')
        );

        $user->roles()->attach($role);

        return $user;
    }

    private function expectValidationException(callable $callback): void
    {
        try {
            $callback();

            $this->fail(
                'Expected validation to reject the operation.'
            );
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
