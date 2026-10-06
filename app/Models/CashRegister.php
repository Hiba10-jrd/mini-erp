<?php

namespace App\Models;

use App\Models\Concerns\RecordsOperations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegister extends Model
{
    use RecordsOperations;

    protected $fillable = [
        'code',
        'name',
        'initial_balance',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'initial_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class);
    }
}
