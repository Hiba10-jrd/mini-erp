<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PurchaseOrderHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'purchase_order_id',
        'event',
        'from_status',
        'to_status',
        'user_id',
        'description',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException(__('L’historique d’une commande fournisseur est immuable.'));
        });

        static::deleting(function (): never {
            throw new LogicException(__('L’historique d’une commande fournisseur ne peut pas être supprimé.'));
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
