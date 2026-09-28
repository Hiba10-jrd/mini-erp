<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerCodeSequence extends Model
{
    protected $fillable = ['next_number', 'singleton'];

    protected function casts(): array
    {
        return ['next_number' => 'integer', 'singleton' => 'boolean'];
    }
}
