<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashTransaction extends Model
{
    public const TYPE_ENTRY = 'entry';

    public const TYPE_EXIT = 'exit';

    protected $fillable = [
        'cash_register_id',
        'expense_id',
        'transaction_date',
        'type',
        'amount',
        'reference',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public static function types(): array
    {
        return [
            self::TYPE_ENTRY,
            self::TYPE_EXIT,
        ];
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
