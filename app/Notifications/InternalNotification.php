<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class InternalNotification extends Notification
{
    public function __construct(public array $payload, public ?string $dedupKey = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [...$this->payload, '_dedup_key' => $this->dedupKey];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->payload))->onConnection('sync');
    }

    public function broadcastType(): string
    {
        return $this->payload['type'];
    }
}
