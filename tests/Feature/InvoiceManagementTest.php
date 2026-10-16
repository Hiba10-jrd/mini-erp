<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentMethod;
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
use App\Services\DocumentSequenceManagementService;
use App\Services\InvoiceManagementService;
use App\Services\PaymentManagementService;
use App\Services\SalesOrderManagementService;
use Brick\Math\BigDecimal;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InvoiceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_invoice_permissions_and_super_administrator_are_enforced(): void
    {
        $this->actingAs($this->createUserWithPermissions(['sales.create', 'sales.update', 'invoices.create', 'invoices.validate']));
        $flow = $this->createFlow([['quantity' => '3.000']]);
        $this->createSequence('invoice', 'FAC');
        $service = app(InvoiceManagementService::class);

        $this->actingAs($this->createUserWithPermissions(['sales.create']));
        try {
            $service->createForOrder($flow['order']);
            $this->fail('Invoice creation must require invoices.create.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Invoice::query()->count());
        }

        $this->actingAs($this->createUserWithPermissions(['invoices.create']));
        $sourceId = $flow['validatedDelivery']->items->first()->id;
        $invoice = $service->createForOrder($flow['order'], ['items' => [$this->invoiceLine($sourceId, '1.000')]]);
        try {
            $service->issue($invoice);
            $this->fail('Invoice issuance must require invoices.validate.');
        } catch (AuthorizationException) {
            $this->assertSame(Invoice::STATUS_DRAFT, $invoice->fresh()->status);
        }

        $this->actingAs($this->createUserWithPermissions(['invoices.validate']));
        $issued = $service->issue($invoice);
        $this->assertSame(Invoice::STATUS_ISSUED, $issued->status);
        $this->assertNotNull($issued->number);

        $this->actingAs($this->createSuperAdministrator());
        $superAdminInvoice = $service->createForOrder($flow['order']);
        $this->assertSame('2.000', $superAdminInvoice->items->first()->quantity);
        $this->assertSame(Invoice::STATUS_ISSUED, $service->issue($superAdminInvoice)->status);
    }

    public function test_only_validated_delivery_note_quantities_can_be_invoiced(): void
    {
        $this->actingAs($this->invoiceOperator());
        $flow = $this->createFlow([['quantity' => '5.000']], ['2.000']);
        $service = app(InvoiceManagementService::class);

        $draftDelivery = app(DeliveryNoteManagementService::class)->createForOrder($flow['order']->fresh(), [
            'warehouse_id' => $flow['warehouse']->id,
            'delivery_date' => today()->toDateString(),
        ]);
        $this->assertSame('draft', $draftDelivery->status);

        $invoice = $service->createForOrder($flow['order']);
        $this->assertSame(1, $invoice->items->count());
        $this->assertSame('2.000', $invoice->items->first()->quantity);
        $this->assertSame($flow['validatedDelivery']->items->first()->id, $invoice->items->first()->delivery_note_item_id);

        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(SalesOrder::STATUS_PARTIALLY_DELIVERED, $flow['order']->fresh()->status);
    }

    public function test_order_without_validated_delivery_is_rejected_even_if_a_draft_delivery_exists(): void
    {
        $this->actingAs($this->invoiceOperator());
        $flow = $this->createFlow([['quantity' => '2.000']], validateDelivery: false);

        try {
            app(InvoiceManagementService::class)->createForOrder($flow['order']);
            $this->fail('An order with only a draft delivery note cannot be invoiced.');
        } catch (ValidationException) {
            $this->assertSame(0, Invoice::query()->count());
        }
    }

    public function test_draft_reserves_quantity_cancelled_releases_it_and_issued_counts_toward_limit(): void
    {
        $this->actingAs($this->invoiceOperator());
        $flow = $this->createFlow([['quantity' => '2.000']]);
        $this->createSequence('invoice', 'FAC');
        $service = app(InvoiceManagementService::class);
        $sourceId = $flow['validatedDelivery']->items->first()->id;

        $reservedDraft = $service->createForOrder($flow['order'], ['items' => [$this->invoiceLine($sourceId, '1.000')]]);
        $this->assertNull($reservedDraft->number);
        $this->expectValidationException(fn () => $service->createForOrder($flow['order'], ['items' => [$this->invoiceLine($sourceId, '1.001')]]));

        $cancelled = $service->cancelDraft($reservedDraft);
        $this->assertSame(Invoice::STATUS_CANCELLED, $cancelled->status);
        $firstPartial = $service->createForOrder($flow['order'], ['items' => [$this->invoiceLine($sourceId, '1.000')]]);
        $secondPartial = $service->createForOrder($flow['order'], ['items' => [$this->invoiceLine($sourceId, '1.000')]]);
        $this->assertNotSame($firstPartial->id, $secondPartial->id);
        $this->assertSame(3, Invoice::query()->where('sales_order_id', $flow['order']->id)->count());
        $firstIssued = $service->issue($firstPartial);
        $issued = $service->issue($secondPartial);

        $this->assertSame(Invoice::STATUS_ISSUED, $firstIssued->status);
        $this->assertSame(Invoice::STATUS_ISSUED, $issued->status);
        $this->expectValidationException(fn () => $service->createForOrder($flow['order'], ['items' => [$this->invoiceLine($sourceId, '0.001')]]));
    }

    public function test_excess_quantity_is_rejected_and_draft_update_rechecks_remaining(): void
    {
        $this->actingAs($this->invoiceOperator());
        $flow = $this->createFlow([['quantity' => '2.000']]);
        $service = app(InvoiceManagementService::class);
        $sourceId = $flow['validatedDelivery']->items->first()->id;

        $this->expectValidationException(fn () => $service->createForOrder($flow['order'], ['items' => [$this->invoiceLine($sourceId, '2.001')]]));
        $draft = $service->createForOrder($flow['order'], ['items' => [$this->invoiceLine($sourceId, '1.000')]]);
        $service->createForOrder($flow['order'], ['items' => [$this->invoiceLine($sourceId, '1.000')]]);
        $this->expectValidationException(fn () => $service->updateDraft($draft, ['items' => [$this->invoiceLine($sourceId, '2.000')]]));

        $this->assertSame('1.000', $draft->items()->firstOrFail()->fresh()->quantity);
        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_invoice_uses_order_snapshots_payment_term_and_half_up_calculation(): void
    {
        $this->actingAs($this->invoiceOperator());
        $flow = $this->createFlow([[
            'quantity' => '1.000',
            'unit_price' => '0.05',
            'discount_percent' => '10.00',
            'tax_rate_percent' => '20.00',
        ]]);
        $invoice = app(InvoiceManagementService::class)->createForOrder($flow['order'], [
            'invoice_date' => '2026-09-29',
        ]);
        $line = $invoice->items->first();

        $this->assertNull($invoice->number);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('2026-10-29', $invoice->due_date->toDateString());
        $this->assertSame('Net 30', $invoice->payment_term_label);
        $this->assertSame(30, $invoice->payment_term_days);
        $this->assertSame('0.05', $line->unit_price);
        $this->assertSame('10.00', $line->discount_percent);
        $this->assertSame('20.00', $line->tax_rate_percent);
        $this->assertSame('0.01', $line->discount_amount);
        $this->assertSame('0.04', $line->subtotal_ht);
        $this->assertSame('0.01', $line->tax_amount);
        $this->assertSame('0.05', $line->total_ttc);

        $flow['customer']->forceFill(['name' => 'Client modifié'])->save();
        $flow['company']->forceFill(['legal_name' => 'Société modifiée', 'address' => 'Adresse modifiée'])->save();
        $flow['product']->forceFill(['name' => 'Produit modifié', 'selling_price' => '999.99'])->save();
        $flow['taxRate']->forceFill(['rate' => '5.00'])->save();
        $flow['paymentTerm']->forceFill(['label' => 'Net 90', 'due_days' => 90])->save();

        $invoice = $invoice->fresh(['items']);
        $this->assertSame('Client test', $invoice->customer_name);
        $this->assertSame('Société test', $invoice->company_legal_name);
        $this->assertSame('Adresse société test', $invoice->company_address);
        $this->assertSame('Net 30', $invoice->payment_term_label);
        $this->assertSame(30, $invoice->payment_term_days);
        $this->assertSame('2026-10-29', $invoice->due_date->toDateString());
        $this->assertSame('0.05', $invoice->items->first()->unit_price);
        $this->assertSame('20.00', $invoice->items->first()->tax_rate_percent);
        $this->assertSame('Article snapshot', $invoice->items->first()->description);
    }

    public function test_issue_uses_invoice_sequence_once_and_never_changes_stock_or_delivery_quantities(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['quantity' => '2.000']]);
        $sequence = $this->createSequence('invoice', 'FAC', 'INV/{counter:04d}/{year}');
        $stockBefore = $flow['stock']->fresh()->quantity;
        $movementCount = StockMovement::query()->count();
        $deliveredBefore = $flow['order']->fresh()->items->first()->delivered_quantity;
        $sourceId = $flow['validatedDelivery']->items->first()->id;

        $preview = app(DocumentSequenceManagementService::class)->preview([
            'prefix' => $sequence->prefix,
            'year' => $sequence->year,
            'counter' => $sequence->counter,
            'number_format' => $sequence->number_format,
        ]);
        $this->assertSame('INV/0001/'.today()->year, $preview);
        $this->assertSame(0, $sequence->fresh()->counter);

        $service = app(InvoiceManagementService::class);
        $draft = $service->createForOrder($flow['order'], ['items' => [$this->invoiceLine($sourceId, '1.000')]]);
        $this->assertNull($draft->number);
        $this->assertSame(0, $sequence->fresh()->counter);

        $updated = $service->updateDraft($draft, [
            'invoice_date' => today()->toDateString(),
            'items' => [$this->invoiceLine($sourceId, '0.500')],
        ]);
        $this->assertSame('0.500', $updated->items->first()->quantity);

        DB::table('invoice_items')->where('invoice_id', $updated->id)->update([
            'unit_price' => '0.01',
            'discount_amount' => '0.00',
            'tax_rate_percent' => '0.00',
            'subtotal_ht' => '0.01',
            'tax_amount' => '0.00',
            'total_ttc' => '0.01',
        ]);
        DB::table('invoices')->where('id', $updated->id)->update([
            'subtotal_ht' => '0.01',
            'discount_total' => '0.00',
            'tax_total' => '0.00',
            'total_ttc' => '0.01',
        ]);
        $issued = $service->issue($updated);

        $this->assertSame('INV/0001/'.today()->year, $issued->number);
        $this->assertSame(1, $sequence->fresh()->counter);
        $this->assertSame('100.00', $issued->items->first()->unit_price);
        $this->assertSame('60.00', $issued->total_ttc);
        $this->expectValidationException(fn () => $service->issue($issued));
        $this->expectValidationException(fn () => $service->cancelDraft($issued));
        $this->expectValidationException(fn () => $service->updateDraft($issued, ['notes' => 'Modification interdite']));

        $this->assertSame('0.500', $issued->items()->firstOrFail()->fresh()->quantity);
        $this->assertSame($stockBefore, $flow['stock']->fresh()->quantity);
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($deliveredBefore, $flow['order']->fresh()->items->first()->delivered_quantity);
    }

    public function test_multi_line_issue_is_atomic_when_one_source_is_no_longer_available(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['quantity' => '2.000'], ['quantity' => '2.000']]);
        $sequence = $this->createSequence('invoice', 'FAC');
        $sources = $flow['validatedDelivery']->items;
        $invoice = app(InvoiceManagementService::class)->createForOrder($flow['order'], [
            'items' => [
                $this->invoiceLine($sources[0]->id, '1.000'),
                $this->invoiceLine($sources[1]->id, '1.000'),
            ],
        ]);

        DB::table('delivery_note_items')->where('id', $sources[1]->id)->update(['quantity' => '0.500']);
        $this->expectValidationException(fn () => app(InvoiceManagementService::class)->issue($invoice));

        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->number);
        $this->assertSame(0, $sequence->fresh()->counter);
        $this->assertSame(2, $invoice->items()->count());
    }

    public function test_invoice_draft_cancellation_and_no_payment_term_keep_due_date_null(): void
    {
        $this->actingAs($this->invoiceOperator());
        $flow = $this->createFlow([['quantity' => '1.000']], withPaymentTerm: false);
        $invoice = app(InvoiceManagementService::class)->createForOrder($flow['order']);

        $this->assertNull($invoice->due_date);
        $this->assertNull($invoice->payment_term_label);
        $this->assertNull($invoice->payment_term_days);

        $cancelled = app(InvoiceManagementService::class)->cancelDraft($invoice);
        $this->assertSame(Invoice::STATUS_CANCELLED, $cancelled->status);
        $this->assertNull($cancelled->number);
    }

    public function test_issued_invoice_cannot_be_cancelled_directly_on_model(): void
    {
        $this->actingAs($this->createSuperAdministrator());

        $flow = $this->createFlow([
            ['quantity' => '1.000'],
        ]);

        $this->createSequence('invoice', 'FAC');

        $service = app(InvoiceManagementService::class);

        $invoice = $service->createForOrder($flow['order']);
        $issued = $service->issue($invoice);

        try {
            $issued->forceFill([
                'status' => Invoice::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            $this->fail('Une facture émise ne doit pas pouvoir être annulée directement.');
        } catch (\LogicException) {
            $issued->refresh();

            $this->assertSame(Invoice::STATUS_ISSUED, $issued->status);
            $this->assertNull($issued->cancelled_at);
        }
    }

    public function test_available_items_for_order_reports_remaining_invoiceable_quantities(): void
    {
        $this->actingAs($this->createSuperAdministrator());

        $flow = $this->createFlow([
            ['quantity' => '3.000'],
        ]);

        $service = app(InvoiceManagementService::class);

        $available = $service->availableItemsForOrder($flow['order']);

        $this->assertCount(1, $available);
        $this->assertSame('3.000', $available[0]['delivered_quantity']);
        $this->assertSame('0.000', $available[0]['already_invoiced_quantity']);
        $this->assertSame('3.000', $available[0]['remaining_quantity']);

        $sourceId = $available[0]['delivery_note_item_id'];

        $invoice = $service->createForOrder($flow['order'], [
            'items' => [
                [
                    'delivery_note_item_id' => $sourceId,
                    'quantity' => '1.000',
                ],
            ],
        ]);

        $availableAfterDraft = $service->availableItemsForOrder($flow['order']);

        $this->assertCount(1, $availableAfterDraft);
        $this->assertSame(
            '1.000',
            $availableAfterDraft[0]['already_invoiced_quantity']
        );
        $this->assertSame(
            '2.000',
            $availableAfterDraft[0]['remaining_quantity']
        );

        $availableWhileEditing = $service->availableItemsForOrder(
            $flow['order'],
            $invoice
        );

        $this->assertSame(
            '3.000',
            $availableWhileEditing[0]['remaining_quantity']
        );
    }

    public function test_issued_invoice_pdf_is_protected_and_returns_a_real_pdf(): void
    {
        $this->actingAs(
            $this->createUserWithPermissions([
                'sales.create',
                'sales.update',
                'invoices.view',
                'invoices.create',
                'invoices.validate',
            ])
        );

        $flow = $this->createFlow([
            ['quantity' => '1.000'],
        ]);

        $this->createSequence('invoice', 'FAC');

        $service = app(InvoiceManagementService::class);

        $invoice = $service->createForOrder($flow['order']);
        $invoice = $service->issue($invoice);

        $response = $this->get(
            route('sales.invoices.pdf', $invoice)
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith(
            '%PDF',
            $response->getContent()
        );
    }

    public function test_service_can_be_invoiced_directly_without_delivery_warehouse_or_stock(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['type' => 'service', 'quantity' => '3.000', 'unit_price' => '50.00', 'discount_percent' => '10.00']], createDelivery: false);
        $service = app(InvoiceManagementService::class);
        $this->get(route('sales.orders.show', $flow['order']))->assertOk()->assertSee('Créer une facture')->assertDontSee('Créer un bon de livraison');
        $this->get(route('sales.orders.invoices.create', $flow['order']))->assertOk()->assertSee('Service');
        $available = $service->availableItemsForOrder($flow['order']);
        $this->assertCount(1, $available);
        $this->assertNull($available[0]['delivery_note_item_id']);
        $this->assertSame('3.000', $available[0]['remaining_quantity']);
        $this->createSequence('invoice', 'FAC');
        $draft = $service->createForOrder($flow['order']);
        $flow['product']->update(['selling_price' => '999.00']);
        $issued = $service->issue($draft);
        $this->assertSame('service', $issued->items->first()->item_type);
        $this->assertNull($issued->items->first()->delivery_note_item_id);
        $this->assertSame($flow['order']->items->first()->id, $issued->items->first()->sales_order_item_id);
        $this->assertSame('50.00', $issued->items->first()->unit_price);
        $this->assertSame('150.00', $issued->subtotal_ht);
        $this->assertSame('15.00', $issued->discount_total);
        $this->assertSame('27.00', $issued->tax_total);
        $this->assertSame('162.00', $issued->total_ttc);
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $flow['order']->fresh()->status);
        $this->assertDatabaseCount('delivery_notes', 0);
        $this->assertDatabaseCount('warehouses', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame([], $service->availableItemsForOrder($flow['order']));
        $this->get(route('sales.orders.show', $flow['order']))->assertDontSee('Créer une facture');
        $this->get(route('sales.orders.invoices.create', $flow['order']))->assertStatus(422);
        $this->get(route('sales.invoices.pdf', $issued))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertDatabaseHas('operation_histories', ['subject_type' => 'invoice', 'subject_id' => $issued->id, 'action' => 'updated']);
    }

    public function test_service_partial_reservations_updates_cancellation_and_double_billing(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['type' => 'service', 'quantity' => '3.000']], createDelivery: false);
        $this->createSequence('invoice', 'FAC');
        $service = app(InvoiceManagementService::class);
        $itemId = $flow['order']->items->first()->id;
        $line = fn ($quantity) => ['sales_order_item_id' => $itemId, 'quantity' => $quantity];
        $draft = $service->createForOrder($flow['order'], ['items' => [$line('2.000')]]);
        $this->assertSame('1.000', $service->availableItemsForOrder($flow['order'])[0]['remaining_quantity']);
        $this->assertSame('3.000', $service->availableItemsForOrder($flow['order'], $draft)[0]['remaining_quantity']);
        $this->expectValidationException(fn () => $service->createForOrder($flow['order'], ['items' => [$line('1.001')]]));
        $other = $service->createForOrder($flow['order'], ['items' => [$line('1.000')]]);
        $this->expectValidationException(fn () => $service->updateDraft($draft, ['items' => [$line('2.001')]]));
        $service->cancelDraft($other);
        $draft = $service->updateDraft($draft, ['notes' => 'Header only']);
        $this->assertSame('2.000', $draft->items->first()->quantity);
        $service->issue($draft);
        $last = $service->createForOrder($flow['order']);
        $this->assertSame('1.000', $last->items->first()->quantity);
        $service->issue($last);
        $this->assertSame([], $service->availableItemsForOrder($flow['order']));
        $this->expectValidationException(fn () => $service->createForOrder($flow['order'], ['items' => [$line('0.001')]]));
        $this->assertSame('3.000', number_format(InvoiceItem::query()->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_ISSUED))->sum('quantity'), 3, '.', ''));
    }

    public function test_mixed_order_invoices_only_validated_product_quantity_and_services(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['quantity' => '3.000'], ['type' => 'service', 'quantity' => '1.000']], ['1.000', '0.000']);
        $stockBefore = $flow['stock']->fresh()->quantity;
        $movementsBefore = StockMovement::query()->count();
        $service = app(InvoiceManagementService::class);
        $available = $service->availableItemsForOrder($flow['order']);
        $this->assertCount(2, $available);
        $this->assertSame(['1.000', '1.000'], array_column($available, 'remaining_quantity'));
        $this->assertSame(['product', 'service'], array_column($available, 'item_type'));
        $this->get(route('sales.orders.invoices.create', $flow['order']))->assertOk()->assertSee('Produit')->assertSee('Service');
        $invoice = $service->createForOrder($flow['order']);
        $this->assertCount(2, $invoice->items);
        $this->assertNotNull($invoice->items[0]->delivery_note_item_id);
        $this->assertNull($invoice->items[1]->delivery_note_item_id);
        $this->assertSame('240.00', $invoice->total_ttc);
        $this->assertSame($stockBefore, $flow['stock']->fresh()->quantity);
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame('0.000', $flow['order']->fresh()->items->last()->delivered_quantity);
    }

    public function test_mixed_order_with_unvalidated_product_only_offers_service_and_checks_forged_lines(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['quantity' => '3.000'], ['type' => 'service', 'quantity' => '1.000']], validateDelivery: false);
        $service = app(InvoiceManagementService::class);
        $available = $service->availableItemsForOrder($flow['order']);
        $this->assertCount(1, $available);
        $this->assertSame('service', $available[0]['item_type']);
        $this->expectValidationException(fn () => $service->createForOrder($flow['order'], ['items' => [
            ['sales_order_item_id' => $available[0]['sales_order_item_id'], 'quantity' => '1.000'],
            $this->invoiceLine($flow['draftDelivery']->items->first()->id, '1.000'),
        ]]));
        $this->expectValidationException(fn () => $service->createForOrder($flow['order'], ['items' => [
            ['sales_order_item_id' => $flow['order']->items->first()->id, 'quantity' => '1.000'],
        ]]));
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $invoice = $service->createForOrder($flow['order']);
        $this->assertSame('service', $invoice->items->first()->item_type);
        $this->assertCount(1, $invoice->items);
    }

    public function test_mixed_order_after_service_is_billed_only_offers_delivered_product(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['quantity' => '3.000'], ['type' => 'service', 'quantity' => '1.000']], ['1.000', '0.000']);
        $this->createSequence('invoice', 'FAC');
        $manager = app(InvoiceManagementService::class);
        $serviceInvoice = $manager->createForOrder($flow['order'], ['items' => [['sales_order_item_id' => $flow['order']->items->last()->id, 'quantity' => '1.000']]]);
        $manager->issue($serviceInvoice);
        $available = $manager->availableItemsForOrder($flow['order']);
        $this->assertCount(1, $available);
        $this->assertSame('product', $available[0]['item_type']);
        $this->assertSame('1.000', $available[0]['remaining_quantity']);
        $this->assertNotNull($manager->createForOrder($flow['order'])->items->first()->delivery_note_item_id);
    }

    public function test_service_backend_rejects_foreign_duplicate_and_excess_lines_atomically(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['type' => 'service', 'quantity' => '1.000']], createDelivery: false);
        $manager = app(InvoiceManagementService::class);
        $itemId = $flow['order']->items->first()->id;
        $line = ['sales_order_item_id' => $itemId, 'quantity' => '1.000'];
        $foreign = app(SalesOrderManagementService::class)->create(['customer_id' => $flow['customer']->id, 'order_date' => today()->toDateString()], [['product_id' => $flow['product']->id, 'ordered_quantity' => '1.000', 'unit_price' => '100.00', 'discount_percent' => '0.00']]);
        $foreign = app(SalesOrderManagementService::class)->transition($foreign, SalesOrder::STATUS_CONFIRMED);
        foreach ([[$line, $line], [array_merge($line, ['quantity' => '1.001'])], [['sales_order_item_id' => $foreign->items->first()->id, 'quantity' => '1.000']]] as $lines) {
            $this->expectValidationException(fn () => $manager->createForOrder($flow['order'], ['items' => $lines]));
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('invoice_items', 0);
        }
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_service_livewire_uses_persisted_limits_and_ignores_tampered_prices(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['type' => 'service', 'quantity' => '1.000']], createDelivery: false);
        $key = 'service-'.$flow['order']->items->first()->id;
        $form = Volt::test('admin.invoice-form', ['orderId' => $flow['order']->id]);
        $form->set('lines.'.$key.'.remaining_quantity', '100.000')->set('lines.'.$key.'.quantity', '2.000')->call('save')->assertHasErrors(['items.0.quantity']);
        $this->assertDatabaseCount('invoices', 0);
        $form->set('lines.'.$key.'.quantity', '1.000')->set('lines.'.$key.'.unit_price', '0.01')->call('save')->assertHasNoErrors();
        $this->assertSame('100.00', Invoice::query()->firstOrFail()->items->first()->unit_price);
    }

    public function test_service_issue_rechecks_persisted_quantity_and_rolls_back_numbering(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['type' => 'service', 'quantity' => '1.000']], createDelivery: false);
        $sequence = $this->createSequence('invoice', 'FAC');
        $manager = app(InvoiceManagementService::class);
        $invoice = $manager->createForOrder($flow['order']);
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['quantity' => '2.000']);
        $this->expectValidationException(fn () => $manager->issue($invoice));
        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->number);
        $this->assertSame(0, $sequence->fresh()->counter);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_service_invoice_keeps_receivable_credit_and_payment_mechanisms(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['type' => 'service', 'quantity' => '2.000']], createDelivery: false);
        $this->createSequence('invoice', 'FAC');
        $this->createSequence('credit_note', 'AV');
        $manager = app(InvoiceManagementService::class);
        $draft = $manager->createForOrder($flow['order']);
        $payments = app(PaymentManagementService::class);
        $this->assertSame(0, $payments->reportingQuery()->count());
        $invoice = $manager->issue($draft);
        $this->assertSame('240.00', $payments->remainingAmount($invoice));
        $this->assertSame('240.00', (string) BigDecimal::of((string) $payments->reportingQuery()->firstOrFail()->report_remaining)->toScale(2));
        $credits = app(CreditNoteManagementService::class);
        $credit = $credits->createForInvoice($invoice, ['items' => [['invoice_item_id' => $invoice->items->first()->id, 'quantity' => '1.000']]]);
        $credits->issue($credit);
        $this->assertSame('120.00', $payments->remainingAmount($invoice));
        $method = PaymentMethod::query()->create(['name' => 'Espèces', 'payment_type' => 'cash', 'is_active' => true]);
        $payments->create(['customer_id' => $flow['customer']->id, 'payment_method_id' => $method->id, 'payment_date' => today()->toDateString(), 'amount' => '40.00'], [['invoice_id' => $invoice->id, 'amount' => '40.00']]);
        $this->assertSame('80.00', $payments->remainingAmount($invoice));
        $this->assertSame('partially_paid', $payments->paymentState($invoice));
        $this->assertSame([], $manager->availableItemsForOrder($flow['order']));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_product_delivered_five_and_invoiced_three_has_exactly_two_remaining(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['quantity' => '8.000']], ['5.000']);
        $manager = app(InvoiceManagementService::class);
        $this->createSequence('invoice', 'FAC');
        $invoice = $manager->createForOrder($flow['order'], ['items' => [$this->invoiceLine($flow['validatedDelivery']->items->first()->id, '3.000')]]);
        $manager->issue($invoice);
        $this->assertSame('2.000', $manager->availableItemsForOrder($flow['order'])[0]['remaining_quantity']);
        $this->expectValidationException(fn () => $manager->createForOrder($flow['order'], ['items' => [$this->invoiceLine($flow['validatedDelivery']->items->first()->id, '2.001')]]));
        $this->assertSame('2.000', $manager->createForOrder($flow['order'])->items->first()->quantity);
    }

    public function test_legacy_service_invoice_linked_to_a_delivery_still_reserves_order_quantity(): void
    {
        $this->actingAs($this->createSuperAdministrator());
        $flow = $this->createFlow([['type' => 'service', 'quantity' => '3.000']], createDelivery: false);
        $manager = app(InvoiceManagementService::class);
        $invoice = $manager->createForOrder($flow['order'], ['items' => [['sales_order_item_id' => $flow['order']->items->first()->id, 'quantity' => '2.000']]]);
        // Reproduce a draft from before services were excluded from deliveries.
        $legacyNote = DeliveryNote::query()->create(['number' => 'LEGACY-BL', 'sales_order_id' => $flow['order']->id, 'status' => 'validated', 'delivery_date' => today()]);
        $legacyLine = $legacyNote->items()->create(['sales_order_item_id' => $flow['order']->items->first()->id, 'product_id' => $flow['product']->id, 'item_type' => 'service', 'description' => 'Legacy service', 'quantity' => '3.000', 'position' => 1]);
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['delivery_note_item_id' => $legacyLine->id]);
        $this->createSequence('invoice', 'FAC');
        $this->expectValidationException(fn () => $manager->updateDraft($invoice, ['items' => [
            ['delivery_note_item_id' => $legacyLine->id, 'quantity' => '1.000'],
            ['sales_order_item_id' => $flow['order']->items->first()->id, 'quantity' => '1.000'],
        ]]));
        $invoice = $manager->updateDraft($invoice, ['notes' => 'Preserve legacy trace']);
        $invoice = $manager->issue($invoice);
        $remaining = $manager->availableItemsForOrder($flow['order']);
        $this->assertCount(1, $remaining);
        $this->assertSame('1.000', $remaining[0]['remaining_quantity']);
        $this->assertNull($remaining[0]['delivery_note_item_id']);
        $this->assertSame($legacyLine->id, $invoice->fresh()->items->first()->delivery_note_item_id);
        $this->expectValidationException(fn () => $manager->createForOrder($flow['order'], ['items' => [['sales_order_item_id' => $flow['order']->items->first()->id, 'quantity' => '1.001']]]));
    }

    private function createFlow(array $lineSpecs, ?array $deliveredQuantities = null, bool $validateDelivery = true, bool $withPaymentTerm = true, bool $createDelivery = true): array
    {
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
            'email' => 'client@example.test',
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
                'type' => $spec['type'] ?? 'product',
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

        $warehouse = $createDelivery ? Warehouse::query()->create(['code' => 'WH-INV', 'name' => 'Dépôt facture', 'is_active' => true]) : null;
        $stock = null;
        foreach ($products as $product) {
            if ($warehouse === null || $product->isService()) {
                continue;
            }
            $stock = WarehouseStock::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => '100.000',
            ]);
        }

        $delivery = null;
        if ($createDelivery) {
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

        }

        return [
            'order' => $order,
            'validatedDelivery' => $delivery?->fresh(['items']),
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

    private function invoiceOperator(): User
    {
        return $this->createUserWithPermissions(['sales.create', 'sales.update', 'invoices.create', 'invoices.validate']);
    }

    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'Invoice test '.$user->id, 'slug' => 'invoice-test-'.$user->id]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }

    private function createSuperAdministrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());

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
