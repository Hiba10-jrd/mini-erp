<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class Quote extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'customer_id',
        'quote_date',
        'valid_until',
        'notes',
        'terms',
    ];

    protected function casts(): array
    {
        return [
            'quote_date' => 'date',
            'valid_until' => 'date',
            'subtotal_ht' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'refused_at' => 'datetime',
            'expired_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Quote $quote): void {
            $transitions = [
                self::STATUS_DRAFT => [self::STATUS_SENT],
                self::STATUS_SENT => [self::STATUS_ACCEPTED, self::STATUS_REFUSED, self::STATUS_EXPIRED],
                self::STATUS_ACCEPTED => [self::STATUS_DRAFT],
                self::STATUS_REFUSED => [self::STATUS_ACCEPTED],
                self::STATUS_EXPIRED => [self::STATUS_SENT],
            ];
            $originalStatus = $quote->getOriginal('status');
            $targetStatus = $quote->status;
            $customerSnapshotFields = [
                'customer_name',
                'customer_trade_name',
                'customer_address',
                'customer_city',
                'customer_country',
                'customer_email',
                'customer_phone',
                'customer_ice',
                'customer_tax_id',
                'customer_commercial_register',
            ];

            if ($quote->isDirty($customerSnapshotFields)) {
                throw new LogicException(__('Les coordonnées client d’un devis sont immuables.'));
            }

            if ($originalStatus === self::STATUS_DRAFT && $targetStatus === self::STATUS_DRAFT) {
                if ($quote->getOriginal('archived_at') !== null) {
                    throw new LogicException(__('Un devis archivé ne peut pas être modifié.'));
                }

                return;
            }

            $statusChanged = $quote->isDirty('status');

            if ($statusChanged && ! in_array($targetStatus, $transitions[$originalStatus] ?? [], true)) {
                throw new LogicException(__('Cette transition de statut est interdite.'));
            }

            $timestampField = $statusChanged ? match ($targetStatus) {
                self::STATUS_SENT => 'sent_at',
                self::STATUS_ACCEPTED => 'accepted_at',
                self::STATUS_REFUSED => 'refused_at',
                self::STATUS_EXPIRED => 'expired_at',
                default => null,
            } : null;
            $allowed = ['status', 'updated_at'];
            if ($timestampField !== null) {
                $allowed[] = $timestampField;
            }

            if (array_diff(array_keys($quote->getDirty()), $allowed) !== []) {
                throw new LogicException(__('Le contenu commercial d’un devis envoyé est immuable.'));
            }

            if ($quote->archived_at !== null) {
                throw new LogicException(__('Un devis archivé ne peut pas être modifié.'));
            }
        });

        static::deleting(function (): never {
            throw new LogicException(__('Un devis ne peut pas être supprimé physiquement.'));
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position');
    }

    public function salesOrder(): HasOne
    {
        return $this->hasOne(SalesOrder::class, 'source_quote_id');
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT && $this->archived_at === null;
    }

    public function baseHt(): string
    {
        return (string) BigDecimal::of($this->subtotal_ht)->minus($this->discount_total)->toScale(2);
    }
}
