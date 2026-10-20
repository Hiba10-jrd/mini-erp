<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\AttachmentManagementService;
use App\Services\ReportService;
use App\Services\SupplierInvoiceManagementService;
use App\Services\SupplierPaymentManagementService;
use Brick\Math\BigDecimal;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SubmitsSupplierInvoices;
use Tests\Support\SupplierInvoiceSourceFixtures;
use Tests\TestCase;

class SupplierInvoiceReconciliationTest extends TestCase
{
    use RefreshDatabase, SubmitsSupplierInvoices, SupplierInvoiceSourceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Storage::fake('attachments');
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());
        $this->actingAs($user);
    }

    private function draft(): SupplierInvoice
    {
        $order = $this->createOrder([['quantity' => '10.000', 'unit_price' => '100.00', 'tax_rate_percent' => '20.00']]);
        $source = $this->createReceipt($order, '10.000')->items->firstOrFail();

        return app(SupplierInvoiceManagementService::class)->createDraft($order, [
            'supplier_invoice_number' => 'EXT-'.str()->random(8), 'invoice_date' => today()->toDateString(),
            'items' => [['goods_receipt_item_id' => $source->id, 'quantity' => '1.000']],
        ]);
    }

    private function supplierData(SupplierInvoice $invoice, string $net = '100.00'): array
    {
        $data = $this->matchingReconciliation($invoice);
        $data['net_ht'] = $net;
        $data['tax_total'] = (string) BigDecimal::of($net)->multipliedBy('0.2')->toScale(2);
        $data['total_ttc'] = (string) BigDecimal::of($net)->plus($data['tax_total'])->toScale(2);
        $data['breakdown'] = [['kind' => 'purchase', 'label' => 'Achats', 'net_ht' => $net, 'rate' => '20.00', 'tax' => $data['tax_total']]];
        $data['difference_reason'] = $net === '100.00' ? null : 'Prix fournisseur vérifié.';

        return $data;
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected validation failure: '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function amounts(): array
    {
        return [['100.00', '120.00', '0.00'], ['110.00', '132.00', '12.00'], ['90.00', '108.00', '-12.00']];
    }

    #[DataProvider('amounts')]
    public function test_accepted_supplier_amount_is_the_debt_while_erp_is_preserved(string $net, string $ttc, string $difference): void
    {
        $invoice = $this->draft();
        $this->createSequence();
        $service = app(SupplierInvoiceManagementService::class);
        $review = $service->submitForReview($invoice, $this->supplierData($invoice, $net));
        $this->assertSame('0.00', $review->payableAmount());
        $accepted = $service->validate($review);
        $this->assertSame('120.00', $accepted->total_ttc);
        $this->assertSame($ttc, $accepted->payableAmount());
        $this->assertSame($difference, $accepted->histories()->where('event', 'validated')->firstOrFail()->metadata['difference_ttc']);
        $this->assertNotNull($accepted->validated_by);
        $this->assertNotNull($accepted->validated_at);
        $this->invalid(fn () => $service->validate($accepted), 'status');
        $this->assertSame(1, DB::table('document_sequences')->value('counter'));
    }

    public function test_difference_reason_is_required_even_when_ttc_differences_cancel_out(): void
    {
        $invoice = $this->draft();
        $data = $this->supplierData($invoice, '110.00');
        $data['difference_reason'] = null;
        $this->invalid(fn () => app(SupplierInvoiceManagementService::class)->submitForReview($invoice, $data), 'reconciliation');
        $data['net_ht'] = '120.00';
        $data['tax_total'] = '0.00';
        $data['total_ttc'] = '120.00';
        $data['breakdown'] = [['kind' => 'purchase', 'label' => 'Exonéré', 'net_ht' => '120.00', 'rate' => '0', 'tax' => '0', 'tax_reason' => 'Exonération justifiée']];
        $this->invalid(fn () => app(SupplierInvoiceManagementService::class)->submitForReview($invoice, $data), 'reconciliation');
        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertDatabaseCount('supplier_invoice_reconciliations', 0);
    }

    public function test_inconsistent_ttc_tax_or_missing_fiscal_treatment_cannot_be_submitted(): void
    {
        $invoice = $this->draft();
        $service = app(SupplierInvoiceManagementService::class);
        $data = $this->supplierData($invoice);
        $data['total_ttc'] = '999.00';
        $this->invalid(fn () => $service->submitForReview($invoice, $data), 'reconciliation');
        $data = $this->supplierData($invoice);
        $data['breakdown'][0]['tax'] = '21.00';
        $this->invalid(fn () => $service->submitForReview($invoice, $data), 'reconciliation');
        $data = $this->supplierData($invoice);
        $data['breakdown'][0]['rate'] = '0';
        $this->invalid(fn () => $service->submitForReview($invoice, $data), 'reconciliation');
        $this->invalid(fn () => $service->validate($invoice), 'status');
    }

    public function test_transport_discount_multiple_rates_and_documented_rounding_are_reconciled(): void
    {
        $invoice = $this->draft();
        $data = $this->supplierData($invoice);
        $data['breakdown'][] = ['kind' => 'transport', 'label' => 'Transport', 'net_ht' => '10.00', 'rate' => '10', 'tax' => '1.00'];
        $data['breakdown'][] = ['kind' => 'fee', 'label' => 'Frais', 'net_ht' => '2.00', 'rate' => '20', 'tax' => '0.40'];
        $data['breakdown'][] = ['kind' => 'discount', 'label' => 'Remise', 'net_ht' => '5.00', 'rate' => '20', 'tax' => '1.00'];
        $data['net_ht'] = '107.00';
        $data['tax_total'] = '20.40';
        $data['total_ttc'] = '127.40';
        $data['difference_reason'] = 'Transport, frais et remise documentés.';
        $review = app(SupplierInvoiceManagementService::class)->submitForReview($invoice, $data);
        $this->assertSame('127.40', $review->reconciliation->total_ttc);
        $this->assertCount(4, $review->reconciliation->breakdown);

        $other = $this->draft();
        $rounded = $this->supplierData($other);
        $rounded['breakdown'][0]['tax'] = '20.01';
        $rounded['tax_total'] = '20.01';
        $rounded['total_ttc'] = '120.01';
        $this->invalid(fn () => app(SupplierInvoiceManagementService::class)->submitForReview($other, $rounded), 'reconciliation');
        $rounded['breakdown'][0]['rounding_reason'] = 'Arrondi fiscal par ligne';
        $rounded['difference_reason'] = 'Arrondi contrôlé';
        $this->assertSame('120.01', app(SupplierInvoiceManagementService::class)->submitForReview($other, $rounded)->reconciliation->total_ttc);
    }

    public function test_dispute_reserves_quantities_blocks_payment_and_correction_keeps_all_documents(): void
    {
        $invoice = $this->draft();
        $service = app(SupplierInvoiceManagementService::class);
        $review = $service->submitForReview($invoice, $this->supplierData($invoice));
        $this->invalid(fn () => $service->dispute($review, ' '), 'reason');
        $disputed = $service->dispute($review, 'Prix à corriger');
        $this->assertSame('9.000', $service->availableItemsForOrder($invoice->purchaseOrder)[0]['available_quantity']);
        $this->assertSame('1.000', $service->availableItemsForOrder($invoice->purchaseOrder)[0]['reserved_by_other_drafts']);
        $this->assertSame('0.00', app(SupplierPaymentManagementService::class)->remainingAmount($disputed));
        $this->pay($disputed, '1.00', expectFailure: true);
        $this->get(route('purchases.invoices.payments.create', $disputed))->assertNotFound();
        $old = $review->reconciliation->attachment_id;
        $data = $review->reconciliation->toArray();
        $this->invalid(fn () => $service->submitForReview($disputed, $data), 'reconciliation');
        $corrected = app(AttachmentManagementService::class)->upload($disputed, UploadedFile::fake()->createWithContent('correction.pdf', "%PDF-1.4\n%%EOF"), ['category' => SupplierInvoice::ORIGINAL_DOCUMENT_CATEGORY]);
        $data['attachment_id'] = $corrected->id;
        $newReview = $service->submitForReview($disputed, $data);
        $this->assertSame('under_review', $newReview->status);
        $this->assertSame(2, $newReview->attachments()->count());
        $this->assertNotNull(Attachment::find($old));
        $this->assertSame($old, $newReview->histories()->where('event', 'disputed')->firstOrFail()->metadata['supplier']['attachment_id']);
        try {
            app(AttachmentManagementService::class)->delete(Attachment::findOrFail($old));
            $this->fail('Official history cannot be deleted');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('purchase_orders', ['id' => $invoice->purchase_order_id]);
        $this->assertDatabaseHas('goods_receipts', ['id' => $invoice->items->first()->goodsReceiptItem->goods_receipt_id]);
        $this->createSequence();
        $acceptedCorrection = $service->validate($newReview);
        $this->assertSame($corrected->id, $acceptedCorrection->reconciliation->attachment_id);
        $this->assertSame('120.00', $acceptedCorrection->payableAmount());
    }

    private function pay(SupplierInvoice $invoice, string $amount, bool $expectFailure = false): void
    {
        $method = PaymentMethod::firstOrCreate(['name' => 'Virement test'], ['is_active' => true]);
        $action = fn () => app(SupplierPaymentManagementService::class)->create([
            'supplier_id' => $invoice->supplier_id, 'payment_method_id' => $method->id,
            'payment_date' => today()->toDateString(), 'amount' => $amount,
        ], [['supplier_invoice_id' => $invoice->id, 'amount' => $amount]]);
        if ($expectFailure) {
            $this->invalid($action, 'allocations.0.supplier_invoice_id');
        } else {
            $action();
        }
    }

    public function test_partial_full_payments_overpayment_reports_and_stock_use_the_accepted_debt(): void
    {
        $invoice = $this->draft();
        $this->createSequence();
        $beforeStock = WarehouseStock::all()->toArray();
        $beforeMovements = StockMovement::count();
        $service = app(SupplierInvoiceManagementService::class);
        $accepted = $service->validate($service->submitForReview($invoice, $this->supplierData($invoice, '110.00')));
        $this->pay($accepted, '50.00');
        $payments = app(SupplierPaymentManagementService::class);
        $this->assertSame('82.00', $payments->remainingAmount($accepted));
        $this->assertSame('partially_paid', $payments->paymentState($accepted));
        $this->invalid(fn () => $service->dispute($accepted, 'Ne pas rouvrir une facture payée'), 'status');
        Volt::test('admin.supplier-payment-form', ['supplierInvoiceId' => $accepted->id])->assertSet('amount', '82.00');
        Volt::test('admin.reports-manager')->call('selectSection', 'purchases')->assertSee('132,00 MAD')->assertSee('82,00 MAD');
        $row = $payments->reportingQuery()->findOrFail($invoice->id);
        $this->assertEquals(132, $row->report_payable);
        $this->assertEquals(82, $row->report_remaining);
        $report = app(ReportService::class)->purchaseSummary(today()->toDateString(), today()->toDateString());
        $this->assertSame('110.00', $report['Achats HT']);
        $this->assertSame('22.00', $report['TVA achats']);
        $this->assertSame('132.00', $report['Achats facturés TTC']);
        $this->assertSame('82.00', $report['Fournisseurs à payer actuels']);
        $this->invalid(fn () => $this->pay($accepted, '82.01'), 'allocations.0.amount');
        $this->pay($accepted, '82.00');
        $this->assertSame('paid', $payments->paymentState($accepted));
        $this->assertSame('0.00', $payments->remainingAmount($accepted));
        $this->assertSame('132.00', $payments->paidAmount($accepted));
        $this->assertSame($beforeStock, WarehouseStock::all()->toArray());
        $this->assertSame($beforeMovements, StockMovement::count());
        $this->assertDatabaseCount('supplier_payment_allocations', 2);
    }

    public function test_stale_decisions_and_stale_corrections_are_rejected_without_a_second_history(): void
    {
        $invoice = $this->draft();
        $this->createSequence();
        $service = app(SupplierInvoiceManagementService::class);
        $data = $this->supplierData($invoice);
        $review = $service->submitForReview($invoice, $data);
        $this->invalid(fn () => $service->submitForReview($invoice, $data), 'status');
        $service->validate($review);
        $this->invalid(fn () => $service->dispute($review, 'Décision périmée'), 'status');
        $this->assertSame(1, $invoice->histories()->where('event', 'validated')->count());
        $this->assertSame(0, $invoice->histories()->where('event', 'disputed')->count());
    }

    public function test_validated_financial_values_lines_and_reconciliation_are_immutable(): void
    {
        $invoice = $this->draft();
        $this->createSequence();
        $service = app(SupplierInvoiceManagementService::class);
        $accepted = $service->validate($this->submitMatchingInvoice($invoice));
        foreach ([fn () => $accepted->forceFill(['total_ttc' => '1'])->save(),
            fn () => $accepted->reconciliation->forceFill(['total_ttc' => '1'])->save(),
            fn () => $accepted->items->first()->forceFill(['quantity' => '2'])->save(),
            fn () => $accepted->reconciliation->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Validated data must be immutable');
            } catch (\LogicException) {
            }
        }
        $this->assertSame('120.00', $accepted->fresh()->payableAmount());
        $this->invalid(fn () => app(AttachmentManagementService::class)->upload($accepted->fresh(), UploadedFile::fake()->createWithContent('replacement.pdf', "%PDF-1.4\n%%EOF"), ['category' => SupplierInvoice::ORIGINAL_DOCUMENT_CATEGORY]), 'file');
        $this->assertSame(1, $accepted->attachments()->count());
    }

    public function test_historical_validated_invoice_retains_its_debt_and_existing_payments(): void
    {
        $invoice = $this->draft();
        // A fixture representing a validated row created before this migration.
        DB::table('supplier_invoices')->where('id', $invoice->id)->update(['status' => 'validated']);
        $historical = $invoice->fresh();
        $this->pay($historical, '20.00');
        $this->assertNull($historical->reconciliation);
        $this->assertSame('120.00', $historical->payableAmount());
        $this->assertSame('100.00', app(SupplierPaymentManagementService::class)->remainingAmount($historical));
        $this->get(route('purchases.invoices.show', $historical))->assertOk()->assertSee('Facture historique');
    }

    public function test_multiple_receipts_and_partial_invoices_keep_reservations(): void
    {
        $order = $this->createOrder([['quantity' => '10.000', 'tax_rate_percent' => '20.00']]);
        $first = $this->createReceipt($order, '4.000')->items->firstOrFail();
        $second = $this->createReceipt($order, '6.000')->items->firstOrFail();
        $service = app(SupplierInvoiceManagementService::class);
        $invoice = $service->createDraft($order, ['supplier_invoice_number' => 'MULTI', 'invoice_date' => today()->toDateString(), 'items' => [
            ['goods_receipt_item_id' => $first->id, 'quantity' => '2.000'], ['goods_receipt_item_id' => $second->id, 'quantity' => '3.000'],
        ]]);
        $review = $this->submitMatchingInvoice($invoice);
        $available = collect($service->availableItemsForOrder($order))->keyBy('goods_receipt_item_id');
        $this->assertSame('2.000', $available[$first->id]['available_quantity']);
        $this->assertSame('3.000', $available[$second->id]['available_quantity']);
        $service->dispute($review, 'Document incorrect');
        $this->assertSame($available->pluck('available_quantity', 'goods_receipt_item_id')->all(), collect($service->availableItemsForOrder($order))->pluck('available_quantity', 'goods_receipt_item_id')->all());
    }

    public function test_view_only_user_cannot_submit_dispute_validate_or_pay(): void
    {
        $invoice = $this->draft();
        $data = $this->supplierData($invoice);
        $review = app(SupplierInvoiceManagementService::class)->submitForReview($invoice, $data);
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Reader', 'slug' => 'reader-test']);
        $role->permissions()->attach(Permission::where('name', 'purchases.view')->value('id'));
        $user->roles()->attach($role);
        $this->actingAs($user);
        $service = app(SupplierInvoiceManagementService::class);
        foreach ([fn () => $service->submitForReview($invoice, $data), fn () => $service->dispute($review, 'Motif'), fn () => $service->validate($review), fn () => $this->pay($review, '1')] as $action) {
            try {
                $action();
                $this->fail('Permission required');
            } catch (AuthorizationException) {
            }
        }
        Volt::test('admin.supplier-invoice-reconciliation', ['invoiceId' => $invoice->id])->assertDontSee('Accepter la facture')->call('accept')->assertForbidden();
    }

    public function test_single_page_livewire_submission_comparison_and_acceptance(): void
    {
        $invoice = $this->draft();
        $data = $this->supplierData($invoice, '110.00');
        $this->createSequence();
        Volt::test('admin.supplier-invoice-reconciliation', ['invoiceId' => $invoice->id])
            ->set('netHt', '110.00')->set('tax', '22.00')->set('documentTtc', '132.00')
            ->set('rate', '20.00')->set('attachmentId', (string) $data['attachment_id'])->set('differenceReason', 'Prix vérifié')
            ->assertSee('Écart à justifier')->assertSee('+12,00')->call('submit')->assertHasNoErrors();
        Volt::test('admin.supplier-invoice-reconciliation', ['invoiceId' => $invoice->id])->assertSee('Accepter la facture')->call('accept')->assertHasNoErrors();
        $this->assertSame('132.00', $invoice->fresh()->payableAmount());
        $this->get(route('purchases.invoices.show', $invoice))->assertOk()->assertSee('132,00');
    }

    public function test_draft_amounts_can_be_saved_without_authorizing_payment_and_stale_edits_are_rejected(): void
    {
        $invoice = $this->draft();
        $data = $this->supplierData($invoice, '110.00');
        $data['difference_reason'] = null;
        $service = app(SupplierInvoiceManagementService::class);
        $saved = $service->saveReconciliationDraft($invoice, $data);
        $this->assertSame('draft', $saved->status);
        $this->assertSame('132.00', $saved->reconciliation->total_ttc);
        $this->assertSame('0.00', $saved->payableAmount());
        $this->invalid(fn () => $service->saveReconciliationDraft($invoice, $data), 'status');
        $this->invalid(fn () => $service->submitForReview($saved, $data), 'reconciliation');
        $this->assertDatabaseHas('supplier_invoice_histories', ['supplier_invoice_id' => $invoice->id, 'event' => 'reconciliation_saved']);
    }

    public function test_supplier_ttc_is_calculated_and_cannot_be_overridden_in_livewire(): void
    {
        $invoice = $this->draft();
        $data = $this->supplierData($invoice);
        Volt::test('admin.supplier-invoice-reconciliation', ['invoiceId' => $invoice->id])
            ->set('netHt', '100.00')->set('tax', '20.00')->set('documentTtc', '999.00')
            ->set('rate', '20.00')->set('attachmentId', (string) $data['attachment_id'])
            ->assertSee('Montants concordants')
            ->assertViewHas('computedTtc', '120.00')
            ->assertDontSeeHtml('wire:model="documentTtc"')
            ->call('submit')->assertHasNoErrors();
        $this->assertSame('under_review', $invoice->fresh()->status);
        $this->assertSame('120.00', $invoice->fresh()->reconciliation->total_ttc);
    }

    public function test_simple_livewire_workspace_auto_ventilates_delivery_at_the_invoice_tax_rate(): void
    {
        $order = $this->createOrder([['quantity' => '10.000', 'unit_price' => '4000.00', 'tax_rate_percent' => '15.00']]);
        $source = $this->createReceipt($order, '10.000')->items->firstOrFail();
        $invoice = app(SupplierInvoiceManagementService::class)->createDraft($order, [
            'supplier_invoice_number' => 'EXT-DELIVERY-4100',
            'invoice_date' => today()->toDateString(),
            'items' => [['goods_receipt_item_id' => $source->id, 'quantity' => '1.000']],
        ]);
        $document = $this->matchingReconciliation($invoice);

        $component = Volt::test('admin.supplier-invoice-reconciliation', ['invoiceId' => $invoice->id])
            ->set('netHt', '4100.00')
            ->set('tax', '615.00')
            ->set('differenceCategory', 'delivery')
            ->assertSet('differenceTaxRate', '15.00')
            ->set('confirmDifferenceTaxTreatment', true)
            ->assertViewHas('previewTotals.base_ht', '4000.00')
            ->assertViewHas('differenceHt', '100.00')
            ->assertViewHas('differenceTax', '15.00')
            ->assertViewHas('difference', '115.00')
            ->assertViewHas('computedTtc', '4715.00')
            ->assertViewHas('fiscalIssue', null)
            ->assertDontSee('Cohérence fiscale à corriger')
            ->assertDontSee('Précisez cet autre motif');

        $this->createSequence();
        $component->call('submit')->assertHasNoErrors();

        $reconciliation = $invoice->fresh()->reconciliation;
        $this->assertSame('4715.00', $reconciliation->total_ttc);
        $this->assertSame([
            ['kind' => 'purchase', 'label' => 'Achats', 'net_ht' => '4000.00', 'rate' => '15.00', 'tax' => '600.00', 'calculated_tax' => '600.00', 'rounding_difference' => '0.00', 'tax_reason' => '', 'rounding_reason' => ''],
            ['kind' => 'transport', 'label' => 'Frais de livraison', 'net_ht' => '100.00', 'rate' => '15.00', 'tax' => '15.00', 'calculated_tax' => '15.00', 'rounding_difference' => '0.00', 'tax_reason' => '', 'rounding_reason' => ''],
        ], $reconciliation->breakdown);
        $this->assertSame($document['attachment_id'], $reconciliation->attachment_id);
    }

    public function test_foreign_or_deleted_original_document_is_rejected(): void
    {
        $invoice = $this->draft();
        $other = $this->draft();
        $data = $this->supplierData($invoice);
        $data['attachment_id'] = $this->supplierData($other)['attachment_id'];
        $this->invalid(fn () => app(SupplierInvoiceManagementService::class)->submitForReview($invoice, $data), 'reconciliation');
        $data = $this->supplierData($invoice);
        Attachment::findOrFail($data['attachment_id'])->delete();
        $this->invalid(fn () => app(SupplierInvoiceManagementService::class)->submitForReview($invoice, $data), 'reconciliation');
    }

    public function test_reconciliation_literals_are_translated_in_all_three_languages(): void
    {
        $sources = ['resources/views/livewire/admin/supplier-invoice-reconciliation.blade.php', 'app/Services/SupplierInvoiceReconciliationCalculator.php', 'app/Models/SupplierInvoiceReconciliation.php', 'app/Livewire/Concerns/PreparesSupplierInvoiceAmounts.php', ...glob(base_path('resources/views/livewire/admin/partials/supplier-invoice-*.blade.php'))];
        foreach (['fr', 'en', 'ar'] as $locale) {
            $translations = json_decode(file_get_contents(base_path('lang/'.$locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
            foreach ($sources as $source) {
                preg_match_all("/__\\('([^']+)'/u", file_get_contents(str_starts_with($source, base_path()) ? $source : base_path($source)), $matches);
                foreach ($matches[1] as $key) {
                    $this->assertArrayHasKey($key, $translations, $locale.': '.$key);
                }
            }
            app()->setLocale($locale);
            Volt::test('admin.supplier-invoice-reconciliation', ['invoiceId' => $this->draft()->id])
                ->assertSee($translations['Vérification des montants']);
        }
    }
}
