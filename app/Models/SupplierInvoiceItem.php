<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SupplierInvoiceItem extends Model
{
    protected $fillable = [
        'goods_receipt_item_id',
        'purchase_order_item_id',
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
        static::saving(function (SupplierInvoiceItem $item): void {
            $invoice = $item->supplierInvoice()->first();
            if ($invoice !== null && ! $invoice->isEditable()) {
                throw new LogicException(__('Les lignes d’une facture fournisseur non modifiable sont immuables.'));
            }
        });

        static::deleting(function (SupplierInvoiceItem $item): void {
            $invoice = $item->supplierInvoice()->first();
            if ($invoice !== null && ! $invoice->isEditable()) {
                throw new LogicException(__('Les lignes d’une facture fournisseur non modifiable sont immuables.'));
            }
        });
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
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
