<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        // Access to the ERP requires at least one assigned role.
        Gate::define('erp.access', function (User $user): bool {
            return $user->roles()->exists();
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
