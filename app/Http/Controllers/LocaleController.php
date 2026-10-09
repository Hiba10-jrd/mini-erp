<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Update the user's preferred locale.
     */
    public function update(Request $request): RedirectResponse
    {
        $supported = array_keys(config('app.supported_locales', [
            'fr' => 'Français',
            'en' => 'English',
            'ar' => 'العربية',
        ]));

        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in($supported)],
        ]);

        $locale = $validated['locale'];

        $request->session()->put('locale', $locale);

        $user = $request->user();
        if ($user) {
            $user->locale = $locale;
            $user->save();
        }

        $fallback = auth()->check() ? route('dashboard') : route('login');

        return redirect()->back(fallback: $fallback);
    }
}
