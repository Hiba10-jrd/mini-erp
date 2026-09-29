<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DeliveryNoteItem extends Model
{
    protected $fillable = [
        'delivery_note_id',
        'sales_order_item_id',
        'product_id',
        'item_type',
        'reference',
        'description',
        'unit_label',
        'quantity',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DeliveryNoteItem $item): void {
            if ($item->quantity === null || (float) $item->quantity <= 0) {
                throw new LogicException(__('Une ligne de bon de livraison doit avoir une quantité positive.'));
            }
        });
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
