<?php

namespace App\Models;

use App\Models\Concerns\RecordsOperations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CreditNoteItem extends Model
{
    use RecordsOperations;

    protected $fillable = [
        'credit_note_id',
        'invoice_item_id',
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
        $ensureDraft = function (CreditNoteItem $item): void {
            $creditNote = $item->creditNote()->first();

            if ($creditNote !== null && ! $creditNote->isEditable()) {
                throw new LogicException(__('Les lignes d’un avoir non modifiable sont immuables.'));
            }
        };

        static::saving($ensureDraft);
        static::deleting($ensureDraft);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
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
