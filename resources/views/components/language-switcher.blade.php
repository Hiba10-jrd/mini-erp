@php
    $currentLocale = app()->getLocale();
    $supported = config('app.supported_locales', [
        'fr' => 'Français',
        'en' => 'English',
        'ar' => 'العربية',
    ]);
@endphp

<div class="relative inline-block text-start">
    <x-dropdown align="right" width="48">
        <x-slot name="trigger">
            <button
                type="button"
                class="erp-icon-button flex items-center justify-center gap-1.5 px-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition"
                aria-label="{{ __('Changer de langue') }}"
            >
                <svg class="h-4 w-4 shrink-0 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 2a14.5 14.5 0 0 0 0 20M12 2a14.5 14.5 0 0 1 0 20M2 12h20" />
                </svg>
                <span class="uppercase font-bold">{{ $currentLocale }}</span>
            </button>
        </x-slot>

        <x-slot name="content">
            <div class="py-1">
                @foreach ($supported as $code => $name)
                    <form method="POST" action="{{ route('locale.update') }}" class="m-0 p-0">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $code }}">
                        <button
                            type="submit"
                            lang="{{ $code }}"
                            dir="{{ $code === 'ar' ? 'rtl' : 'ltr' }}"
                            @if ($code === $currentLocale) aria-current="true" @endif
                            class="flex w-full items-center justify-between px-4 py-2.5 text-xs sm:text-sm text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition @if ($code === $currentLocale) font-bold bg-slate-50 text-indigo-700 @endif"
                        >
                            <span>{{ $name }}</span>
                            @if ($code === $currentLocale)
                                <svg class="h-4 w-4 text-indigo-600 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                            @endif
                        </button>
                    </form>
                @endforeach
            </div>
        </x-slot>
    </x-dropdown>
</div>
