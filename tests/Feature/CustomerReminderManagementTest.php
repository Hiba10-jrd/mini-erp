<?php

namespace Tests\Feature;

use App\Models\CustomerReminder;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CustomerReminderManagementService;
use App\Services\InvoiceManagementService;
use App\Services\PaymentManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerReminderFixtures;
use Tests\TestCase;

class CustomerReminderManagementTest extends TestCase
{
    use CustomerReminderFixtures;
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 0));
        $this->seed(RbacSeeder::class);
        $this->operator = $this->userWithPermissions([
            'sales.create', 'sales.update', 'invoices.create', 'invoices.validate', 'payments.create',
        ]);
        $this->actingAs($this->operator);
    }

    public function test_payment_create_permission_is_required(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->userWithPermissions(['payments.view']));
        try {
            $this->service()->create($invoice, $this->attributes());
            $this->fail('Expected authorization rejection.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('customer_reminders', 0);
        }
    }

    public static function validChannels(): array
    {
        return [['email'], ['phone'], ['whatsapp'], ['manual']];
    }

    #[DataProvider('validChannels')]
    public function test_issued_invoice_accepts_each_channel_and_records_responsible_user(string $channel): void
    {
        $invoice = $this->invoice();
        $reminder = $this->service()->create($invoice, [
            ...$this->attributes(), 'channel' => $channel, 'note' => '  Appel client  ',
            'created_by' => 999999, 'invoice_id' => 999999,
        ]);
        $this->assertSame($invoice->id, $reminder->invoice_id);
        $this->assertSame($this->operator->id, $reminder->created_by);
        $this->assertSame('2026-10-04', $reminder->reminder_date->toDateString());
        $this->assertSame($channel, $reminder->channel);
        $this->assertSame('Appel client', $reminder->note);
        $this->assertTrue($reminder->invoice->is($invoice));
        $this->assertTrue($reminder->creator->is($this->operator));
        $this->assertTrue($this->operator->createdCustomerReminders()->first()->is($reminder));
    }

    public static function invalidAttributes(): array
    {
        return [
            'unknown channel' => [['channel' => 'sms'], 'channel'],
            'channel case' => [['channel' => 'EMAIL'], 'channel'],
            'invalid date' => [['reminder_date' => '2026-02-30'], 'reminder_date'],
            'missing date' => [['reminder_date' => null], 'reminder_date'],
            'invalid note' => [['note' => ['invalid']], 'note'],
        ];
    }

    #[DataProvider('invalidAttributes')]
    public function test_invalid_attributes_are_rejected_atomically(array $overrides, string $field): void
    {
        $invoice = $this->invoice();
        try {
            $this->service()->create($invoice, [...$this->attributes(), ...$overrides]);
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertDatabaseCount('customer_reminders', 0);
        }
    }

    public function test_blank_or_missing_note_is_normalized_to_null(): void
    {
        $invoice = $this->invoice();
        $this->assertNull($this->service()->create($invoice, [...$this->attributes(), 'note' => '  '])->note);
        $this->assertNull($this->service()->create($invoice, $this->attributes())->note);
    }

    public static function invalidStatuses(): array
    {
        return [['draft'], ['cancelled']];
    }

    #[DataProvider('invalidStatuses')]
    public function test_draft_and_cancelled_invoices_are_rejected(string $status): void
    {
        $invoice = $this->invoice(false);
        if ($status === 'cancelled') {
            app(InvoiceManagementService::class)->cancelDraft($invoice);
        }
        $this->assertInvalidInvoice($invoice);
        $this->assertSame(0, $this->service()->overdueDays($invoice->fresh()));
    }

    public function test_nonexistent_invoice_is_rejected(): void
    {
        $invoice = new Invoice;
        $invoice->id = 999999;
        $this->expectException(ModelNotFoundException::class);
        try {
            $this->service()->create($invoice, $this->attributes());
        } finally {
            $this->assertDatabaseCount('customer_reminders', 0);
        }
    }

    public function test_invoice_is_reloaded_instead_of_trusting_mutated_customer_and_status(): void
    {
        $invoice = $this->invoice(false);
        $invoice->status = Invoice::STATUS_ISSUED;
        $invoice->customer_id = 999999;
        $this->assertInvalidInvoice($invoice);
    }

    public function test_history_and_latest_reminder_use_date_then_id_including_eager_loading(): void
    {
        $invoice = $this->invoice();
        $first = $this->service()->create($invoice, $this->attributes());
        $second = $this->service()->create($invoice, $this->attributes());
        $older = $this->service()->create($invoice, [...$this->attributes(), 'reminder_date' => '2026-10-01']);
        $this->assertSame([$second->id, $first->id, $older->id], $invoice->reminders()->pluck('id')->all());
        $this->assertSame($second->id, $invoice->latestReminder->id);
        $loaded = Invoice::query()->with(['reminders', 'latestReminder'])->findOrFail($invoice->id);
        $this->assertSame([$second->id, $first->id, $older->id], $loaded->reminders->modelKeys());
        $this->assertSame($second->id, $loaded->latestReminder->id);
        $this->assertDatabaseCount('customer_reminders', 3);
    }

    public static function dueDates(): array
    {
        return [['2026-10-04', 1], ['2026-10-05', 0], ['2026-10-06', 0], [null, 0], ['2026-09-30', 5]];
    }

    #[DataProvider('dueDates')]
    public function test_overdue_days_follow_due_date(?string $dueDate, int $expected): void
    {
        $invoice = $this->invoice(true, $dueDate);
        $this->assertSame($expected, $this->service()->overdueDays($invoice));
    }

    public function test_partial_payment_reuses_remaining_amount_and_keeps_invoice_overdue(): void
    {
        $invoice = $this->invoice(true, '2026-10-04');
        $this->pay($invoice, '40.00');
        $payments = app(PaymentManagementService::class);
        $this->assertSame('80.00', $payments->remainingAmount($invoice));
        $this->assertSame('overdue', $payments->paymentState($invoice));
        $this->assertSame(1, $this->service()->overdueDays($invoice));
        $this->service()->create($invoice, $this->attributes());
        $this->assertDatabaseCount('customer_reminders', 1);
    }

    public function test_paid_invoice_rejects_new_reminder_and_preserves_existing_history(): void
    {
        $invoice = $this->invoice(true, '2026-10-04');
        $reminder = $this->service()->create($invoice, $this->attributes());
        $this->pay($invoice, '120.00');
        $this->assertSame('paid', app(PaymentManagementService::class)->paymentState($invoice));
        $this->assertSame(0, $this->service()->overdueDays($invoice));
        $this->assertInvalidInvoice($invoice, 1);
        $this->assertTrue($invoice->reminders()->first()->is($reminder));
    }

    public function test_remaining_amount_is_delegated_to_payment_service(): void
    {
        $invoice = $this->invoice(true, '2026-10-04');
        $payments = $this->mock(PaymentManagementService::class);
        $payments->shouldReceive('remainingAmount')->twice()->withArgs(fn (Invoice $value) => $value->is($invoice))->andReturn('0.00');
        $service = app(CustomerReminderManagementService::class);
        $this->assertSame(0, $service->overdueDays($invoice));
        $this->expectException(ValidationException::class);
        $service->create($invoice, $this->attributes());
    }

    public function test_creation_does_not_modify_invoice_payments_allocations_or_stock(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '40.00');
        $tables = ['invoices', 'invoice_items', 'payments', 'payment_allocations', 'warehouse_stocks', 'stock_movements'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->service()->create($invoice, $this->attributes());
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_history_cannot_be_updated_or_deleted_through_model(): void
    {
        $reminder = $this->service()->create($this->invoice(), $this->attributes());
        foreach (['update', 'delete'] as $action) {
            try {
                $action === 'update' ? $reminder->update(['note' => 'Changed']) : $reminder->delete();
                $this->fail('Expected immutable history.');
            } catch (LogicException) {
                $this->assertDatabaseCount('customer_reminders', 1);
                $this->assertNull($reminder->fresh()->note);
            }
        }
    }

    private function attributes(): array
    {
        return ['reminder_date' => '2026-10-04', 'channel' => CustomerReminder::CHANNEL_EMAIL];
    }

    private function service(): CustomerReminderManagementService
    {
        return app(CustomerReminderManagementService::class);
    }

    private function assertInvalidInvoice(Invoice $invoice, int $expectedCount = 0): void
    {
        try {
            $this->service()->create($invoice, $this->attributes());
            $this->fail('Expected invoice rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice_id', $exception->errors());
            $this->assertDatabaseCount('customer_reminders', $expectedCount);
        }
    }
}
