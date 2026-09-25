<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordHasBeenChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user instanceof User
            && $user->must_change_password
            && ! $request->routeIs('password.change.required', '*.livewire.update')
        ) {
            return redirect()->route('password.change.required');
        }

        return $next($request);
    }
}
