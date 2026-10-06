<?php

namespace App\Models;

use App\Models\Concerns\RecordsOperations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

class CreditNote extends Model
{
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    use RecordsOperations;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'invoice_id',
        'customer_id',
        'credit_date',
        'reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'credit_date' => 'date',
            'subtotal_ht' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CreditNote $creditNote): void {
            $originalStatus = $creditNote->getOriginal('status');
            $targetStatus = $creditNote->status;

            if ($originalStatus === self::STATUS_CANCELLED) {
                throw new LogicException(__('Un avoir annulé est immuable.'));
            }

            if ($originalStatus === self::STATUS_ISSUED) {
                if ($targetStatus !== self::STATUS_ISSUED) {
                    throw new LogicException(
                        __('Un avoir émis ne peut plus changer de statut.')
                    );
                }

                if (
                    array_diff(
                        array_keys($creditNote->getDirty()),
                        ['updated_at']
                    ) !== []
                ) {
                    throw new LogicException(
                        __('Le contenu d’un avoir émis est immuable.')
                    );
                }

                return;
            }

            if ($originalStatus === self::STATUS_DRAFT
                && ! in_array($targetStatus, [self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_CANCELLED], true)) {
                throw new LogicException(__('Cette transition d’avoir est interdite.'));
            }
        });

        static::deleting(function (): never {
            throw new LogicException(__('Un avoir ne peut pas être supprimé physiquement.'));
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
        return $this->hasMany(CreditNoteItem::class)->orderBy('position');
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
