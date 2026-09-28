<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockInventorySequence extends Model
{
    protected $fillable = ['year', 'next_number'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'next_number' => 'integer'];
    }
}
