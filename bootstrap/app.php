<?php

use App\Http\Middleware\EnsurePasswordHasBeenChanged;
use App\Http\Middleware\EnsureUserAccountIsActive;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'middleware' => ['web', 'auth', 'auth.session', 'verified', 'can:erp.access'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'account.active' => EnsureUserAccountIsActive::class,
            'password.changed' => EnsurePasswordHasBeenChanged::class,
            'set.locale' => SetLocale::class,
        ]);

        $middleware->appendToGroup('web', [
            SetLocale::class,
            EnsureUserAccountIsActive::class,
            EnsurePasswordHasBeenChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
