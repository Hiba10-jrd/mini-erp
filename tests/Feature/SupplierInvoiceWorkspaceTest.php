<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\AttachmentManagementService;
use App\Services\SupplierInvoiceManagementService;
use Database\Seeders\RbacSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Support\SupplierInvoiceSourceFixtures;
use Tests\TestCase;

class SupplierInvoiceWorkspaceTest extends TestCase
{
    use RefreshDatabase, SupplierInvoiceSourceFixtures;

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

    private function workspace(string $net = '110.00', string $tax = '22.00')
    {
        $order = $this->createOrder([['quantity' => '10.000', 'unit_price' => '100.00', 'tax_rate_percent' => '20.00']]);
        $source = $this->createReceipt($order, '10.000')->items->firstOrFail();

        return Volt::test('admin.supplier-invoice-form', ['orderId' => $order->id])
            ->set('supplierInvoiceNumber', 'UX-'.str()->random(8))
            ->set('lines.'.$source->id.'.quantity', '1.000')
            ->set('netHt', $net)->set('tax', $tax)
            ->set('differenceCategory', 'price')
            ->set('differenceReason', 'Prix fournisseur contrôlé')
            ->set('originalInvoiceFile', UploadedFile::fake()->createWithContent('facture-originale.pdf', "%PDF-1.4\n%%EOF"));
    }

    public function test_creation_document_amounts_and_draft_are_saved_together(): void
    {
        $component = $this->workspace();
        $this->assertDatabaseCount('supplier_invoices', 0);
        $beforeStock = WarehouseStock::all()->toArray();
        $beforeMovements = StockMovement::count();
        $component->assertSee('Vérification des montants')->assertViewHas('difference', '12.00')
            ->call('save')->assertHasNoErrors();
        $invoice = SupplierInvoice::sole();
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertSame('120.00', $invoice->total_ttc);
        $this->assertSame('132.00', $invoice->reconciliation->total_ttc);
        $this->assertSame('0.00', $invoice->payableAmount());
        $this->assertSame(1, $invoice->attachments()->count());
        Storage::disk('attachments')->assertExists($invoice->attachments()->sole()->path);
        $this->assertSame($beforeStock, WarehouseStock::all()->toArray());
        $this->assertSame($beforeMovements, StockMovement::count());
        Volt::test('admin.supplier-invoice-form', ['invoiceId' => $invoice->id])
            ->assertSet('netHt', '110.00')->assertSet('tax', '22.00')->assertSet('documentTtc', '132.00')
            ->assertSee('facture-originale.pdf')->assertDontSeeHtml('id="si-original-file"');
    }

    public function test_first_submission_needs_no_preliminary_draft_or_upload_action_and_validation_is_separate(): void
    {
        $this->createSequence();
        $this->workspace()->call('submit')->assertHasNoErrors();
        $invoice = SupplierInvoice::sole();
        $this->assertSame('under_review', $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertSame('0.00', $invoice->payableAmount());
        Volt::test('admin.supplier-invoice-form', ['invoiceId' => $invoice->id, 'viewOnly' => true])
            ->assertSee('Cette facture attend une décision.')->assertViewHas('canEdit', false)
            ->assertDontSeeHtml('wire:click="save"')->assertDontSeeHtml('wire:click="submit"')
            ->call('accept')->assertHasNoErrors();
        $accepted = $invoice->fresh();
        $this->assertSame('132.00', $accepted->payableAmount());
        $this->get(route('purchases.invoices.show', $accepted))->assertOk()->assertSee('Facture acceptée et validée.')->assertSee('132,00');
    }

    public function test_delivery_difference_is_simple_and_ttc_is_derived_from_net_and_tax(): void
    {
        $order = $this->createOrder([['quantity' => '10.000', 'unit_price' => '4000.00', 'tax_rate_percent' => '15.00']]);
        $source = $this->createReceipt($order, '10.000')->items->firstOrFail();
        $component = Volt::test('admin.supplier-invoice-form', ['orderId' => $order->id])
            ->set('supplierInvoiceNumber', 'UX-DELIVERY-4100')
            ->set('lines.'.$source->id.'.quantity', '1.000')
            ->set('netHt', '4100.00')
            ->set('tax', '615.00')
            ->set('documentTtc', '999999.99')
            ->set('differenceCategory', 'delivery')
            ->set('confirmDifferenceTaxTreatment', true)
            ->set('differenceTaxRate', '15.00')
            ->assertSet('confirmDifferenceTaxTreatment', false)
            ->set('confirmDifferenceTaxTreatment', true)
            ->set('originalInvoiceFile', UploadedFile::fake()->createWithContent('facture-livraison.pdf', "%PDF-1.4\n%%EOF"));

        $component->assertViewHas('previewTotals.base_ht', '4000.00')
            ->assertViewHas('differenceHt', '100.00')
            ->assertViewHas('differenceTax', '15.00')
            ->assertViewHas('difference', '115.00')
            ->assertViewHas('computedTtc', '4715.00')
            ->assertSet('differenceTaxRate', '15.00')
            ->assertViewHas('fiscalIssue', null)
            ->assertViewHas('canSubmit', true)
            ->assertSee('Je confirme ce montant et le traitement de TVA indiqué.')
            ->assertSee('Précision (facultative)')
            ->assertDontSee('Précisez cet autre motif')
            ->assertDontSeeHtml('wire:model="documentTtc"')
            ->set('differenceCategory', 'other')
            ->assertSee('Précisez cet autre motif')
            ->assertViewHas('canSubmit', false)
            ->set('differenceReason', 'Ajustement documenté')
            ->assertViewHas('canSubmit', true)
            ->set('differenceCategory', 'delivery')
            ->assertDontSee('Précisez cet autre motif')
            ->assertSet('confirmDifferenceTaxTreatment', false)
            ->assertViewHas('canSubmit', false)
            ->set('confirmDifferenceTaxTreatment', true)
            ->assertViewHas('canSubmit', true)
            ->set('tax', '616.00')
            ->assertSet('confirmDifferenceTaxTreatment', false)
            ->assertViewHas('canSubmit', false)
            ->set('tax', '615.00')
            ->set('confirmDifferenceTaxTreatment', true)
            ->assertViewHas('canSubmit', true);

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $button = (new DOMXPath($document))->query('//button[contains(normalize-space(.), "Soumettre à vérification")]')->item(0);
        $this->assertNotNull($button);
        $this->assertFalse($button->hasAttribute('disabled'));
        $component->call('submit')->assertHasNoErrors();

        $invoice = SupplierInvoice::sole();
        $this->assertSame('4600.00', $invoice->total_ttc);
        $this->assertSame('4715.00', $invoice->reconciliation->total_ttc);
        $this->assertSame([
            ['kind' => 'purchase', 'label' => 'Achats', 'net_ht' => '4000.00', 'rate' => '15.00', 'tax' => '600.00', 'calculated_tax' => '600.00', 'rounding_difference' => '0.00', 'tax_reason' => '', 'rounding_reason' => ''],
            ['kind' => 'transport', 'label' => 'Frais de livraison', 'net_ht' => '100.00', 'rate' => '15.00', 'tax' => '15.00', 'calculated_tax' => '15.00', 'rounding_difference' => '0.00', 'tax_reason' => '', 'rounding_reason' => ''],
        ], $invoice->reconciliation->breakdown);

        $this->createSequence();
        Volt::test('admin.supplier-invoice-form', ['invoiceId' => $invoice->id, 'viewOnly' => true])
            ->call('accept')->assertHasNoErrors();
        $this->assertSame('4715.00', $invoice->fresh()->payableAmount());
    }

    public function test_submit_button_enabled_state_matches_the_visible_readiness_message(): void
    {
        $order = $this->createOrder([['quantity' => '2.000']]);
        $this->createReceipt($order, '2.000');
        $incomplete = Volt::test('admin.supplier-invoice-form', ['orderId' => $order->id])
            ->set('supplierInvoiceNumber', 'UX-BUTTON-STATE')
            ->set('invoiceDate', today()->toDateString());
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$incomplete->html());
        $xpath = new DOMXPath($document);
        $button = $xpath->query('//button[contains(normalize-space(.), "Soumettre à vérification")]')->item(0);
        $this->assertNotNull($button);
        $this->assertTrue($button->hasAttribute('disabled'));
        $incomplete->assertSee('Ajoutez le document fournisseur avant de soumettre.');

        $ready = $this->workspace();
        $ready->assertViewHas('canSubmit', true)
            ->assertSeeHtml('wire:target="submit,originalInvoiceFile"');
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$ready->html());
        $xpath = new DOMXPath($document);
        $button = $xpath->query('//button[contains(normalize-space(.), "Soumettre à vérification")]')->item(0);
        $this->assertNotNull($button);
        $this->assertFalse($button->hasAttribute('disabled'));
    }

    public function test_simple_discount_difference_uses_an_explicitly_confirmed_tax_rate(): void
    {
        $order = $this->createOrder([['quantity' => '10.000', 'unit_price' => '100.00', 'tax_rate_percent' => '20.00']]);
        $source = $this->createReceipt($order, '10.000')->items->firstOrFail();
        Volt::test('admin.supplier-invoice-form', ['orderId' => $order->id])
            ->set('supplierInvoiceNumber', 'UX-DISCOUNT-090')
            ->set('invoiceDate', today()->toDateString())
            ->set('lines.'.$source->id.'.quantity', '1.000')
            ->set('netHt', '90.00')
            ->set('tax', '18.00')
            ->set('differenceCategory', 'discount')
            ->set('confirmDifferenceTaxTreatment', true)
            ->set('originalInvoiceFile', UploadedFile::fake()->createWithContent('facture-remise.pdf', "%PDF-1.4\n%%EOF"))
            ->assertViewHas('canSubmit', true)
            ->call('submit')->assertHasNoErrors();

        $invoice = SupplierInvoice::sole();
        $this->assertSame('108.00', $invoice->reconciliation->total_ttc);
        $this->assertSame('discount', $invoice->reconciliation->breakdown[1]['kind']);
        $this->assertSame('10.00', $invoice->reconciliation->breakdown[1]['net_ht']);
        $this->assertSame('2.00', $invoice->reconciliation->breakdown[1]['tax']);
    }

    public function test_failed_combined_submission_rolls_back_invoice_document_reservations_and_sequence(): void
    {
        $component = $this->workspace()->set('differenceCategory', '');
        $component->call('submit')->assertHasErrors('differenceCategory')->assertSet('netHt', '110.00');
        $this->assertDatabaseCount('supplier_invoices', 0);
        $this->assertDatabaseCount('supplier_invoice_items', 0);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('attachments')->allFiles());
        $component->set('differenceCategory', 'price')->set('differenceReason', 'Prix confirmé')->set('tax', '23.00')
            ->call('submit')->assertHasErrors('reconciliation');
        $this->assertDatabaseCount('supplier_invoices', 0);
        $this->assertSame([], Storage::disk('attachments')->allFiles());
    }

    public function test_dispute_then_corrected_document_and_amounts_can_be_resubmitted_on_the_same_page(): void
    {
        $this->workspace()->call('submit')->assertHasNoErrors();
        $invoice = SupplierInvoice::sole();
        $oldDocument = $invoice->attachments()->sole();
        Volt::test('admin.supplier-invoice-form', ['invoiceId' => $invoice->id, 'viewOnly' => true])
            ->set('showDispute', true)->call('dispute')->assertHasErrors('reason')
            ->set('disputeReason', 'Document à corriger')->call('dispute')->assertHasNoErrors();
        $this->assertSame('disputed', $invoice->fresh()->status);
        $correction = UploadedFile::fake()->createWithContent('facture-corrigee.pdf', "%PDF-1.4\n%%EOF");
        Volt::test('admin.supplier-invoice-form', ['invoiceId' => $invoice->id, 'viewOnly' => true])
            ->assertSee('Facture contestée — paiement bloqué.')->assertSee('Document à corriger')
            ->set('originalInvoiceFile', $correction)->set('netHt', '90.00')->set('tax', '18.00')->set('documentTtc', '108.00')
            ->set('differenceReason', 'Prix corrigé vérifié')->call('submit')->assertHasNoErrors();
        $review = $invoice->fresh();
        $this->assertSame('under_review', $review->status);
        $this->assertSame('0.00', $review->payableAmount());
        $this->assertSame(2, $review->attachments()->count());
        $this->assertNotEquals($oldDocument->id, $review->reconciliation->attachment_id);
        Storage::disk('attachments')->assertExists($oldDocument->path);
        $this->assertSame('9.000', app(SupplierInvoiceManagementService::class)->availableItemsForOrder($review->purchaseOrder)[0]['available_quantity']);
    }

    public function test_commercial_difference_and_fiscal_inconsistency_have_distinct_states(): void
    {
        $component = $this->workspace('100.00', '20.00')->set('differenceCategory', '')->set('differenceReason', '');
        $component->assertViewHas('hasDifference', false)->assertViewHas('advancedNeeded', false)
            ->assertDontSeeHtml('data-difference-reason')->assertSee('Montants concordants');
        $component->set('taxDetailsOpen', true)->set('tax', '20.00');
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $this->assertSame(1, (new DOMXPath($document))->query('//details[@data-tax-details][@open]')->length);
        $component->set('taxDetailsOpen', false);
        $component->set('netHt', '110.00')->set('tax', '22.00')->set('differenceCategory', 'price')
            ->assertViewHas('hasDifference', true)->assertViewHas('fiscalIssue', null)
            ->assertSeeHtml('data-difference-reason')->assertSee('Écart à justifier');
        $component->set('tax', '23.00')
            ->assertViewHas('advancedNeeded', true)->assertSee('Cohérence fiscale à corriger')
            ->assertDontSee('Montants concordants')->call('submit')->assertHasErrors('reconciliation');
    }

    public function test_explicit_zero_tax_treatment_is_revealed_and_remains_required(): void
    {
        $component = $this->workspace('100.00', '0.00')->set('rate', '0.0');
        $component->assertViewHas('advancedNeeded', true)->assertSee('Traitement sans TVA')
            ->call('submit')->assertHasErrors('reconciliation');
        $component->set('taxReason', 'Exonération justifiée par le document')->call('submit')->assertHasNoErrors();
        $this->assertSame('0.00', SupplierInvoice::sole()->reconciliation->tax_total);
    }

    public function test_advanced_charges_use_existing_calculator_without_modifying_order_prices(): void
    {
        $component = $this->workspace('100.00', '20.00')->set('differenceCategory', 'delivery')->call('addComponent');
        $component->set('breakdown.1.kind', 'transport')->set('breakdown.1.label', 'Transport')
            ->set('breakdown.1.net_ht', '10.00')->set('breakdown.1.rate', '10.00')->set('breakdown.1.tax', '1.00')
            ->set('netHt', '110.00')->set('tax', '21.00')
            ->set('confirmDifferenceTaxTreatment', true)
            ->assertViewHas('fiscalIssue', null)->call('submit')->assertHasNoErrors();
        $invoice = SupplierInvoice::sole();
        $this->assertCount(2, $invoice->reconciliation->breakdown);
        $this->assertSame('131.00', $invoice->reconciliation->total_ttc);
        $this->assertSame('100.00', $invoice->purchaseOrder->items->first()->unit_price);
        $this->assertSame('120.00', $invoice->total_ttc);
    }

    public function test_component_entry_then_final_tax_confirmation_allows_supplier_invoice_submission(): void
    {
        $order = $this->createOrder([['quantity' => '1.000', 'unit_price' => '1000.00', 'tax_rate_percent' => '10.00']]);
        $source = $this->createReceipt($order, '1.000')->items->firstOrFail();
        $component = Volt::test('admin.supplier-invoice-form', ['orderId' => $order->id])
            ->set('supplierInvoiceNumber', 'UX-TRANSPORT-COMPONENTS')
            ->set('invoiceDate', today()->toDateString())
            ->set('lines.'.$source->id.'.quantity', '1.000')
            ->set('netHt', '1100.00')
            ->set('tax', '110.00')
            ->set('differenceCategory', 'delivery')
            ->assertSet('confirmDifferenceTaxTreatment', false)
            ->set('rate', '10.00')
            ->call('addComponent')
            ->set('breakdown.0.kind', 'purchase')
            ->set('breakdown.0.label', 'Achats')
            ->set('breakdown.0.net_ht', '1000.00')
            ->set('breakdown.0.rate', '10.00')
            ->set('breakdown.0.tax', '100.00')
            ->set('breakdown.1.kind', 'transport')
            ->set('breakdown.1.label', 'Transport')
            ->set('breakdown.1.net_ht', '100.00')
            ->set('breakdown.1.rate', '10.00')
            ->set('breakdown.1.tax', '10.00')
            ->set('originalInvoiceFile', UploadedFile::fake()->createWithContent('facture-transport.pdf', "%PDF-1.4\n%%EOF"));

        $component->assertViewHas('previewTotals.base_ht', '1000.00')
            ->assertViewHas('previewTotals.tax_total', '100.00')
            ->assertViewHas('differenceHt', '100.00')
            ->assertViewHas('differenceTax', '10.00')
            ->assertViewHas('computedTtc', '1210.00')
            ->assertViewHas('fiscalIssue', null)
            ->assertViewHas('differenceTreatmentRequired', true)
            ->assertSet('confirmDifferenceTaxTreatment', false)
            ->assertSeeHtml('wire:model.live="confirmDifferenceTaxTreatment"')
            ->assertSee('Confirmez le montant et le traitement TVA avant de soumettre.');

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $button = (new DOMXPath($document))->query('//button[contains(normalize-space(.), "Soumettre à vérification")]')->item(0);
        $this->assertNotNull($button);
        $this->assertTrue($button->hasAttribute('disabled'), 'The server must disable submission until the final tax confirmation.');

        $component->set('confirmDifferenceTaxTreatment', true)
            ->assertSet('confirmDifferenceTaxTreatment', true)
            ->assertSet('supplierInvoiceNumber', 'UX-TRANSPORT-COMPONENTS')
            ->assertSet('invoiceDate', today()->toDateString())
            ->assertSet('netHt', '1100.00')
            ->assertSet('tax', '110.00')
            ->assertViewHas('canPrepareAmounts', true)
            ->assertViewHas('order', fn ($order) => $order !== null)
            ->assertViewHas('lines', fn ($lines) => $lines !== [])
            ->assertViewHas('selectedReceipts', fn ($receipts) => $receipts->isNotEmpty())
            ->assertViewHas('invalidSelectedLine', false)
            ->assertViewHas('activeDocument', null)
            ->assertViewHas('originalInvoiceFile', fn ($file) => $file !== null)
            ->assertViewHas('documentTtc', '1210.00')
            ->assertViewHas('differenceTreatmentRequired', false)
            ->assertViewHas('categoryRequired', false)
            ->assertViewHas('reasonRequired', false)
            ->assertViewHas('fiscalIssue', null)
            ->assertViewHas('canSubmit', true)
            ->assertSee('Vous pouvez soumettre cette facture à vérification.')
            ->assertDontSee('Confirmez le montant et le traitement TVA avant de soumettre.');

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $button = (new DOMXPath($document))->query('//button[contains(normalize-space(.), "Soumettre à vérification")]')->item(0);
        $this->assertNotNull($button);
        $this->assertFalse($button->hasAttribute('disabled'));
        $component->assertSeeHtml('wire:loading.attr="disabled"')
            ->assertSeeHtml('wire:target="submit,originalInvoiceFile"');

        $component->call('submit')->assertHasNoErrors();

        $invoice = SupplierInvoice::sole();
        $this->assertSame('under_review', $invoice->status);
        $this->assertSame('1210.00', $invoice->reconciliation->total_ttc);
        $this->assertSame([
            ['kind' => 'purchase', 'label' => 'Achats', 'net_ht' => '1000.00', 'rate' => '10.00', 'tax' => '100.00', 'calculated_tax' => '100.00', 'rounding_difference' => '0.00', 'tax_reason' => '', 'rounding_reason' => ''],
            ['kind' => 'transport', 'label' => 'Transport', 'net_ht' => '100.00', 'rate' => '10.00', 'tax' => '10.00', 'calculated_tax' => '10.00', 'rounding_difference' => '0.00', 'tax_reason' => '', 'rounding_reason' => ''],
        ], $invoice->reconciliation->breakdown);
    }

    public function test_pdf_upload_keeps_its_event_target_until_upload_finishes_and_submission_is_ready(): void
    {
        $order = $this->createOrder([['quantity' => '1.000', 'unit_price' => '1000.00', 'tax_rate_percent' => '10.00']]);
        $source = $this->createReceipt($order, '1.000')->items->firstOrFail();
        $component = Volt::test('admin.supplier-invoice-form', ['orderId' => $order->id])
            ->set('supplierInvoiceNumber', 'UX-UPLOAD-EVENTS')
            ->set('invoiceDate', today()->toDateString())
            ->set('lines.'.$source->id.'.quantity', '1.000')
            ->set('originalInvoiceFile', UploadedFile::fake()->createWithContent('facture-originale.pdf', "%PDF-1.4\n%%EOF"));

        $component->assertSee('facture-originale.pdf')
            ->assertSeeHtml('wire:model="originalInvoiceFile"')
            ->assertSeeHtml('livewire-upload-start')
            ->assertSeeHtml('livewire-upload-finish')
            ->assertSeeHtml('livewire-upload-error')
            ->assertSeeHtml('livewire-upload-cancel')
            ->assertSeeHtml('wire:loading.attr="disabled"')
            ->assertSeeHtml('wire:target="submit,originalInvoiceFile"');

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new DOMXPath($document);
        $uploadArea = $xpath->query('//*[@id="si-upload-area"]')->item(0);
        $uploadInput = $xpath->query('//input[@id="si-original-file"]')->item(0);
        $this->assertNotNull($uploadArea, 'Keep the upload target in the DOM until Livewire dispatches its completion event.');
        $this->assertNotNull($uploadInput);
        $this->assertStringContainsString('hidden', $uploadArea->getAttribute('class'));

        $component->set('netHt', '1100.00')
            ->set('tax', '110.00')
            ->set('differenceCategory', 'delivery')
            ->call('addComponent')
            ->set('breakdown.0.kind', 'purchase')
            ->set('breakdown.0.label', 'Achats')
            ->set('breakdown.0.net_ht', '1000.00')
            ->set('breakdown.0.rate', '10.00')
            ->set('breakdown.0.tax', '100.00')
            ->set('breakdown.1.kind', 'transport')
            ->set('breakdown.1.label', 'Transport')
            ->set('breakdown.1.net_ht', '100.00')
            ->set('breakdown.1.rate', '10.00')
            ->set('breakdown.1.tax', '10.00')
            ->set('confirmDifferenceTaxTreatment', true)
            ->assertViewHas('fiscalIssue', null)
            ->assertViewHas('differenceTreatmentRequired', false)
            ->assertViewHas('canSubmit', true);

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $button = (new DOMXPath($document))->query('//button[contains(normalize-space(.), "Soumettre à vérification")]')->item(0);
        $this->assertNotNull($button);
        $this->assertFalse($button->hasAttribute('disabled'));
        $component->call('submit')->assertHasNoErrors();

        $invoice = SupplierInvoice::sole();
        $this->assertSame('under_review', $invoice->status);
        $this->assertSame('1210.00', $invoice->reconciliation->total_ttc);
    }

    public function test_supplementary_document_manager_excludes_original_only_when_requested(): void
    {
        $this->workspace()->call('save')->assertHasNoErrors();
        $invoice = SupplierInvoice::sole();
        app(AttachmentManagementService::class)->upload($invoice, UploadedFile::fake()->createWithContent('justificatif.pdf', "%PDF-1.4\n%%EOF"), ['category' => 'Justificatif']);
        Volt::test('attachments-manager', ['parentType' => 'supplier-invoice', 'parentId' => $invoice->id, 'excludeOriginalInvoice' => true])
            ->assertViewHas('documents', fn ($documents) => $documents->total() === 1 && $documents->first()->original_name === 'justificatif.pdf');
        Volt::test('attachments-manager', ['parentType' => 'supplier-invoice', 'parentId' => $invoice->id])->assertViewHas('documents', fn ($documents) => $documents->total() === 2);
    }

    public function test_five_sections_and_accessible_inputs_are_present_without_receipt_table(): void
    {
        $component = $this->workspace();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new DOMXPath($document);
        $this->assertSame(5, $xpath->query('//section[@data-invoice-section]')->length);
        $this->assertSame(0, $xpath->query('//table')->length);
        foreach ($xpath->query('//input[not(@type="hidden")]') as $input) {
            $this->assertTrue($input->hasAttribute('aria-label') || ($input->hasAttribute('id') && $xpath->query('//label[@for="'.$input->getAttribute('id').'"]')->length > 0), 'Input lacks a label: '.$input->getAttribute('id'));
        }
    }

    public function test_no_available_receipts_is_a_readable_empty_state(): void
    {
        $order = $this->createOrder([['quantity' => '1.000']]);
        Volt::test('admin.supplier-invoice-form', ['orderId' => $order->id])->assertSee('Aucune quantité reçue ne reste à facturer.')->assertStatus(200);
    }

    public function test_missing_document_and_incomplete_amounts_do_not_create_a_partial_invoice(): void
    {
        $component = $this->workspace()->call('clearOriginalInvoiceFile');
        $component->call('submit')->assertHasErrors('attachment_id')->assertSee('Ajoutez le document fournisseur avant de soumettre.');
        $this->assertDatabaseCount('supplier_invoices', 0);
        $component->set('originalInvoiceFile', UploadedFile::fake()->createWithContent('facture.pdf', "%PDF-1.4\n%%EOF"))
            ->set('tax', '')->call('save')->assertHasErrors('tax_total')->assertSet('netHt', '110.00');
        $this->assertDatabaseCount('supplier_invoices', 0);
        $this->assertSame([], Storage::disk('attachments')->allFiles());
    }

    public function test_quantity_error_is_associated_with_the_selected_row_after_an_unselected_row(): void
    {
        $order = $this->createOrder([['quantity' => '5.000', 'tax_rate_percent' => '20.00']]);
        $first = $this->createReceipt($order, '2.000')->items->firstOrFail();
        $second = $this->createReceipt($order, '3.000')->items->firstOrFail();
        $component = Volt::test('admin.supplier-invoice-form', ['orderId' => $order->id])
            ->set('supplierInvoiceNumber', 'UX-QUANTITY')->set('lines.'.$first->id.'.selected', false)
            ->set('lines.'.$second->id.'.quantity', '4.000')->call('save')->assertHasErrors('items.0.quantity');
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new DOMXPath($document);
        $this->assertNotSame('', trim($xpath->query('//*[@id="si-quantity-error-'.$second->id.'"]')->item(0)->textContent));
        $this->assertSame('', trim($xpath->query('//*[@id="si-quantity-error-'.$first->id.'"]')->item(0)->textContent));
    }

    public function test_stale_workspace_update_is_rejected_and_validated_workspace_cannot_be_modified(): void
    {
        $this->workspace()->call('save')->assertHasNoErrors();
        $invoice = SupplierInvoice::sole();
        $first = Volt::test('admin.supplier-invoice-form', ['invoiceId' => $invoice->id]);
        $stale = Volt::test('admin.supplier-invoice-form', ['invoiceId' => $invoice->id]);
        $first->set('notes', 'Version récente')->call('save')->assertHasNoErrors();
        $stale->set('notes', 'Écran périmé')->call('save')->assertHasErrors('status');
        $this->assertSame('Version récente', $invoice->fresh()->notes);
        Volt::test('admin.supplier-invoice-form', ['invoiceId' => $invoice->id])->call('submit')->assertHasNoErrors();
        $this->createSequence();
        Volt::test('admin.supplier-invoice-form', ['invoiceId' => $invoice->id, 'viewOnly' => true])->call('accept')->assertHasNoErrors();
        Volt::test('admin.supplier-invoice-form', ['invoiceId' => $invoice->id, 'viewOnly' => true])->set('netHt', '1.00')->call('save')->assertForbidden();
        $this->assertSame('132.00', $invoice->fresh()->payableAmount());
    }

    public function test_two_workspaces_cannot_reserve_the_same_receipt_quantity_twice(): void
    {
        $order = $this->createOrder([['quantity' => '1.000']]);
        $this->createReceipt($order, '1.000');
        $first = Volt::test('admin.supplier-invoice-form', ['orderId' => $order->id])->set('supplierInvoiceNumber', 'RESERVE-FIRST');
        $second = Volt::test('admin.supplier-invoice-form', ['orderId' => $order->id])->set('supplierInvoiceNumber', 'RESERVE-SECOND');
        $first->call('save')->assertHasNoErrors();
        $second->call('save')->assertHasErrors('items.0.quantity');
        $this->assertDatabaseCount('supplier_invoices', 1);
        $this->assertSame([], app(SupplierInvoiceManagementService::class)->availableItemsForOrder($order));
    }

    public function test_reader_cannot_edit_submit_accept_dispute_or_add_financial_components(): void
    {
        $this->workspace()->call('save')->assertHasNoErrors();
        $invoice = SupplierInvoice::sole();
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Workspace reader', 'slug' => 'workspace-reader']);
        $role->permissions()->attach(Permission::where('name', 'purchases.view')->value('id'));
        $user->roles()->attach($role);
        $this->actingAs($user);
        foreach (['save', 'submit', 'accept', 'dispute', 'addComponent'] as $action) {
            Volt::test('admin.supplier-invoice-form', ['invoiceId' => $invoice->id, 'viewOnly' => true])
                ->assertViewHas('canEdit', false)->call($action)->assertForbidden();
        }
    }

    public function test_french_english_and_arabic_workspace_routes_and_direction_render(): void
    {
        $this->workspace()->call('save')->assertHasNoErrors();
        $invoice = SupplierInvoice::sole();
        foreach (['fr', 'en', 'ar'] as $locale) {
            $translations = json_decode(file_get_contents(base_path('lang/'.$locale.'.json')), true);
            $this->withSession(['locale' => $locale])->get(route('purchases.invoices.show', $invoice))
                ->assertOk()->assertSee($translations['Enregistrer et vérifier une facture fournisseur'])
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);
        }
    }
}
