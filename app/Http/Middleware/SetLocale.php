<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('app.supported_locales', [
            'fr' => 'Français',
            'en' => 'English',
            'ar' => 'العربية',
        ]));

        $locale = $request->session()->get('locale');

        if (! is_string($locale) || ! in_array($locale, $supported, true)) {
            $userLocale = $request->user()?->locale;
            $locale = (is_string($userLocale) && in_array($userLocale, $supported, true))
                ? $userLocale
                : (string) config('app.locale', 'fr');
        }

        if (! in_array($locale, $supported, true)) {
            $locale = (string) config('app.fallback_locale', 'fr');
        }

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
