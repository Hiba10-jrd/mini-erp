<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

class PurchaseOrder extends Model
{
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'supplier_id',
        'payment_term_id',
        'order_date',
        'expected_date',
        'notes',
        'terms',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'payment_term_days' => 'integer',
            'subtotal_ht' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PurchaseOrder $order): void {
            $originalStatus = $order->getOriginal('status');
            $targetStatus = $order->status;

            if ($originalStatus === self::STATUS_DRAFT && $targetStatus === self::STATUS_DRAFT) {
                return;
            }

            $transitions = [
                self::STATUS_DRAFT => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
            ];
            $statusChanged = $order->isDirty('status');

            if ($statusChanged && ! in_array($targetStatus, $transitions[$originalStatus] ?? [], true)) {
                throw new LogicException(__('Cette transition de commande fournisseur est interdite.'));
            }

            $allowed = ['status', 'updated_at'];
            if ($statusChanged && $targetStatus === self::STATUS_CONFIRMED) {
                array_push($allowed, 'confirmed_at', 'confirmed_by');
            }
            if ($statusChanged && $targetStatus === self::STATUS_CANCELLED) {
                $allowed[] = 'cancelled_at';
            }

            if (array_diff(array_keys($order->getDirty()), $allowed) !== []) {
                throw new LogicException(__('Le contenu commercial d’une commande fournisseur non modifiable est immuable.'));
            }
        });

        static::deleting(function (): never {
            throw new LogicException(__('Une commande fournisseur ne peut pas être supprimée physiquement.'));
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('position');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(PurchaseOrderHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('receipt_date')->orderBy('id');
    }

    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class)->orderBy('invoice_date')->orderBy('id');
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function baseHt(): string
    {
        return (string) BigDecimal::of($this->subtotal_ht)->minus($this->discount_total)->toScale(2);
    }
}
