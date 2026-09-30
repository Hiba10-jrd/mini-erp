<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Invoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'sales_order_id',
        'customer_id',
        'invoice_date',
        'due_date',
        'notes',
        'terms',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal_ht' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'payment_term_days' => 'integer',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            $originalStatus = $invoice->getOriginal('status');
            $targetStatus = $invoice->status;

            if ($originalStatus === self::STATUS_CANCELLED) {
                throw new LogicException(__('Une facture annulée est immuable.'));
            }

            if ($originalStatus === self::STATUS_ISSUED) {
                if ($targetStatus !== self::STATUS_ISSUED) {
                    throw new LogicException(__('Une facture émise ne peut plus changer de statut.'));
                }

                if (array_diff(array_keys($invoice->getDirty()), ['updated_at']) !== []) {
                    throw new LogicException(__('Le contenu d’une facture émise est immuable.'));
                }

                return;
            }

            if ($originalStatus === self::STATUS_DRAFT
                && ! in_array($targetStatus, [self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_CANCELLED], true)) {
                throw new LogicException(__('Cette transition de facture est interdite.'));
            }
        });

        static::deleting(function (): never {
            throw new LogicException(__('Une facture ne peut pas être supprimée physiquement.'));
        });
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
