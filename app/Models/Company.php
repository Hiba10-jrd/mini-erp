<?php

namespace App\Models;

use App\Models\Concerns\RecordsOperations;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use RecordsOperations;

    protected $fillable = [
        'legal_name',
        'trade_name',
        'ice',
        'tax_id',
        'commercial_register',
        'address',
        'phone',
        'email',
        'website',
        'logo_path',
        'bank_name',
        'bank_account_holder',
        'bank_reference',
    ];

    protected function casts(): array
    {
        return [
            'singleton' => 'boolean',
        ];
    }
}
