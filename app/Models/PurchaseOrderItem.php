<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'product_id',
        'tax_rate_id',
        'item_type',
        'reference',
        'description',
        'unit_label',
        'quantity',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'tax_rate_percent',
        'subtotal_ht',
        'tax_amount',
        'total_ttc',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
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
        static::saving(function (PurchaseOrderItem $item): void {
            $order = $item->purchaseOrder()->first();
            if ($order !== null && ! $order->isEditable()) {
                throw new LogicException(__('Les lignes d’une commande fournisseur non modifiable sont immuables.'));
            }
        });

        static::deleting(function (PurchaseOrderItem $item): void {
            $order = $item->purchaseOrder()->first();
            if ($order !== null && ! $order->isEditable()) {
                throw new LogicException(__('Les lignes d’une commande fournisseur non modifiable sont immuables.'));
            }
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
