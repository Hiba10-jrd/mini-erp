<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTerm extends Model
{
    protected $fillable = ['label', 'due_days', 'description', 'is_active', 'is_default'];

    protected function casts(): array
    {
        return [
            'due_days' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
