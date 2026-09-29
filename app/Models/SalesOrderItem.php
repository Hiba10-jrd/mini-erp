<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SalesOrderItem extends Model
{
    protected $fillable = [
        'product_id',
        'item_type',
        'reference',
        'description',
        'unit_label',
        'ordered_quantity',
        'delivered_quantity',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'tax_rate_id',
        'tax_rate_percent',
        'subtotal_ht',
        'tax_amount',
        'total_ttc',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:3',
            'delivered_quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate_percent' => 'decimal:2',
            'subtotal_ht' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SalesOrderItem $item): void {
            $order = $item->salesOrder()->first();
            $delivered = BigDecimal::of((string) ($item->delivered_quantity ?? '0'))->toScale(3);

            if ($delivered->isLessThan(0)) {
                throw new LogicException(__('La quantité livrée ne peut pas être négative.'));
            }

            if ($order?->status === SalesOrder::STATUS_DRAFT && $delivered->isGreaterThan(0)) {
                throw new LogicException(__('Une commande brouillon ne peut pas avoir de quantité livrée.'));
            }

            if ($item->exists && $item->isDirty('delivered_quantity')) {
                throw new LogicException(__('La quantité livrée sera modifiée par le bon de livraison.'));
            }

            if ($order !== null && ! $order->isEditable()) {
                if (array_diff(array_keys($item->getDirty()), ['updated_at']) !== []) {
                    throw new LogicException(__('Les lignes commerciales d’une commande confirmée sont immuables.'));
                }

                if ($delivered->isGreaterThan(BigDecimal::of($item->ordered_quantity))) {
                    throw new LogicException(__('La quantité livrée ne peut pas dépasser la quantité commandée.'));
                }
            }
        });

        static::deleting(function (SalesOrderItem $item): void {
            $order = $item->salesOrder()->first();
            if ($order !== null && ! $order->isEditable()) {
                throw new LogicException(__('Les lignes d’une commande non modifiable sont immuables.'));
            }
        });
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
