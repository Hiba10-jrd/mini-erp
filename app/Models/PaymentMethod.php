<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    public const TYPE_CASH = 'cash';

    public const TYPE_CHEQUE = 'cheque';

    public const TYPE_BANK_TRANSFER = 'bank_transfer';

    public const TYPE_CARD = 'card';

    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'name',
        'payment_type',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public static function types(): array
    {
        return [
            self::TYPE_CASH,
            self::TYPE_CHEQUE,
            self::TYPE_BANK_TRANSFER,
            self::TYPE_CARD,
            self::TYPE_OTHER,
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
