<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\AttachmentAuthorizationService;
use App\Services\AttachmentManagementService;
use App\Services\AuditTrailService;
use App\Services\InvoiceManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CustomerReminderFixtures;
use Tests\TestCase;

class AttachmentManagementTest extends TestCase
{
    use CustomerReminderFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('attachments');
        Storage::fake('local');
        $this->actingAs($this->userWithPermissions(['payments.view', 'payments.create', 'invoices.view', 'invoices.create', 'invoices.validate', 'sales.create', 'sales.update']));
    }

    private function expense(): Expense
    {
        $category = ExpenseCategory::create(['name' => 'Documents', 'is_active' => true]);
        $method = PaymentMethod::create(['name' => 'Bank', 'is_active' => true]);

        return Expense::create(['expense_category_id' => $category->id, 'payment_method_id' => $method->id, 'expense_date' => today(), 'amount' => '10.00', 'tax_amount' => '0.00']);
    }

    private function pdf(string $name = 'preuve.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    }

    public static function images(): array
    {
        return [['jpg'], ['png'], ['webp']];
    }

    public function test_three_kilobyte_pdf_survives_type_update_and_saves_then_resets(): void
    {
        $invoice = $this->invoice(false);
        $content = str_pad("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF", 3072, ' ');
        $component = Volt::test('attachments-manager', ['parentType' => 'invoice', 'parentId' => $invoice->id])
            ->set('file', UploadedFile::fake()->createWithContent('technique.pdf', $content))
            ->set('category', 'Document technique');
        $temporary = $component->get('file');
        $this->assertInstanceOf(TemporaryUploadedFile::class, $temporary);
        $this->assertTrue($temporary->exists());
        $this->assertTrue($temporary->isValid());
        $this->assertSame(3072, $temporary->getSize());
        $component->call('saveDocument')->assertHasNoErrors()->assertSee('technique.pdf')
            ->assertSee('Documents ajoutés')
            ->assertSet('file', null)->assertSet('category', '')->assertSet('description', '');
        $this->assertSame(1, $component->viewData('documents')->total());
        $document = $invoice->attachments()->sole();
        $this->assertSame('Document technique', $document->category);
        $this->assertSame($content, Storage::disk('attachments')->get($document->path));

        // A second submission uses the same component without reloading.
        $component->set('file', $this->pdf('second.pdf'))->call('saveDocument')->assertHasNoErrors();
        $this->assertSame(2, $component->viewData('documents')->total());
    }

    public function test_temporary_upload_error_can_be_followed_by_success_without_reload(): void
    {
        $component = Volt::test('attachments-manager', ['parentType' => 'invoice', 'parentId' => $this->invoice(false)->id]);
        $component->call('_uploadErrored', 'file', null, false)->assertHasErrors(['file']);
        $component->set('file', $this->pdf('retry.pdf'))->set('category', 'Document technique')
            ->call('saveDocument')->assertHasNoErrors()->assertSee('retry.pdf')->assertSet('file', null);
        $this->assertSame(1, $component->viewData('documents')->total());
    }

    public function test_selected_file_can_be_cleared_and_replaced_without_affecting_saved_documents(): void
    {
        $invoice = $this->invoice(false);
        $existing = app(AttachmentManagementService::class)->upload($invoice, $this->pdf('existing.pdf'));
        $original = $existing->refresh()->getAttributes();
        $component = Volt::test('attachments-manager', ['parentType' => 'invoice', 'parentId' => $invoice->id])
            ->set('file', $this->pdf('selected.pdf'))
            ->set('category', 'Document technique')->set('description', 'À conserver')
            ->assertSee('selected.pdf')->assertSee('Retirer');
        $temporary = $component->get('file');
        $this->mock(AuditTrailService::class)->shouldNotReceive('record');
        $component->call('_uploadErrored', 'file', null, false)->assertHasErrors(['file'])
            ->call('clearSelectedFile')->assertHasNoErrors(['file'])->assertSet('file', null)
            ->assertDontSee('selected.pdf')->assertDontSee('Retirer')
            ->assertSet('category', 'Document technique')->assertSet('description', 'À conserver')
            ->assertSee('existing.pdf');
        $this->assertTrue($temporary->exists());
        $this->assertSame($original, $existing->fresh()->getAttributes());
        Storage::disk('attachments')->assertExists($existing->path);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertSame(1, $component->viewData('documents')->total());

        // Restore the real audit service only for the subsequent normal upload.
        $this->app->forgetInstance(AuditTrailService::class);
        $component->set('file', $this->pdf('replacement.pdf'))->assertSee('replacement.pdf')
            ->call('saveDocument')->assertHasNoErrors()->assertSet('file', null);
        $this->assertSame(2, $component->viewData('documents')->total());
        $this->assertDatabaseHas('attachments', ['original_name' => 'replacement.pdf', 'category' => 'Document technique', 'description' => 'À conserver']);
        $this->assertSame($original, $existing->fresh()->getAttributes());
    }

    public function test_document_form_saves_predefined_and_custom_types_and_lists_legacy_values(): void
    {
        $invoice = $this->invoice(false);
        app(AttachmentManagementService::class)->upload($invoice, $this->pdf('ancien.pdf'), ['category' => 'Ancienne catégorie']);
        $component = Volt::test('attachments-manager', ['parentType' => 'invoice', 'parentId' => $invoice->id])
            ->assertSee('Documents liés à cette facture')
            ->assertSee('Ancienne catégorie')
            ->set('file', $this->pdf('commande.pdf'))
            ->set('category', 'Bon de commande')
            ->call('saveDocument')
            ->assertHasNoErrors()
            ->assertSee('commande.pdf')
            ->assertSet('category', '')
            ->assertSet('file', null);
        $this->assertDatabaseHas('attachments', ['original_name' => 'commande.pdf', 'category' => 'Bon de commande', 'description' => null]);

        $component->set('category', 'other')->assertSee('Préciser le type')
            ->set('file', $this->pdf('intervention.pdf'))
            ->set('customType', '   ')->call('saveDocument')->assertHasErrors(['customType'])
            ->set('customType', ' Procès-verbal d’intervention ')
            ->set('description', 'Intervention sur site')
            ->call('saveDocument')->assertHasNoErrors()->assertSee('Procès-verbal d’intervention');
        $this->assertDatabaseHas('attachments', ['original_name' => 'intervention.pdf', 'category' => 'Procès-verbal d’intervention', 'description' => 'Intervention sur site']);
    }

    public function test_document_form_rejects_invalid_metadata_and_files(): void
    {
        $component = Volt::test('attachments-manager', ['parentType' => 'expense', 'parentId' => $this->expense()->id]);
        $component->call('saveDocument')->assertHasErrors(['file']);
        $component->set('file', $this->pdf())->set('category', 'forged')->call('saveDocument')->assertHasErrors(['category']);
        $component->set('category', 'other')->set('customType', str_repeat('a', 101))->call('saveDocument')->assertHasErrors(['customType']);
        $component->set('category', '')->set('customType', '')->set('description', str_repeat('a', 2001))->call('saveDocument')->assertHasErrors(['description']);
        $component->set('description', '')->set('file', UploadedFile::fake()->createWithContent('invalid.pdf', 'plain text'))
            ->call('saveDocument')->assertHasErrors(['file']);
        $component->set('file', UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf'))
            ->call('saveDocument')->assertHasErrors(['file']);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_document_form_permissions_and_scoped_deletion(): void
    {
        $invoice = $this->invoice(false);
        $document = app(AttachmentManagementService::class)->upload($invoice, $this->pdf());
        $other = app(AttachmentManagementService::class)->upload($this->expense(), $this->pdf());
        try {
            Volt::test('attachments-manager', ['parentType' => 'invoice', 'parentId' => $invoice->id])
                ->call('remove', $other->id);
            $this->fail('A document from another parent was removed.');
        } catch (ModelNotFoundException) {
            $this->assertNotSoftDeleted('attachments', ['id' => $other->id]);
        }
        $this->actingAs($this->userWithPermissions(['invoices.view']));
        Volt::test('attachments-manager', ['parentType' => 'invoice', 'parentId' => $invoice->id])
            ->assertDontSee('Choisir un fichier')->assertDontSee('Supprimer')->assertSee('Télécharger')
            ->call('remove', $document->id)->assertForbidden();
        Volt::test('attachments-manager', ['parentType' => 'invoice', 'parentId' => $invoice->id])
            ->set('file', $this->pdf())->call('saveDocument')->assertForbidden();
        $this->actingAs($this->userWithPermissions(['invoices.view', 'invoices.create']));
        Volt::test('attachments-manager', ['parentType' => 'invoice', 'parentId' => $invoice->id])
            ->call('remove', $document->id)->assertHasNoErrors()->assertDontSee('preuve.pdf');
        $this->assertSoftDeleted('attachments', ['id' => $document->id]);
        $this->actingAs($this->userWithPermissions(['stock.view']));
        Volt::test('attachments-manager', ['parentType' => 'invoice', 'parentId' => $invoice->id])->assertForbidden();
    }

    #[DataProvider('images')]
    public function test_valid_image_formats_are_private(string $extension): void
    {
        $attachment = app(AttachmentManagementService::class)->upload($this->expense(), UploadedFile::fake()->image('preuve.'.$extension));
        Storage::disk('attachments')->assertExists($attachment->path);
        $this->assertStringStartsWith('image/', $attachment->mime_type);
        $this->assertArrayNotHasKey('path', $attachment->toArray());
    }

    public function test_pdf_metadata_generated_path_and_secure_download(): void
    {
        $expense = $this->expense();
        $file = $this->pdf('Facture originale.pdf');
        $attachment = app(AttachmentManagementService::class)->upload($expense, $file);
        $this->assertSame('Facture originale.pdf', $attachment->original_name);
        $this->assertSame('expense', $attachment->attachable_type);
        $this->assertTrue($attachment->attachable->is($expense));
        $this->assertSame(auth()->id(), $attachment->uploaded_by);
        $this->assertSame($file->getSize(), (int) $attachment->size);
        $this->assertStringNotContainsString('originale', $attachment->path);
        $this->get(route('attachments.download', $attachment))->assertOk()->assertDownload('Facture originale.pdf');
        $this->get(route('attachments.index', ['parentType' => 'expense', 'parentId' => $expense->id]))->assertOk()->assertDontSee($attachment->path);
        $this->actingAs($this->userWithPermissions(['stock.view']));
        $this->get(route('attachments.download', $attachment))->assertForbidden();
    }

    public static function invalidFiles(): array
    {
        return [['texte.pdf', 'hello'], ['preuve.php.pdf', "%PDF-1.4\n%%EOF"], ['preuve.html', "%PDF-1.4\n%%EOF"], ['preuve.js.png', "%PDF-1.4\n%%EOF"]];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_mime_and_extensions_leave_no_row_or_file(string $name, string $content): void
    {
        try {
            app(AttachmentManagementService::class)->upload($this->expense(), UploadedFile::fake()->createWithContent($name, $content));
            $this->fail('Invalid upload accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('attachments', 0);
            $this->assertSame([], Storage::disk('attachments')->allFiles());
        }
    }

    public function test_size_limit_and_upload_permission(): void
    {
        $expense = $this->expense();
        try {
            app(AttachmentManagementService::class)->upload($expense, UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf'));
            $this->fail('Oversize accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('attachments', 0);
        }
        $this->actingAs($this->userWithPermissions(['payments.view']));
        $this->expectException(AuthorizationException::class);
        app(AttachmentManagementService::class)->upload($expense, $this->pdf());
    }

    public function test_draft_soft_delete_keeps_file_and_finalized_parent_allows_add_but_not_delete(): void
    {
        $invoice = $this->invoice(false);
        $service = app(AttachmentManagementService::class);
        $draft = $service->upload($invoice, $this->pdf());
        $service->delete($draft);
        $this->assertSoftDeleted('attachments', ['id' => $draft->id]);
        Storage::disk('attachments')->assertExists($draft->path);
        $this->get(route('attachments.download', $draft))->assertNotFound();
        $issued = app(InvoiceManagementService::class)->issue($invoice);
        $final = $service->upload($issued, $this->pdf());
        $this->expectException(HttpException::class);
        $service->delete($final);
    }

    public function test_missing_file_and_unknown_parent_are_not_found(): void
    {
        $attachment = app(AttachmentManagementService::class)->upload($this->expense(), $this->pdf());
        Storage::disk('attachments')->delete($attachment->path);
        $this->get(route('attachments.download', $attachment))->assertNotFound();
        $this->get('/documents/App.Models.User/1')->assertNotFound();
    }

    public function test_database_failure_cleans_up_file(): void
    {
        $expense = $this->expense();
        $this->mock(AuditTrailService::class)->shouldReceive('record')->andThrow(new \RuntimeException('DB failed'));
        try {
            app(AttachmentManagementService::class)->upload($expense, $this->pdf());
            $this->fail();
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('attachments', 0);
            $this->assertSame([], Storage::disk('attachments')->allFiles());
        }
    }

    public function test_storage_failure_creates_no_database_row(): void
    {
        $expense = $this->expense();
        Storage::shouldReceive('disk')->with('attachments')->andReturn($disk = \Mockery::mock());
        $disk->shouldReceive('putFileAs')->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        try {
            app(AttachmentManagementService::class)->upload($expense, $this->pdf());
            $this->fail();
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('attachments', 0);
        }
    }

    public function test_legacy_receipt_is_preserved_downloadable_and_traversal_rejected(): void
    {
        $expense = $this->expense();
        $expense->update(['receipt_path' => 'expenses/receipts/legacy.pdf']);
        Storage::disk('local')->put($expense->receipt_path, '%PDF-1.4');
        $this->get(route('attachments.expense-legacy', $expense))->assertOk()->assertDownload('legacy.pdf');
        Volt::test('attachments-manager', ['parentType' => 'expense', 'parentId' => $expense->id])->assertSee('justificatif historique');
        $this->assertSame('expenses/receipts/legacy.pdf', $expense->fresh()->receipt_path);
        $expense->update(['receipt_path' => 'expenses/receipts/../../secret.pdf']);
        $this->get(route('attachments.expense-legacy', $expense))->assertNotFound();
    }

    public static function parentPermissions(): array
    {
        return [['invoice', 'invoices.view', 'invoices.create'], ['credit-note', 'invoices.view', 'invoices.create'], ['payment', 'payments.view', 'payments.create'], ['supplier-payment', 'payments.view', 'payments.create'], ['expense', 'payments.view', 'payments.create'], ['supplier-invoice', 'purchases.view', 'purchases.update'], ['goods-receipt', 'purchases.view', 'purchases.update'], ['purchase-order', 'purchases.view', 'purchases.update']];
    }

    #[DataProvider('parentPermissions')]
    public function test_eight_parent_permission_strategies(string $alias, string $read, string $write): void
    {
        $class = AttachmentAuthorizationService::PARENTS[$alias];
        $model = new $class;
        $model->exists = true;
        $this->assertSame($alias, $model->getMorphClass());
        $this->assertSame([$read, $write], app(AttachmentAuthorizationService::class)->permissions($model));
        $this->actingAs($this->userWithPermissions([$read, $write]));
        app(AttachmentAuthorizationService::class)->authorize($model, true);
        $this->actingAs($this->userWithPermissions([$read]));
        $this->expectException(AuthorizationException::class);
        app(AttachmentAuthorizationService::class)->authorize($model, true);
    }
}
