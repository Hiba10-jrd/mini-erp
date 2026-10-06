<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class AttachmentAuthorizationService
{
    public const PARENTS = [
        'invoice' => Invoice::class, 'credit-note' => CreditNote::class,
        'payment' => Payment::class, 'supplier-invoice' => SupplierInvoice::class,
        'supplier-payment' => SupplierPayment::class, 'expense' => Expense::class,
        'goods-receipt' => GoodsReceipt::class, 'purchase-order' => PurchaseOrder::class,
    ];

    public function resolve(string $type, int $id): Model
    {
        abort_unless(isset(self::PARENTS[$type]), 404);

        return self::PARENTS[$type]::query()->findOrFail($id);
    }

    public function permissions(Model $parent): array
    {
        abort_unless(in_array($parent::class, self::PARENTS, true) && $parent->exists, 404);

        return match ($parent::class) {
            Invoice::class, CreditNote::class => ['invoices.view', 'invoices.create'],
            Payment::class, SupplierPayment::class, Expense::class => ['payments.view', 'payments.create'],
            default => ['purchases.view', 'purchases.update'],
        };
    }

    public function authorize(Model $parent, bool $write = false): void
    {
        [$read, $add] = $this->permissions($parent);
        Gate::authorize($read);
        if ($write) {
            Gate::authorize($add);
        }
    }

    public function canDelete(Model $parent): bool
    {
        return method_exists($parent, 'isEditable') && $parent->isEditable();
    }
}
