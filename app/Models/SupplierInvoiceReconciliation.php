<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SupplierInvoiceReconciliation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['net_ht' => 'decimal:2', 'tax_total' => 'decimal:2', 'total_ttc' => 'decimal:2', 'breakdown' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $reconciliation): void {
            if (! $reconciliation->supplierInvoice()->firstOrFail()->isEditable()) {
                throw new LogicException(__('Le rapprochement est verrouillé.'));
            }
            if ($reconciliation->exists && $reconciliation->isDirty('supplier_invoice_id')) {
                throw new LogicException(__('Le rapprochement est verrouillé.'));
            }
        });
        static::deleting(function (): never {
            throw new LogicException(__('Le rapprochement est verrouillé.'));
        });
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
