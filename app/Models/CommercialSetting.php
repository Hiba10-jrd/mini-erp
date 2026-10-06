<?php

namespace App\Models;

use App\Models\Concerns\RecordsOperations;
use Illuminate\Database\Eloquent\Model;

class CommercialSetting extends Model
{
    use RecordsOperations;

    protected $fillable = [
        'currency_code',
        'currency_name',
    ];

    protected function casts(): array
    {
        return ['singleton' => 'boolean'];
    }
}
