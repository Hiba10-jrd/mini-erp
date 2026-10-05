<?php

namespace Tests\Support;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Database\Seeders\RbacSeeder;

trait ReportFixtures
{
    use CustomerReminderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 0));
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->first());
        $this->actingAs($user);
    }

    private function credit(Invoice $invoice, string $amount, string $status = 'issued', string $date = '2026-10-05'): CreditNote
    {
        return CreditNote::query()->forceCreate(['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'customer_name' => $invoice->customer_name, 'status' => $status, 'credit_date' => $date, 'subtotal_ht' => $amount, 'discount_total' => '0.00', 'tax_total' => '0.00', 'total_ttc' => $amount]);
    }

    private function recordedPayment(Invoice $invoice, string $amount, string $date = '2026-10-05'): Payment
    {
        $method = PaymentMethod::query()->create(['name' => 'Report method '.str()->random(8), 'is_active' => true]);
        $payment = Payment::query()->create(['customer_id' => $invoice->customer_id, 'payment_method_id' => $method->id, 'payment_date' => $date, 'amount' => $amount]);
        PaymentAllocation::query()->create(['invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'amount' => $amount]);

        return $payment;
    }

    private function supplierInvoice(string $status = 'validated'): SupplierInvoice
    {
        $supplier = Supplier::query()->forceCreate(['code' => 'SUP-'.str()->random(8), 'name' => 'Report supplier', 'status' => 'active']);
        $order = PurchaseOrder::query()->forceCreate(['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'number' => 'PO-'.str()->random(8), 'order_date' => today(), 'subtotal_ht' => '100.00', 'total_ttc' => '120.00']);

        return SupplierInvoice::query()->forceCreate(['purchase_order_id' => $order->id, 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'supplier_invoice_number' => 'EXT-'.str()->random(8), 'status' => $status, 'invoice_date' => today(), 'due_date' => today()->subDay(), 'subtotal_ht' => '100.00', 'discount_total' => '10.00', 'tax_total' => '18.00', 'total_ttc' => '108.00']);
    }
}
