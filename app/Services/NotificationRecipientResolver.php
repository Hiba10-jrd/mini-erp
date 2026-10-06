<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class NotificationRecipientResolver
{
    public function query(string $group): Builder
    {
        $permissions = $group === 'stock' ? ['stock.view', 'stock.manage'] : ['payments.view'];

        return User::query()->where('account_status', 'active')->where('must_change_password', false)
            ->where(function ($query) use ($permissions): void {
                $query->whereHas('roles', fn ($roles) => $roles->where('slug', 'super-admin'))
                    ->orWhereHas('roles.permissions', fn ($p) => $p->whereIn('name', $permissions));
            });
    }

    public function allowed(User $user, string $type): bool
    {
        return Gate::forUser($user)->allows(str_starts_with($type, 'stock.') ? 'stock.access' : 'payments.view');
    }
}
