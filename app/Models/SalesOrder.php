<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SalesOrder extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    // Delivery statuses are reserved for the future delivery-note workflow.
    public const STATUS_PARTIALLY_DELIVERED = 'partially_delivered';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['customer_id', 'order_date', 'notes', 'terms'];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'subtotal_ht' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SalesOrder $order): void {
            $originalStatus = $order->getOriginal('status');
            $targetStatus = $order->status;

            if ($originalStatus === self::STATUS_DRAFT && $targetStatus === self::STATUS_DRAFT) {
                if ($order->getOriginal('archived_at') !== null) {
                    throw new LogicException(__('Une commande archivée ne peut pas être modifiée.'));
                }

                return;
            }

            $transitions = [
                self::STATUS_DRAFT => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
                self::STATUS_CONFIRMED => [self::STATUS_CANCELLED],
            ];
            $statusChanged = $order->isDirty('status');

            if ($statusChanged && ! in_array($targetStatus, $transitions[$originalStatus] ?? [], true)) {
                throw new LogicException(__('Cette transition de commande est interdite.'));
            }

            $timestampField = $statusChanged ? match ($targetStatus) {
                self::STATUS_CONFIRMED => 'confirmed_at',
                self::STATUS_CANCELLED => 'cancelled_at',
                default => null,
            } : null;
            $allowed = ['status', 'updated_at'];
            if ($timestampField !== null) {
                $allowed[] = $timestampField;
            }

            if (array_diff(array_keys($order->getDirty()), $allowed) !== []) {
                throw new LogicException(__('Le contenu commercial d’une commande confirmée est immuable.'));
            }
        });

        static::deleting(function (): never {
            throw new LogicException(__('Une commande ne peut pas être supprimée physiquement.'));
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sourceQuote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'source_quote_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class)->orderBy('position');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SalesOrderHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class)->orderBy('created_at');
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT && $this->archived_at === null;
    }

    public function baseHt(): string
    {
        return (string) BigDecimal::of($this->subtotal_ht)->minus($this->discount_total)->toScale(2);
    }

    public function remainingQuantity(SalesOrderItem $item): string
    {
        return (string) BigDecimal::of($item->ordered_quantity)->minus($item->delivered_quantity)->toScale(3);
    }
}
