<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    protected $fillable = ['label', 'rate', 'is_active', 'is_default'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
