<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OperationHistory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'metadata' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Operation histories are immutable.'));
        static::deleting(fn () => throw new LogicException('Operation histories are immutable.'));
    }
}
