<?php

namespace App\Services;

use App\Jobs\BroadcastStoredNotification;
use App\Models\User;
use App\Notifications\InternalNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InternalNotificationDispatcher
{
    public function send(User $recipient, array $payload, string $key): ?string
    {
        if (! app(NotificationRecipientResolver::class)->allowed($recipient, $payload['type'])) {
            return null;
        }
        $id = (string) Str::uuid();
        $notification = new InternalNotification([
            ...$payload, 'timestamp' => now()->toIso8601String(), 'notification_id' => $id,
        ], hash('sha256', $key));
        $notification->id = $id;
        try {
            DB::transaction(fn () => Notification::sendNow($recipient, $notification, ['database']));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        DB::afterCommit(function () use ($id): void {
            try {
                BroadcastStoredNotification::dispatch($id)->afterCommit();
            } catch (\Throwable $e) {
                Log::warning('Stored notification broadcast dispatch failed.', ['notification_id' => $id, 'exception' => $e::class]);
            }
        });

        return $id;
    }

    public function group(string $group, array $payload, string $key): void
    {
        app(NotificationRecipientResolver::class)->query($group)->chunkById(100, function ($users) use ($payload, $key): void {
            foreach ($users as $user) {
                $this->send($user, $payload, $key);
            }
        });
    }
}
