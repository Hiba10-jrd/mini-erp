<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class GoodsReceiptItem extends Model
{
    protected $fillable = [
        'purchase_order_item_id',
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
        return ['quantity' => 'decimal:3', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (GoodsReceiptItem $item): void {
            if ($item->quantity === null || (float) $item->quantity <= 0) {
                throw new LogicException(__('Une ligne de réception doit avoir une quantité positive.'));
            }

            $receipt = $item->goodsReceipt()->first();
            if ($receipt !== null && ! $receipt->isEditable()) {
                throw new LogicException(__('Les lignes d’une réception non modifiable sont immuables.'));
            }
        });

        static::deleting(function (GoodsReceiptItem $item): void {
            $receipt = $item->goodsReceipt()->first();
            if ($receipt !== null && ! $receipt->isEditable()) {
                throw new LogicException(__('Les lignes d’une réception non modifiable sont immuables.'));
            }
        });
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
