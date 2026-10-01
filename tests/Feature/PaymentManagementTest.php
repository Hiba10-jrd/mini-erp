<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\DeliveryNoteManagementService;
use App\Services\InvoiceManagementService;
use App\Services\PaymentManagementService;
use App\Services\SalesOrderManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {

        parent::setUp();

        $this->seed(RbacSeeder::class);

    }

    public function test_payment_create_permission_is_enforced(): void
    {

        $this->actingAs($this->paymentOperator());

        $fixture = $this->issuedInvoice();

        $this->actingAs($this->createUserWithPermissions(['payments.view']));

        try {

            app(PaymentManagementService::class)->create(

                $this->paymentData($fixture['customer'], $fixture['method'], '50.00'),

                [

                    [

                        'invoice_id' => $fixture['invoice']->id,

                        'amount' => '50.00',

                    ],

                ],

            );

            $this->fail('Payment creation must require payments.create.');

        } catch (AuthorizationException) {

            $this->assertDatabaseCount('payments', 0);

        }

    }

    public function test_partial_and_full_payments_compute_remaining_amount(): void
    {

        $this->actingAs($this->paymentOperator());

        $fixture = $this->issuedInvoice();

        $service = app(PaymentManagementService::class);

        $this->assertSame('120.00', $service->remainingAmount($fixture['invoice']));

        $this->assertSame('unpaid', $service->paymentState($fixture['invoice']));

        $service->create(

            $this->paymentData($fixture['customer'], $fixture['method'], '40.00'),

            [

                [

                    'invoice_id' => $fixture['invoice']->id,

                    'amount' => '40.00',

                ],

            ],

        );

        $this->assertSame('40.00', $service->paidAmount($fixture['invoice']));

        $this->assertSame('80.00', $service->remainingAmount($fixture['invoice']));

        $this->assertSame('partially_paid', $service->paymentState($fixture['invoice']));

        $service->create(

            $this->paymentData($fixture['customer'], $fixture['method'], '80.00'),

            [

                [

                    'invoice_id' => $fixture['invoice']->id,

                    'amount' => '80.00',

                ],

            ],

        );

        $this->assertSame('120.00', $service->paidAmount($fixture['invoice']));

        $this->assertSame('0.00', $service->remainingAmount($fixture['invoice']));

        $this->assertSame('paid', $service->paymentState($fixture['invoice']));

    }

    public function test_overpayment_is_rejected_atomically(): void
    {

        $this->actingAs($this->paymentOperator());

        $fixture = $this->issuedInvoice();

        $this->expectValidationException(

            fn () => app(PaymentManagementService::class)->create(

                $this->paymentData($fixture['customer'], $fixture['method'], '120.01'),

                [

                    [

                        'invoice_id' => $fixture['invoice']->id,

                        'amount' => '120.01',

                    ],

                ],

            )

        );

        $this->assertDatabaseCount('payments', 0);

        $this->assertDatabaseCount('payment_allocations', 0);

    }

    public function test_inactive_payment_method_is_rejected(): void
    {

        $this->actingAs($this->paymentOperator());

        $fixture = $this->issuedInvoice();

        $fixture['method']->update(['is_active' => false]);

        $this->expectValidationException(

            fn () => app(PaymentManagementService::class)->create(

                $this->paymentData($fixture['customer'], $fixture['method'], '50.00'),

                [

                    [

                        'invoice_id' => $fixture['invoice']->id,

                        'amount' => '50.00',

                    ],

                ],

            )

        );

        $this->assertDatabaseCount('payments', 0);

    }

    public function test_draft_invoice_cannot_receive_payment(): void
    {

        $this->actingAs($this->paymentOperator());

        $fixture = $this->issuedInvoice(issueInvoice: false);

        $this->expectValidationException(

            fn () => app(PaymentManagementService::class)->create(

                $this->paymentData($fixture['customer'], $fixture['method'], '50.00'),

                [

                    [

                        'invoice_id' => $fixture['invoice']->id,

                        'amount' => '50.00',

                    ],

                ],

            )

        );

    }

    public function test_invoice_from_another_customer_is_rejected(): void
    {

        $this->actingAs($this->paymentOperator());

        $first = $this->issuedInvoice();

        $otherCustomer = Customer::query()->forceCreate([

            'code' => 'CLI-OTHER',

            'customer_type' => 'company',

            'name' => 'Autre client',

            'status' => 'active',

        ]);

        $this->expectValidationException(

            fn () => app(PaymentManagementService::class)->create(

                $this->paymentData($otherCustomer, $first['method'], '50.00'),

                [

                    [

                        'invoice_id' => $first['invoice']->id,

                        'amount' => '50.00',

                    ],

                ],

            )

        );

    }

    public function test_duplicate_invoice_allocation_is_rejected(): void
    {

        $this->actingAs($this->paymentOperator());

        $fixture = $this->issuedInvoice();

        $this->expectValidationException(

            fn () => app(PaymentManagementService::class)->create(

                $this->paymentData($fixture['customer'], $fixture['method'], '100.00'),

                [

                    [

                        'invoice_id' => $fixture['invoice']->id,

                        'amount' => '50.00',

                    ],

                    [

                        'invoice_id' => $fixture['invoice']->id,

                        'amount' => '50.00',

                    ],

                ],

            )

        );

    }

    public function test_issued_credit_note_reduces_invoice_payable_amount(): void
    {

        $this->actingAs($this->paymentOperator());

        $fixture = $this->issuedInvoice();

        CreditNote::query()->forceCreate([

            'invoice_id' => $fixture['invoice']->id,

            'customer_id' => $fixture['customer']->id,

            'status' => CreditNote::STATUS_ISSUED,

            'credit_date' => today(),

            'customer_name' => $fixture['customer']->name,

            'subtotal_ht' => '20.00',

            'discount_total' => '0.00',

            'tax_total' => '4.00',

            'total_ttc' => '24.00',

        ]);

        $service = app(PaymentManagementService::class);

        $this->assertSame('24.00', $service->creditedAmount($fixture['invoice']));

        $this->assertSame('96.00', $service->payableAmount($fixture['invoice']));

        $this->assertSame('96.00', $service->remainingAmount($fixture['invoice']));

        $this->expectValidationException(

            fn () => $service->create(

                $this->paymentData($fixture['customer'], $fixture['method'], '96.01'),

                [

                    [

                        'invoice_id' => $fixture['invoice']->id,

                        'amount' => '96.01',

                    ],

                ],

            )

        );

    }

    public function test_payment_does_not_modify_stock(): void
    {

        $this->actingAs($this->paymentOperator());

        $fixture = $this->issuedInvoice();

        $before = $fixture['stock']->fresh()->quantity;

        app(PaymentManagementService::class)->create(

            $this->paymentData($fixture['customer'], $fixture['method'], '50.00'),

            [

                [

                    'invoice_id' => $fixture['invoice']->id,

                    'amount' => '50.00',

                ],

            ],

        );

        $this->assertSame($before, $fixture['stock']->fresh()->quantity);

    }

    public function test_overdue_state_uses_due_date_before_today(): void
    {

        $this->actingAs($this->paymentOperator());

        $fixture = $this->issuedInvoice();

        // La facture émise est immuable, donc modification directe SQL

        // uniquement pour construire le scénario de test.

        Invoice::query()

            ->whereKey($fixture['invoice']->id)

            ->toBase()

            ->update(['due_date' => today()->subDay()->toDateString()]);

        $invoice = $fixture['invoice']->fresh();

        $this->assertSame(
            'overdue',
            app(PaymentManagementService::class)->paymentState($invoice)
        );
    }

    private function createFlow(
        array $lineSpecs,
        ?array $deliveredQuantities = null,
        bool $validateDelivery = true,
        bool $withPaymentTerm = true
    ): array {

        $company = Company::query()->create([

            'legal_name' => 'Société test',

            'trade_name' => 'Société commerciale',

            'address' => 'Adresse société test',

            'ice' => 'ICE-123',

            'tax_id' => 'IF-123',

            'commercial_register' => 'RC-123',

            'phone' => '0600000000',

            'email' => 'compta\@example.test',

            'singleton' => true,

        ]);

        $paymentTerm = $withPaymentTerm

            ? PaymentTerm::query()->create(['label' => 'Net 30', 'due_days' => 30, 'is_active' => true])

            : null;

        $customer = Customer::query()->forceCreate([

            'code' => 'CLI-'.str()->upper(str()->random(8)),

            'customer_type' => 'company',

            'name' => 'Client test',

            'trade_name' => 'Client commercial',

            'address' => 'Adresse client',

            'city' => 'Rabat',

            'country' => 'Maroc',

            'email' => 'client\@example.test',

            'phone' => '0611111111',

            'ice' => 'CLIENT-ICE',

            'tax_id' => 'CLIENT-IF',

            'commercial_register' => 'CLIENT-RC',

            'payment_term_id' => $paymentTerm?->id,

            'status' => 'active',

        ]);

        $taxRate = TaxRate::query()->create([

            'label' => 'TVA test',

            'rate' => $lineSpecs[0]['tax_rate_percent'] ?? '20.00',

            'is_active' => true,

        ]);

        $unit = Unit::query()->firstOrCreate(['symbol' => 'pce'], ['name' => 'Pièce']);

        $products = [];

        $orderLines = [];

        foreach ($lineSpecs as $position => $spec) {

            $product = Product::query()->forceCreate([

                'type' => 'product',

                'reference' => 'INV-'.str()->upper(str()->random(8)),

                'name' => 'Article snapshot',

                'unit_id' => $unit->id,

                'selling_price' => $spec['unit_price'] ?? '100.00',

                'purchase_price' => '0.00',

                'tax_rate_id' => $taxRate->id,

                'is_active' => true,

            ]);

            $products[] = $product;

            $orderLines[] = [

                'product_id' => $product->id,

                'ordered_quantity' => $spec['quantity'],

                'unit_price' => $spec['unit_price'] ?? '100.00',

                'discount_percent' => $spec['discount_percent'] ?? '0.00',

                'tax_rate_id' => (string) $taxRate->id,

            ];

        }

        $this->createSequence('order', 'CMD');

        $this->createSequence('delivery_note', 'BL');

        $order = app(SalesOrderManagementService::class)->create([

            'customer_id' => $customer->id,

            'order_date' => today()->toDateString(),

            'terms' => 'Conditions commande',

            'notes' => 'Note commande',

        ], $orderLines);

        $order = app(SalesOrderManagementService::class)->transition($order, SalesOrder::STATUS_CONFIRMED);

        $warehouse = Warehouse::query()->create(['code' => 'WH-INV', 'name' => 'Dépôt facture', 'is_active' => true]);

        foreach ($products as $product) {

            $stock = WarehouseStock::query()->create([

                'warehouse_id' => $warehouse->id,

                'product_id' => $product->id,

                'quantity' => '100.000',

            ]);

        }

        $delivery = app(DeliveryNoteManagementService::class)->createForOrder($order, [

            'warehouse_id' => $warehouse->id,

            'delivery_date' => today()->toDateString(),

        ]);

        if ($deliveredQuantities !== null) {

            $deliveryItems = [];

            foreach ($order->items as $index => $orderItem) {

                $quantity = $deliveredQuantities[$index] ?? '0.000';

                if ((float) $quantity > 0) {

                    $deliveryItems[] = ['sales_order_item_id' => $orderItem->id, 'quantity' => $quantity];

                }

            }

            $delivery = app(DeliveryNoteManagementService::class)->updateDraft($delivery, [

                'warehouse_id' => $warehouse->id,

                'delivery_date' => today()->toDateString(),

                'items' => $deliveryItems,

            ]);

        }

        if ($validateDelivery) {

            $delivery = app(DeliveryNoteManagementService::class)->validate($delivery);

            $order->refresh();

        }

        return [

            'order' => $order,

            'validatedDelivery' => $delivery->fresh(['items']),

            'draftDelivery' => $validateDelivery ? null : $delivery,

            'customer' => $customer,

            'company' => $company,

            'paymentTerm' => $paymentTerm,

            'products' => $products,

            'product' => $products[0],

            'taxRate' => $taxRate,

            'warehouse' => $warehouse,

            'stock' => $stock,

        ];

    }

    private function invoiceLine(int $deliveryNoteItemId, string $quantity): array
    {

        return ['delivery_note_item_id' => $deliveryNoteItemId, 'quantity' => $quantity];

    }

    private function createSequence(string $type, string $prefix, string $format = '{prefix}-{year}-{counter:05d}'): DocumentSequence
    {

        return DocumentSequence::query()->create([

            'document_type' => $type,

            'prefix' => $prefix,

            'year' => today()->year,

            'counter' => 0,

            'number_format' => $format,

        ]);

    }

    private function issuedInvoice(bool $issueInvoice = true): array
    {

        $flow = $this->createFlow([

            [

                'quantity' => '1.000',

                'unit_price' => '100.00',

                'discount_percent' => '0.00',

                'tax_rate_percent' => '20.00',

            ],

        ]);

        $this->createSequence('invoice', 'FAC');

        $invoiceService = app(InvoiceManagementService::class);

        $invoice = $invoiceService->createForOrder($flow['order']);

        if ($issueInvoice) {

            $invoice = $invoiceService->issue($invoice);

        }

        $method = PaymentMethod::query()->create([

            'name' => 'Virement test '.str()->random(6),

            'is_active' => true,

            'sort_order' => 0,

        ]);

        return [

            ...$flow,

            'invoice' => $invoice,

            'method' => $method,

        ];

    }

    private function paymentData(

        Customer $customer,

        PaymentMethod $method,

        string $amount

    ): array {

        return [

            'customer_id' => $customer->id,

            'payment_method_id' => $method->id,

            'payment_date' => today()->toDateString(),

            'amount' => $amount,

            'reference' => 'PAY-TEST',

            'notes' => 'Paiement test',

        ];

    }

    private function paymentOperator(): User
    {

        return $this->createUserWithPermissions([

            'sales.create',

            'sales.update',

            'invoices.create',

            'invoices.validate',

            'payments.view',

            'payments.create',

        ]);

    }

    private function createUserWithPermissions(array $permissions): User
    {

        $user = User::factory()->create();

        $role = Role::query()->create([

            'name' => 'Payment test '.$user->id,

            'slug' => 'payment-test-'.$user->id,

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

            $this->fail('Expected validation to reject the operation.');

        } catch (ValidationException) {

            $this->assertTrue(true);

        }

    }
}
