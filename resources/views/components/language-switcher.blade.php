@php
    $locales = [
        'fr' => ['label' => 'Français', 'short' => 'FR', 'flag' => 'fr'],
        'en' => ['label' => 'English', 'short' => 'EN', 'flag' => 'gb'],
        'ar' => ['label' => 'العربية', 'short' => 'AR', 'flag' => 'sa'],
    ];

    $currentLocale = app()->getLocale();
@endphp

<div @keydown.escape.stop="open = false; $refs.languageTrigger.focus()" x-data="{ open: false }" class="relative shrink-0">
    <button
        x-ref="languageTrigger" type="button"
        @click="open = !open" :aria-expanded="open" aria-controls="erp-language-menu" aria-label="{{ $locales[$currentLocale]['label'] ?? $currentLocale }}"
        class="erp-language-trigger inline-flex items-center justify-between gap-1.5 px-2.5 text-sm font-semibold text-slate-700 sm:gap-2 sm:px-3.5"
    >
        <span class="flex items-center gap-1.5 sm:gap-2">
            <x-language-flag :country="$locales[$currentLocale]['flag'] ?? 'fr'" />
            <span class="leading-none">{{ $locales[$currentLocale]['short'] ?? strtoupper($currentLocale) }}</span>
        </span>

        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <div
        id="erp-language-menu" x-show="open"
        @click.away="open = false"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-y-1 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 translate-y-1 scale-95"
        class="erp-language-menu absolute end-0 z-50 mt-2.5 w-[208px] max-w-[calc(100vw-24px)] rounded-2xl border border-slate-200 bg-white p-2 ltr:origin-top-right rtl:origin-top-left"
        style="display: none;"
    >
        <div class="space-y-1.5">
            @foreach($locales as $locale => $data)
                <form method="POST" action="{{ route('locale.update') }}" class="m-0">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $locale }}">

                    <button
                        type="submit"
                        aria-current="{{ $currentLocale === $locale ? 'true' : 'false' }}" class="erp-language-option flex h-11 w-full items-center justify-between gap-3 rounded-xl px-3 text-start text-sm leading-6 transition-colors duration-150
                            {{ $currentLocale === $locale
                                ? 'bg-blue-50 text-blue-700 font-semibold'
                                : 'font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}"
                    >
                        <span class="flex items-center gap-3"><x-language-flag :country="$data['flag']" /><span class="{{ $locale === 'ar' ? 'font-semibold' : '' }}" lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
                            {{ $data['label'] }}
                        </span></span>

                        @if($currentLocale === $locale)
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        @endif
                    </button>
                </form>
            @endforeach
        </div>
    </div>
</div>
