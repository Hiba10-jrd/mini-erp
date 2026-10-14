<?php

namespace App\Http\Middleware;

use App\Services\RequestRbacMemo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ScopeRbacMemo
{
    public function handle(Request $request, Closure $next): Response
    {
        $memo = app(RequestRbacMemo::class);
        $memo->begin($request);
        try {
            return $next($request);
        } finally {
            $memo->end();
        }
    }
}
