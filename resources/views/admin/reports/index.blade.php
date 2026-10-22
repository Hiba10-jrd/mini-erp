<x-app-layout>
    <div class="reports-page">
        <header class="reports-hero">
            <span class="reports-hero-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3v18h17M8 16v-4m5 4V7m5 9v-6" /></svg>
            </span>
            <div class="reports-hero-copy">
                <h1>{{ __('Rapports') }}</h1>
                <p>{{ __('Analysez votre activité sur la période sélectionnée.') }}</p>
            </div>
            <div class="reports-hero-art" aria-hidden="true"><span></span><span></span><span></span></div>
        </header>
        <livewire:admin.reports-manager />
    </div>
</x-app-layout>
