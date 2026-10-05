<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Services\CustomerReminderManagementService;
use App\Services\InvoiceManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\Support\CustomerReminderFixtures;
use Tests\TestCase;

class CustomerReminderUiTest extends TestCase
{
    use CustomerReminderFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 0));
        $this->seed(RbacSeeder::class);
    }

    private function operator(): void
    {
        $this->actingAs($this->userWithPermissions(['sales.create', 'sales.update', 'invoices.create', 'invoices.validate', 'payments.view', 'payments.create', 'invoices.view']));
    }

    public function test_route_and_direct_component_require_view_permission(): void
    {
        $this->get(route('finance.receivables.index'))->assertRedirect(route('login'));
        $this->actingAs($this->userWithPermissions(['invoices.view']));
        $this->get(route('finance.receivables.index'))->assertForbidden();
        Volt::test('admin.receivables-manager')->assertForbidden();
        $this->actingAs($this->userWithPermissions(['payments.view']));
        $this->get(route('finance.receivables.index'))->assertOk()->assertSeeVolt('admin.receivables-manager');
    }

    public function test_navigation_links_require_payment_view(): void
    {
        $this->actingAs($this->userWithPermissions(['payments.view']));
        $response = $this->get(route('dashboard'))->assertOk()->assertSee('Créances clients');
        $this->assertSame(2, substr_count($response->getContent(), 'href="'.route('finance.receivables.index').'"'));
        $this->actingAs($this->userWithPermissions(['invoices.view']));
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Créances clients');
    }

    public function test_default_list_excludes_paid_draft_and_cancelled_invoices(): void
    {
        $this->operator();
        $open = $this->invoice();
        $paid = $this->invoice();
        $this->pay($paid, '120.00');
        $draft = $this->invoice(false);
        $cancelled = $this->invoice(false);
        app(InvoiceManagementService::class)->cancelDraft($cancelled);
        Volt::test('admin.receivables-manager')->assertSee($open->number)->assertDontSee($paid->number)
            ->assertViewHas('receivables', fn ($rows) => $rows->total() === 1)
            ->set('paymentFilter', 'paid')->assertSee($paid->number)->assertDontSee($open->number)
            ->assertViewHas('receivables', fn ($rows) => $rows->total() === 1);
    }

    public function test_payment_and_due_filters_remain_independent(): void
    {
        $this->operator();
        $unpaid = $this->invoice();
        $partial = $this->invoice(true, '2026-10-04');
        $this->pay($partial, '40.00');
        $noDate = $this->invoice(true, null);
        Volt::test('admin.receivables-manager')->set('paymentFilter', 'partially_paid')->assertSee($partial->number)->assertDontSee($unpaid->number)
            ->set('dueFilter', 'overdue')->assertSee($partial->number)->assertViewHas('receivables', fn ($rows) => $rows->total() === 1)
            ->call('resetFilters')->set('paymentFilter', 'unpaid')->assertSee($unpaid->number)->assertDontSee($partial->number)
            ->set('dueFilter', 'upcoming')->assertSee($unpaid->number)->assertDontSee($noDate->number)
            ->set('dueFilter', 'no_due_date')->assertSee($noDate->number)->assertDontSee($unpaid->number);
    }

    public function test_customer_search_and_date_range_filters(): void
    {
        $this->operator();
        $first = $this->invoice(true, '2026-10-10');
        $second = $this->invoice(true, '2026-10-20');
        $first->customer->update(['name' => 'Atlas client']);
        Volt::test('admin.receivables-manager')->set('customerFilter', (string) $first->customer_id)->assertSee($first->number)->assertDontSee($second->number)
            ->call('resetFilters')->set('search', $second->number)->assertSee($second->number)->assertDontSee($first->number)
            ->set('search', 'Atlas client')->assertSee($first->number)->assertDontSee($second->number)
            ->set('search', $first->customer->code)->assertSee($first->number)->assertDontSee($second->number)
            ->call('resetFilters')->set('dateFrom', '2026-10-10')->set('dateTo', '2026-10-10')->assertSee($first->number)->assertDontSee($second->number);
    }

    public function test_kpis_use_remaining_amount_and_follow_filters(): void
    {
        $this->operator();
        $overdue = $this->invoice(true, '2026-10-04');
        $this->pay($overdue, '40.00');
        $this->invoice();
        Volt::test('admin.receivables-manager')
            ->assertViewHas('summary', ['remaining' => '200.00', 'overdue' => '80.00', 'overdueCount' => 1, 'openCount' => 2])
            ->set('dueFilter', 'overdue')
            ->assertViewHas('summary', ['remaining' => '80.00', 'overdue' => '80.00', 'overdueCount' => 1, 'openCount' => 1]);
    }

    public function test_reminder_action_requires_create_permission_even_when_called_directly(): void
    {
        $this->operator();
        $invoice = $this->invoice();
        $this->actingAs($this->userWithPermissions(['payments.view']));
        Volt::test('admin.receivables-manager')->assertDontSee('Relancer')->call('openReminder', $invoice->id)->assertForbidden();
        Volt::test('admin.receivables-manager')->call('saveReminder')->assertForbidden();
        $this->assertDatabaseCount('customer_reminders', 0);
    }

    public function test_kpis_account_for_issued_credit_notes(): void
    {
        $this->operator();
        $invoice = $this->invoice(true, '2026-10-04');
        $this->pay($invoice, '40.00');
        // Same credit fixture convention as PaymentManagementTest.
        CreditNote::query()->forceCreate([
            'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id,
            'status' => CreditNote::STATUS_ISSUED, 'credit_date' => today(),
            'customer_name' => $invoice->customer_name, 'subtotal_ht' => '20.00',
            'discount_total' => '0.00', 'tax_total' => '4.00', 'total_ttc' => '24.00',
        ]);
        Volt::test('admin.receivables-manager')->assertViewHas('summary', [
            'remaining' => '56.00', 'overdue' => '56.00', 'overdueCount' => 1, 'openCount' => 1,
        ])->assertSee('56,00 DH');
    }

    public function test_revoked_view_permission_is_checked_on_subsequent_renders(): void
    {
        $viewer = $this->userWithPermissions(['payments.view']);
        $this->actingAs($viewer);
        $component = Volt::test('admin.receivables-manager');
        $viewer->roles()->first()->permissions()->detach();
        $component->set('search', 'test')->assertForbidden();
    }

    public function test_reminder_can_be_saved_and_latest_reminder_updates(): void
    {
        $this->operator();
        $invoice = $this->invoice();
        $tables = ['invoices', 'payments', 'payment_allocations', 'warehouse_stocks', 'stock_movements'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        Volt::test('admin.receivables-manager')->assertSee('Relancer')->call('openReminder', $invoice->id)
            ->assertSet('reminderDate', '2026-10-05')->set('channel', 'phone')->set('note', ' Appel effectué ')
            ->set('reminderDate', '2026-10-03')->call('saveReminder')->assertHasNoErrors()
            ->assertSet('selectedInvoiceId', null)->assertSet('note', '')->assertSee('La relance a été enregistrée.')
            ->assertSee('03/10/2026')->assertSee('phone');
        $this->assertDatabaseHas('customer_reminders', ['invoice_id' => $invoice->id, 'channel' => 'phone', 'note' => 'Appel effectué', 'created_by' => auth()->id()]);
        $this->assertSame('2026-10-03', $invoice->reminders()->first()->reminder_date->toDateString());
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_invalid_channel_and_date_are_displayed_in_form(): void
    {
        $this->operator();
        $invoice = $this->invoice();
        Volt::test('admin.receivables-manager')->call('openReminder', $invoice->id)->set('channel', 'sms')->call('saveReminder')
            ->assertHasErrors(['channel'])->set('channel', 'email')->set('reminderDate', 'invalid')->call('saveReminder')->assertHasErrors(['reminder_date']);
        $this->assertDatabaseCount('customer_reminders', 0);
    }

    public function test_invoice_paid_between_open_and_save_is_rejected(): void
    {
        $this->operator();
        $invoice = $this->invoice();
        $component = Volt::test('admin.receivables-manager')->call('openReminder', $invoice->id);
        $this->pay($invoice, '120.00');
        $component->call('saveReminder')->assertHasErrors(['invoice_id'])->assertSee('Une facture soldée ne peut pas recevoir une nouvelle relance.');
        $this->assertDatabaseCount('customer_reminders', 0);
    }

    public function test_invoice_history_is_visible_in_date_and_id_order_after_payment(): void
    {
        $this->operator();
        $invoice = $this->invoice();
        $service = app(CustomerReminderManagementService::class);
        foreach (['2026-10-04' => 'Première relance', '2026-10-01' => 'Ancienne relance'] as $date => $note) {
            $service->create($invoice, ['reminder_date' => $date, 'channel' => 'email', 'note' => $note]);
        }
        $service->create($invoice, ['reminder_date' => '2026-10-04', 'channel' => 'phone', 'note' => 'Dernière relance']);
        $responsible = auth()->user()->name;
        $this->pay($invoice, '120.00');
        $this->actingAs($this->userWithPermissions(['invoices.view']));
        Volt::test('admin.invoice-details', ['invoiceId' => $invoice->id])->assertSee('Historique des relances')
            ->assertSeeInOrder(['Dernière relance', 'Première relance', 'Ancienne relance'])->assertSee($responsible)->assertSee('Téléphone')->assertDontSee('Relancer');
        $this->get(route('sales.invoices.show', $invoice))->assertOk()->assertSee('Dernière relance');
    }

    public function test_empty_history_has_a_clear_message(): void
    {
        $this->operator();
        Volt::test('admin.invoice-details', ['invoiceId' => $this->invoice()->id])->assertSee('Aucune relance enregistrée.');
    }

    public function test_pagination_and_filter_reset(): void
    {
        $this->operator();
        for ($index = 0; $index < 13; $index++) {
            $this->invoice();
        }
        Volt::test('admin.receivables-manager')->assertViewHas('receivables', fn ($rows) => $rows->total() === 13 && $rows->count() === 12)
            ->call('setPage', 2)->assertViewHas('receivables', fn ($rows) => $rows->currentPage() === 2 && $rows->count() === 1)
            ->set('search', 'Client test')->assertViewHas('receivables', fn ($rows) => $rows->currentPage() === 1)
            ->set('dueFilter', 'overdue')->call('resetFilters')->assertSet('search', '')->assertSet('dueFilter', 'all');
    }
}
