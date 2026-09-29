<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class DeliveryNote extends Model
{
    protected $fillable = [
        'number',
        'sales_order_id',
        'warehouse_id',
        'status',
        'delivery_date',
        'notes',
        'created_by',
        'validated_by',
        'validated_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'validated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (DeliveryNote $deliveryNote): void {
            $originalStatus = $deliveryNote->getOriginal('status');
            $targetStatus = $deliveryNote->status;

            if ($originalStatus === 'validated' && $targetStatus !== 'validated') {
                throw new LogicException(__('Un bon de livraison validé est immutable.'));
            }

            if ($originalStatus === 'cancelled' && $targetStatus !== 'cancelled') {
                throw new LogicException(__('Un bon de livraison annulé ne peut pas être réactivé.'));
            }

            if ($originalStatus === 'draft' && ! in_array($targetStatus, ['draft', 'validated', 'cancelled'], true)) {
                throw new LogicException(__('Transition de bon de livraison interdite.'));
            }
        });
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
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
        return $this->hasMany(DeliveryNoteItem::class)->orderBy('position');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}
