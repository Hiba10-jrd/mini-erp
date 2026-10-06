<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification;

class InternalDatabaseNotification extends DatabaseNotification
{
    protected $table = 'notifications';

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            $notification->dedup_key = $notification->data['_dedup_key'] ?? null;
            $data = $notification->data;
            unset($data['_dedup_key']);
            $notification->data = $data;
        });
    }
}
