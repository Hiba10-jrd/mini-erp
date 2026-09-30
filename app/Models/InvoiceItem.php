<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'sales_order_item_id',
        'delivery_note_item_id',
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
        $ensureDraft = function (InvoiceItem $item): void {
            $invoice = $item->invoice()->first();

            if ($invoice !== null && ! $invoice->isEditable()) {
                throw new LogicException(__('Les lignes d’une facture non modifiable sont immuables.'));
            }
        };

        static::saving($ensureDraft);
        static::deleting($ensureDraft);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function deliveryNoteItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryNoteItem::class);
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
