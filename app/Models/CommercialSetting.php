<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialSetting extends Model
{
    protected $fillable = [
        'currency_code',
        'currency_name',
    ];

    protected function casts(): array
    {
        return ['singleton' => 'boolean'];
    }
}
