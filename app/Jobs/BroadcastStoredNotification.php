<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\InternalNotification;
use App\Services\NotificationRecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

class BroadcastStoredNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $notificationId) {}

    public function backoff(): array
    {
        return [10, 60, 120];
    }

    public function handle(NotificationRecipientResolver $resolver): void
    {
        $stored = DatabaseNotification::find($this->notificationId);
        if (! $stored || ! ($stored->notifiable instanceof User)) {
            return;
        }
        $user = $stored->notifiable;
        if (! $resolver->allowed($user, $stored->data['type'])) {
            return;
        }
        $notification = new InternalNotification($stored->data);
        $notification->id = $stored->id;
        Notification::sendNow($user, $notification, ['broadcast']);
    }
}
