<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserSessionInvalidator
{
    public function invalidate(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $connection = config('session.connection');
        $table = (string) config('session.table', 'sessions');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        DB::connection($connection)
            ->table($table)
            ->where('user_id', $user->getKey())
            ->when(
                $exceptSessionId !== null,
                fn ($query) => $query->where('id', '<>', $exceptSessionId)
            )
            ->delete();
    }
}
