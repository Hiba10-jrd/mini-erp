<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

class SupplierInvoice extends Model
{
    public const ORIGINAL_DOCUMENT_CATEGORY = 'Facture originale fournisseur';

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public const STATUS_DRAFT = 'draft';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_DISPUTED = 'disputed';

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
            'lock_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SupplierInvoice $invoice): void {
            $originalStatus = $invoice->getOriginal('status');
            $targetStatus = $invoice->status;

            if (in_array($originalStatus, [self::STATUS_DRAFT, self::STATUS_DISPUTED], true) && $targetStatus === $originalStatus) {
                return;
            }

            $statusChanged = $invoice->isDirty('status');
            $transitions = [
                self::STATUS_DRAFT => [self::STATUS_UNDER_REVIEW, self::STATUS_CANCELLED],
                self::STATUS_DISPUTED => [self::STATUS_UNDER_REVIEW],
                self::STATUS_UNDER_REVIEW => [self::STATUS_VALIDATED, self::STATUS_DISPUTED],
            ];
            if (! $statusChanged || ! in_array($targetStatus, $transitions[$originalStatus] ?? [], true)) {
                throw new LogicException(__('Cette transition de facture fournisseur est interdite.'));
            }

            $allowed = ['status', 'updated_at', 'lock_version'];
            if ($targetStatus === self::STATUS_VALIDATED) {
                array_push($allowed, 'number', 'validated_by', 'validated_at');
            } elseif ($targetStatus === self::STATUS_CANCELLED) {
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
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_DISPUTED], true);
    }

    public function reconciliation(): HasOne
    {
        return $this->hasOne(SupplierInvoiceReconciliation::class);
    }

    /** Historical invoices keep their original debt. Only validated invoices are payable. */
    public function payableAmount(): string
    {
        return $this->status === self::STATUS_VALIDATED
            ? ($this->reconciliation?->total_ttc ?? $this->total_ttc)
            : '0.00';
    }

    public static function payableSql(string $field = 'total_ttc'): string
    {
        if (! in_array($field, ['net_ht', 'tax_total', 'total_ttc'], true)) {
            throw new \InvalidArgumentException('Invalid payable field');
        }
        $fallback = $field === 'net_ht' ? '(supplier_invoices.subtotal_ht - supplier_invoices.discount_total)' : 'supplier_invoices.'.$field;

        return "COALESCE((SELECT sir.{$field} FROM supplier_invoice_reconciliations sir WHERE sir.supplier_invoice_id = supplier_invoices.id), {$fallback})";
    }

    public function baseHt(): string
    {
        return (string) BigDecimal::of($this->subtotal_ht)->minus($this->discount_total)->toScale(2);
    }
}
