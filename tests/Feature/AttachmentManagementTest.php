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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
