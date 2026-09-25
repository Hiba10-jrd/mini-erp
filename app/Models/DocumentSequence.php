<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    protected $fillable = [
        'document_type',
        'prefix',
        'year',
        'counter',
        'number_format',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'counter' => 'integer',
        ];
    }
}
