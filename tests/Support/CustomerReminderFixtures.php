<?php

namespace Tests\Support;

use App\Models\Company;
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

trait CustomerReminderFixtures
{
    private function pay(Invoice $invoice, string $amount): void
    {
        $method = PaymentMethod::query()->create(['name' => 'Virement test', 'is_active' => true, 'sort_order' => 0]);
        app(PaymentManagementService::class)->create([
            'customer_id' => $invoice->customer_id, 'payment_method_id' => $method->id,
            'payment_date' => today()->toDateString(), 'amount' => $amount,
        ], [['invoice_id' => $invoice->id, 'amount' => $amount]]);
    }

    // Same real order -> validated delivery -> invoice workflow as InvoiceManagementTest.
    private function invoice(bool $issue = true, ?string $dueDate = '2026-11-04'): Invoice
    {
        Company::query()->firstOrCreate(['singleton' => true], ['legal_name' => 'Société test', 'address' => 'Rabat']);
        $term = PaymentTerm::query()->firstOrCreate(['label' => 'Net 30'], ['due_days' => 30, 'is_active' => true]);
        $customer = Customer::query()->forceCreate([
            'code' => 'CLI-'.str()->random(8), 'customer_type' => 'company', 'name' => 'Client test',
            'address' => 'Rabat', 'payment_term_id' => $term->id, 'status' => 'active',
        ]);
        $tax = TaxRate::query()->firstOrCreate(['label' => 'TVA test'], ['rate' => '20.00', 'is_active' => true]);
        $unit = Unit::query()->firstOrCreate(['symbol' => 'pce'], ['name' => 'Pièce']);
        $product = Product::query()->forceCreate([
            'type' => 'product', 'reference' => 'PROD-'.str()->random(8), 'name' => 'Article test',
            'unit_id' => $unit->id, 'selling_price' => '100.00', 'purchase_price' => '0.00',
            'tax_rate_id' => $tax->id, 'is_active' => true,
        ]);
        foreach (['order' => 'CMD', 'delivery_note' => 'BL', 'invoice' => 'FAC'] as $type => $prefix) {
            DocumentSequence::query()->firstOrCreate(['document_type' => $type, 'year' => today()->year], [
                'prefix' => $prefix,
                'counter' => 0, 'number_format' => '{prefix}-{year}-{counter:05d}',
            ]);
        }
        $orders = app(SalesOrderManagementService::class);
        $order = $orders->create(['customer_id' => $customer->id, 'order_date' => today()->toDateString()], [[
            'product_id' => $product->id, 'ordered_quantity' => '1.000', 'unit_price' => '100.00',
            'discount_percent' => '0.00', 'tax_rate_id' => (string) $tax->id,
        ]]);
        $order = $orders->transition($order, SalesOrder::STATUS_CONFIRMED);
        $warehouse = Warehouse::query()->firstOrCreate(['code' => 'WH-TEST'], ['name' => 'Dépôt test', 'is_active' => true]);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => '100.000']);
        $deliveries = app(DeliveryNoteManagementService::class);
        $delivery = $deliveries->createForOrder($order, ['warehouse_id' => $warehouse->id, 'delivery_date' => today()->toDateString()]);
        $deliveries->validate($delivery);
        $invoices = app(InvoiceManagementService::class);
        $invoice = $invoices->createForOrder($order->fresh());
        // Set the due date while still draft; issued invoices remain immutable.
        $invoice->update(['due_date' => $dueDate]);

        return $issue ? $invoices->issue($invoice) : $invoice;
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'Reminder test '.$user->id, 'slug' => 'reminder-test-'.$user->id]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }
}
