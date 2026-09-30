<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\PaymentTerm;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\CreditNoteManagementService;
use App\Services\DeliveryNoteManagementService;
use App\Services\InvoiceManagementService;
use App\Services\SalesOrderManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class CreditNoteManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_credit_note_permissions_are_enforced(): void
    {
        $this->actingAs($this->creditOperator());

        $flow = $this->createFlow([
            ['quantity' => '2.000'],
        ]);

        $invoice = $this->createIssuedInvoice(
            $flow['order']
        );

        $service = app(
            CreditNoteManagementService::class
        );

        $this->actingAs(
            $this->createUserWithPermissions([])
        );

        try {
            $service->createForInvoice($invoice);

            $this->fail(
                'Credit note creation must require invoices.create.'
            );
        } catch (AuthorizationException) {
            $this->assertSame(
                0,
                CreditNote::query()->count()
            );
        }

        $this->actingAs(
            $this->createUserWithPermissions([
                'invoices.create',
            ])
        );

        $creditNote = $service->createForInvoice(
            $invoice,
            [
                'items' => [
                    $this->creditLine(
                        $invoice->items->first()->id,
                        '1.000'
                    ),
                ],
            ]
        );

        $this->createSequence(
            'credit_note',
            'AV'
        );

        try {
            $service->issue($creditNote);

            $this->fail(
                'Credit note issuance must require invoices.validate.'
            );
        } catch (AuthorizationException) {
            $this->assertSame(
                CreditNote::STATUS_DRAFT,
                $creditNote->fresh()->status
            );
        }

        $this->actingAs(
            $this->createUserWithPermissions([
                'invoices.validate',
            ])
        );

        $issued = $service->issue($creditNote);

        $this->assertSame(
            CreditNote::STATUS_ISSUED,
            $issued->status
        );

        $this->assertSame(
            'AV-'.today()->year.'-00001',
            $issued->number
        );
    }

    public function test_only_issued_invoice_can_receive_credit_note(): void
    {
        $this->actingAs($this->creditOperator());

        $flow = $this->createFlow([
            ['quantity' => '1.000'],
        ]);

        $invoice = app(
            InvoiceManagementService::class
        )->createForOrder($flow['order']);

        try {
            app(
                CreditNoteManagementService::class
            )->createForInvoice($invoice);

            $this->fail(
                'Draft invoice must not receive a credit note.'
            );
        } catch (ValidationException) {
            $this->assertSame(
                0,
                CreditNote::query()->count()
            );
        }
    }

    public function test_credit_note_uses_invoice_snapshots_and_exact_calculation(): void
    {
        $this->actingAs($this->creditOperator());

        $flow = $this->createFlow([
            [
                'quantity' => '2.000',
                'unit_price' => '100.00',
                'discount_percent' => '10.00',
                'tax_rate_percent' => '20.00',
            ],
        ]);

        $invoice = $this->createIssuedInvoice(
            $flow['order']
        );

        $invoiceItem = $invoice->items->first();

        $flow['customer']
            ->forceFill([
                'name' => 'Client modifié',
            ])
            ->save();

        $creditNote = app(
            CreditNoteManagementService::class
        )->createForInvoice(
            $invoice,
            [
                'reason' => 'Retour partiel',
                'items' => [
                    $this->creditLine(
                        $invoiceItem->id,
                        '0.500'
                    ),
                ],
            ]
        );

        $line = $creditNote->items->first();

        $this->assertNull($creditNote->number);

        $this->assertSame(
            CreditNote::STATUS_DRAFT,
            $creditNote->status
        );

        $this->assertSame(
            today()->toDateString(),
            $creditNote->credit_date->toDateString()
        );

        $this->assertSame(
            'Client test',
            $creditNote->customer_name
        );

        $this->assertSame(
            'Retour partiel',
            $creditNote->reason
        );

        $this->assertSame(
            '0.500',
            $line->quantity
        );

        $this->assertSame(
            '100.00',
            $line->unit_price
        );

        $this->assertSame(
            '10.00',
            $line->discount_percent
        );

        $this->assertSame(
            '20.00',
            $line->tax_rate_percent
        );

        $this->assertSame(
            '5.00',
            $line->discount_amount
        );

        $this->assertSame(
            '45.00',
            $line->subtotal_ht
        );

        $this->assertSame(
            '9.00',
            $line->tax_amount
        );

        $this->assertSame(
            '54.00',
            $line->total_ttc
        );

        $this->assertSame(
            '50.00',
            $creditNote->subtotal_ht
        );

        $this->assertSame(
            '5.00',
            $creditNote->discount_total
        );

        $this->assertSame(
            '9.00',
            $creditNote->tax_total
        );

        $this->assertSame(
            '54.00',
            $creditNote->total_ttc
        );
    }

    public function test_draft_reserves_quantity_and_cancel_releases_it(): void
    {
        $this->actingAs($this->creditOperator());

        $flow = $this->createFlow([
            ['quantity' => '2.000'],
        ]);

        $invoice = $this->createIssuedInvoice(
            $flow['order']
        );

        $service = app(
            CreditNoteManagementService::class
        );

        $invoiceItem = $invoice->items->first();

        $draft = $service->createForInvoice(
            $invoice,
            [
                'items' => [
                    $this->creditLine(
                        $invoiceItem->id,
                        '1.250'
                    ),
                ],
            ]
        );

        $available = $service
            ->availableItemsForInvoice($invoice);

        $this->assertCount(1, $available);

        $this->assertSame(
            '1.250',
            $available[0][
                'already_credited_quantity'
            ]
        );

        $this->assertSame(
            '0.750',
            $available[0]['remaining_quantity']
        );

        $this->expectValidationException(
            fn () => $service->createForInvoice(
                $invoice,
                [
                    'items' => [
                        $this->creditLine(
                            $invoiceItem->id,
                            '0.751'
                        ),
                    ],
                ]
            )
        );

        $cancelled = $service->cancelDraft(
            $draft
        );

        $this->assertSame(
            CreditNote::STATUS_CANCELLED,
            $cancelled->status
        );

        $availableAfterCancellation =
            $service->availableItemsForInvoice(
                $invoice
            );

        $this->assertSame(
            '0.000',
            $availableAfterCancellation[0][
                'already_credited_quantity'
            ]
        );

        $this->assertSame(
            '2.000',
            $availableAfterCancellation[0][
                'remaining_quantity'
            ]
        );
    }

    public function test_multiple_credit_notes_cannot_exceed_invoice_quantity(): void
    {
        $this->actingAs($this->creditOperator());

        $flow = $this->createFlow([
            ['quantity' => '2.000'],
        ]);

        $invoice = $this->createIssuedInvoice(
            $flow['order']
        );

        $this->createSequence(
            'credit_note',
            'AV'
        );

        $service = app(
            CreditNoteManagementService::class
        );

        $invoiceItem = $invoice->items->first();

        $first = $service->createForInvoice(
            $invoice,
            [
                'items' => [
                    $this->creditLine(
                        $invoiceItem->id,
                        '1.000'
                    ),
                ],
            ]
        );

        $second = $service->createForInvoice(
            $invoice,
            [
                'items' => [
                    $this->creditLine(
                        $invoiceItem->id,
                        '1.000'
                    ),
                ],
            ]
        );

        $first = $service->issue($first);
        $second = $service->issue($second);

        $this->assertSame(
            'AV-'.today()->year.'-00001',
            $first->number
        );

        $this->assertSame(
            'AV-'.today()->year.'-00002',
            $second->number
        );

        $this->expectValidationException(
            fn () => $service->createForInvoice(
                $invoice,
                [
                    'items' => [
                        $this->creditLine(
                            $invoiceItem->id,
                            '0.001'
                        ),
                    ],
                ]
            )
        );

        $this->assertSame(
            2,
            CreditNote::query()
                ->where(
                    'status',
                    CreditNote::STATUS_ISSUED
                )
                ->count()
        );
    }

    public function test_update_draft_preserves_unsent_reason_and_notes(): void
    {
        $this->actingAs($this->creditOperator());

        $flow = $this->createFlow([
            ['quantity' => '2.000'],
        ]);

        $invoice = $this->createIssuedInvoice(
            $flow['order']
        );

        $service = app(
            CreditNoteManagementService::class
        );

        $invoiceItem = $invoice->items->first();

        $draft = $service->createForInvoice(
            $invoice,
            [
                'reason' => 'Retour client',
                'notes' => 'Emballage endommagé',
                'items' => [
                    $this->creditLine(
                        $invoiceItem->id,
                        '1.000'
                    ),
                ],
            ]
        );

        $updated = $service->updateDraft(
            $draft,
            [
                'items' => [
                    $this->creditLine(
                        $invoiceItem->id,
                        '0.500'
                    ),
                ],
            ]
        );

        $this->assertSame(
            'Retour client',
            $updated->reason
        );

        $this->assertSame(
            'Emballage endommagé',
            $updated->notes
        );

        $this->assertSame(
            '0.500',
            $updated->items->first()->quantity
        );
    }

    public function test_credit_note_issue_is_numbered_once_and_has_no_stock_effect(): void
    {
        $this->actingAs($this->creditOperator());

        $flow = $this->createFlow([
            ['quantity' => '2.000'],
        ]);

        $invoice = $this->createIssuedInvoice(
            $flow['order']
        );

        $this->createSequence(
            'credit_note',
            'AV'
        );

        $stockBefore =
            $flow['stock']->fresh()->quantity;

        $movementCountBefore =
            StockMovement::query()->count();

        $deliveredBefore =
            $flow['order']
                ->fresh()
                ->items
                ->first()
                ->delivered_quantity;

        $service = app(
            CreditNoteManagementService::class
        );

        $draft = $service->createForInvoice(
            $invoice,
            [
                'items' => [
                    $this->creditLine(
                        $invoice->items->first()->id,
                        '0.500'
                    ),
                ],
            ]
        );

        $issued = $service->issue($draft);

        $this->assertSame(
            CreditNote::STATUS_ISSUED,
            $issued->status
        );

        $this->assertSame(
            'AV-'.today()->year.'-00001',
            $issued->number
        );

        $this->assertNotNull(
            $issued->issued_at
        );

        $this->assertSame(
            auth()->id(),
            $issued->issued_by
        );

        $sequence = DocumentSequence::query()
            ->where(
                'document_type',
                'credit_note'
            )
            ->where('year', today()->year)
            ->firstOrFail();

        $this->assertSame(
            1,
            $sequence->counter
        );

        $this->expectValidationException(
            fn () => $service->issue($issued)
        );

        $this->expectValidationException(
            fn () => $service->cancelDraft($issued)
        );

        $this->expectValidationException(
            fn () => $service->updateDraft(
                $issued,
                [
                    'notes' => 'Modification interdite',
                ]
            )
        );

        $this->assertSame(
            $stockBefore,
            $flow['stock']->fresh()->quantity
        );

        $this->assertSame(
            $movementCountBefore,
            StockMovement::query()->count()
        );

        $this->assertSame(
            $deliveredBefore,
            $flow['order']
                ->fresh()
                ->items
                ->first()
                ->delivered_quantity
        );
    }

    public function test_issued_credit_note_cannot_be_modified_directly(): void
    {
        $this->actingAs($this->creditOperator());

        $flow = $this->createFlow([
            ['quantity' => '1.000'],
        ]);

        $invoice = $this->createIssuedInvoice(
            $flow['order']
        );

        $this->createSequence(
            'credit_note',
            'AV'
        );

        $service = app(
            CreditNoteManagementService::class
        );

        $creditNote = $service->createForInvoice(
            $invoice
        );

        $issued = $service->issue(
            $creditNote
        );

        try {
            $issued->forceFill([
                'status' => CreditNote::STATUS_CANCELLED,

                'cancelled_at' => now(),
            ])->save();

            $this->fail(
                'An issued credit note must be immutable.'
            );
        } catch (LogicException) {
            $issued->refresh();

            $this->assertSame(
                CreditNote::STATUS_ISSUED,
                $issued->status
            );

            $this->assertNull(
                $issued->cancelled_at
            );
        }
    }

    public function test_issued_credit_note_pdf_returns_a_real_pdf(): void
    {
        $this->actingAs($this->creditOperator());

        $flow = $this->createFlow([
            ['quantity' => '1.000'],
        ]);

        $invoice = $this->createIssuedInvoice(
            $flow['order']
        );

        $this->createSequence(
            'credit_note',
            'AV'
        );

        $service = app(
            CreditNoteManagementService::class
        );

        $creditNote = $service->createForInvoice(
            $invoice
        );

        $creditNote = $service->issue(
            $creditNote
        );

        $response = $this->get(
            route(
                'sales.credit-notes.pdf',
                $creditNote
            )
        );

        $response->assertOk();

        $response->assertHeader(
            'content-type',
            'application/pdf'
        );

        $this->assertStringStartsWith(
            '%PDF',
            $response->getContent()
        );
    }

    private function createIssuedInvoice(
        SalesOrder $order
    ): Invoice {
        $this->createSequence(
            'invoice',
            'FAC'
        );

        $service = app(
            InvoiceManagementService::class
        );

        $invoice = $service->createForOrder(
            $order
        );

        return $service->issue($invoice);
    }

    private function createFlow(
        array $lineSpecs,
        ?array $deliveredQuantities = null
    ): array {
        $company = Company::query()->create([
            'legal_name' => 'Société test',
            'trade_name' => 'Société commerciale',
            'address' => 'Adresse société test',
            'ice' => 'ICE-123',
            'tax_id' => 'IF-123',
            'commercial_register' => 'RC-123',
            'phone' => '0600000000',
            'email' => 'compta@example.test',
            'singleton' => true,
        ]);

        $paymentTerm =
            PaymentTerm::query()->create([
                'label' => 'Net 30',
                'due_days' => 30,
                'is_active' => true,
            ]);

        $customer =
            Customer::query()->forceCreate([
                'code' => 'CLI-'.str()
                    ->upper(
                        str()->random(8)
                    ),

                'customer_type' => 'company',
                'name' => 'Client test',
                'trade_name' => 'Client commercial',
                'address' => 'Adresse client',
                'city' => 'Rabat',
                'country' => 'Maroc',
                'email' => 'client@example.test',
                'phone' => '0611111111',
                'ice' => 'CLIENT-ICE',
                'tax_id' => 'CLIENT-IF',
                'commercial_register' => 'CLIENT-RC',

                'payment_term_id' => $paymentTerm->id,

                'status' => 'active',
            ]);

        $taxRate = TaxRate::query()->create([
            'label' => 'TVA test',
            'rate' => $lineSpecs[0][
                    'tax_rate_percent'
                ] ?? '20.00',

            'is_active' => true,
        ]);

        $unit = Unit::query()->firstOrCreate(
            ['symbol' => 'pce'],
            ['name' => 'Pièce']
        );

        $products = [];
        $orderLines = [];

        foreach (
            $lineSpecs as $spec
        ) {
            $product =
                Product::query()->forceCreate([
                    'type' => 'product',

                    'reference' => 'CRN-'.str()
                        ->upper(
                            str()->random(8)
                        ),

                    'name' => 'Article avoir test',

                    'unit_id' => $unit->id,

                    'selling_price' => $spec['unit_price']
                            ?? '100.00',

                    'purchase_price' => '0.00',

                    'tax_rate_id' => $taxRate->id,

                    'is_active' => true,
                ]);

            $products[] = $product;

            $orderLines[] = [
                'product_id' => $product->id,

                'ordered_quantity' => $spec['quantity'],

                'unit_price' => $spec['unit_price']
                        ?? '100.00',

                'discount_percent' => $spec['discount_percent']
                        ?? '0.00',

                'tax_rate_id' => (string) $taxRate->id,
            ];
        }

        $this->createSequence(
            'order',
            'CMD'
        );

        $this->createSequence(
            'delivery_note',
            'BL'
        );

        $order = app(
            SalesOrderManagementService::class
        )->create(
            [
                'customer_id' => $customer->id,

                'order_date' => today()->toDateString(),

                'terms' => 'Conditions commande',

                'notes' => 'Note commande',
            ],
            $orderLines
        );

        $order = app(
            SalesOrderManagementService::class
        )->transition(
            $order,
            SalesOrder::STATUS_CONFIRMED
        );

        $warehouse =
            Warehouse::query()->create([
                'code' => 'WH-CRN',

                'name' => 'Dépôt test avoir',

                'is_active' => true,
            ]);

        $stock = null;

        foreach ($products as $product) {
            $stock =
                WarehouseStock::query()
                    ->create([
                        'warehouse_id' => $warehouse->id,

                        'product_id' => $product->id,

                        'quantity' => '100.000',
                    ]);
        }

        $delivery = app(
            DeliveryNoteManagementService::class
        )->createForOrder(
            $order,
            [
                'warehouse_id' => $warehouse->id,

                'delivery_date' => today()->toDateString(),
            ]
        );

        if ($deliveredQuantities !== null) {
            $deliveryItems = [];

            foreach (
                $order->items as $index => $orderItem
            ) {
                $quantity =
                    $deliveredQuantities[
                        $index
                    ] ?? '0.000';

                if ((float) $quantity > 0) {
                    $deliveryItems[] = [
                        'sales_order_item_id' => $orderItem->id,

                        'quantity' => $quantity,
                    ];
                }
            }

            $delivery = app(
                DeliveryNoteManagementService::class
            )->updateDraft(
                $delivery,
                [
                    'warehouse_id' => $warehouse->id,

                    'delivery_date' => today()
                        ->toDateString(),

                    'items' => $deliveryItems,
                ]
            );
        }

        $delivery = app(
            DeliveryNoteManagementService::class
        )->validate($delivery);

        $order->refresh();

        return [
            'order' => $order,

            'validatedDelivery' => $delivery->fresh([
                'items',
            ]),

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

    private function creditLine(
        int $invoiceItemId,
        string $quantity
    ): array {
        return [
            'invoice_item_id' => $invoiceItemId,

            'quantity' => $quantity,
        ];
    }

    private function createSequence(
        string $type,
        string $prefix,
        string $format =
            '{prefix}-{year}-{counter:05d}'
    ): DocumentSequence {
        return DocumentSequence::query()
            ->create([
                'document_type' => $type,
                'prefix' => $prefix,
                'year' => today()->year,
                'counter' => 0,
                'number_format' => $format,
            ]);
    }

    private function creditOperator(): User
    {
        return $this->createUserWithPermissions([
            'sales.create',
            'sales.update',
            'invoices.view',
            'invoices.create',
            'invoices.validate',
        ]);
    }

    private function createUserWithPermissions(
        array $permissions
    ): User {
        $user = User::factory()->create();

        $role = Role::query()->create([
            'name' => 'Credit note test '.$user->id,

            'slug' => 'credit-note-test-'.$user->id,
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

    private function expectValidationException(
        callable $callback
    ): void {
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
