<?php

namespace App\Providers;

use App\Http\Middleware\EnsurePasswordHasBeenChanged;
use App\Http\Middleware\EnsureUserAccountIsActive;
use App\Models\User;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([
            AuthenticateSession::class,
            EnsureUserAccountIsActive::class,
            EnsurePasswordHasBeenChanged::class,
        ]);

        Gate::before(function (User $user): ?bool {
            if (! $user->isActive() || $user->must_change_password) {
                return false;
            }

            return null;
        });

        // Access to the ERP requires at least one assigned role.
        Gate::define('erp.access', function (User $user): bool {
            return $user->roles()->exists();
        });

        // LOT 03-A keeps user administration exclusive to Super Administrators.
        Gate::define('users.administer', function (User $user): bool {
            return $user->isSuperAdministrator()
                && $user->hasPermission('users.view')
                && $user->hasPermission('users.manage');
        });

        // Register all ERP permissions as Laravel Gates.
        foreach (config('erp.permissions', []) as $permission) {
            Gate::define(
                $permission,
                fn (User $user): bool => $user->hasPermission($permission)
            );
        }
    }
}
