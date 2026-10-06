<?php

namespace App\Models;

use App\Models\Concerns\RecordsOperations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockInventoryLine extends Model
{
    use RecordsOperations;

    protected $fillable = [
        'stock_inventory_id',
        'product_id',
        'theoretical_quantity',
        'actual_quantity',
        'difference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'theoretical_quantity' => 'decimal:3',
            'actual_quantity' => 'decimal:3',
            'difference' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (StockInventoryLine $line): void {
            if (! $line->inventory()->firstOrFail()->isEditable()) {
                throw new LogicException('Les lignes d’un inventaire clôturé sont immuables.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Une ligne d’inventaire ne peut pas être supprimée physiquement.');
        });
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(StockInventory::class, 'stock_inventory_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
