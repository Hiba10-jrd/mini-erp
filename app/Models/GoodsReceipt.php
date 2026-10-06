<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

class GoodsReceipt extends Model
{
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['purchase_order_id', 'warehouse_id', 'receipt_date', 'notes'];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'validated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (GoodsReceipt $receipt): void {
            $originalStatus = $receipt->getOriginal('status');
            $targetStatus = $receipt->status;

            if ($originalStatus === self::STATUS_DRAFT && $targetStatus === self::STATUS_DRAFT) {
                return;
            }

            if ($originalStatus !== self::STATUS_DRAFT || ! in_array($targetStatus, [self::STATUS_VALIDATED, self::STATUS_CANCELLED], true)) {
                throw new LogicException(__('Cette transition de réception fournisseur est interdite.'));
            }

            $allowed = ['status', 'updated_at'];
            if ($targetStatus === self::STATUS_VALIDATED) {
                array_push($allowed, 'validated_by', 'validated_at');
            } else {
                $allowed[] = 'cancelled_at';
            }

            if (array_diff(array_keys($receipt->getDirty()), $allowed) !== []) {
                throw new LogicException(__('Une réception fournisseur validée ou annulée est immuable.'));
            }
        });

        static::deleting(function (): never {
            throw new LogicException(__('Une réception fournisseur ne peut pas être supprimée physiquement.'));
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
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
        return $this->hasMany(GoodsReceiptItem::class)->orderBy('position');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(GoodsReceiptHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
