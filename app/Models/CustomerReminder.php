<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CustomerReminder extends Model
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_PHONE = 'phone';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_MANUAL = 'manual';

    protected $fillable = ['invoice_id', 'reminder_date', 'channel', 'note', 'created_by'];

    public static function channels(): array
    {
        return [self::CHANNEL_EMAIL, self::CHANNEL_PHONE, self::CHANNEL_WHATSAPP, self::CHANNEL_MANUAL];
    }

    protected function casts(): array
    {
        return ['reminder_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException(__('Une relance client est immuable.'));
        });

        static::deleting(function (): never {
            throw new LogicException(__('Une relance client ne peut pas être supprimée physiquement.'));
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
