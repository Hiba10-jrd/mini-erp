<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

class SupplierInvoice extends Model
{
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'purchase_order_id',
        'supplier_id',
        'supplier_invoice_number',
        'invoice_date',
        'due_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'payment_term_days' => 'integer',
            'subtotal_ht' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'validated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SupplierInvoice $invoice): void {
            $originalStatus = $invoice->getOriginal('status');
            $targetStatus = $invoice->status;

            if ($originalStatus === self::STATUS_DRAFT && $targetStatus === self::STATUS_DRAFT) {
                return;
            }

            $statusChanged = $invoice->isDirty('status');
            if (! $statusChanged || $originalStatus !== self::STATUS_DRAFT || ! in_array($targetStatus, [self::STATUS_VALIDATED, self::STATUS_CANCELLED], true)) {
                throw new LogicException(__('Cette transition de facture fournisseur est interdite.'));
            }

            $allowed = ['status', 'updated_at'];
            if ($targetStatus === self::STATUS_VALIDATED) {
                array_push($allowed, 'number', 'subtotal_ht', 'discount_total', 'tax_total', 'total_ttc', 'validated_by', 'validated_at');
            } else {
                $allowed[] = 'cancelled_at';
            }

            if (array_diff(array_keys($invoice->getDirty()), $allowed) !== []) {
                throw new LogicException(__('Le contenu commercial d’une facture fournisseur validée ou annulée est immuable.'));
            }
        });

        static::deleting(function (): never {
            throw new LogicException(__('Une facture fournisseur ne peut pas être supprimée physiquement.'));
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierInvoiceItem::class)->orderBy('position');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SupplierInvoiceHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function baseHt(): string
    {
        return (string) BigDecimal::of($this->subtotal_ht)->minus($this->discount_total)->toScale(2);
    }
}
