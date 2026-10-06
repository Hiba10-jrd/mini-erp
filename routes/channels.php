<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return (string) $user->id === $id && $user->isActive() && ! $user->must_change_password && $user->roles()->exists();
});
