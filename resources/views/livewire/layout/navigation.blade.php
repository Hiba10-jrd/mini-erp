<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>
<div class="erp-navigation">
<header class="erp-topbar">
    <div class="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
        <button x-ref="navToggle" type="button" @click="sidebarOpen = true; $nextTick(() => $refs.sidebarClose.focus())" :aria-expanded="sidebarOpen" aria-controls="erp-sidebar" aria-label="{{ __('Ouvrir la navigation') }}" class="erp-icon-button lg:hidden"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg></button>
        <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center justify-center rounded-xl" aria-label="Mini ERP"><x-application-logo class="h-8 w-8 rounded-lg sm:h-9 sm:w-9" /></a>
        <div class="erp-topbar-context hidden min-w-0 sm:block"><x-page-context /></div>
    </div>
    <div class="erp-topbar-actions flex shrink-0 items-center gap-1.5 sm:gap-2.5">
        @can('erp.access')<livewire:notification-bell />@endcan
        <x-language-switcher />
        <div class="erp-topbar-profile">
        <x-dropdown align="right" width="w-56 sm:w-64" contentClasses="p-1.5 bg-white">
            <x-slot name="trigger">
                <button type="button" class="erp-profile-trigger" aria-label="{{ __('Menu du profil') }}" :aria-expanded="open">
                    <span class="erp-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="hidden max-w-40 truncate sm:block" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></span>
                    <svg class="hidden h-4 w-4 shrink-0 text-slate-500 sm:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m7 10 5 5 5-5" /></svg>
                </button>
            </x-slot>
            <x-slot name="content">
                <p class="mx-1.5 mb-1 truncate border-b border-slate-100 px-2 py-3 text-xs font-medium text-slate-500">{{ auth()->user()->email }}</p>
                <x-dropdown-link :href="route('profile')">{{ __('Profile') }}</x-dropdown-link>
                <button wire:click="logout" type="button" class="block w-full rounded-lg px-3 py-2.5 text-start text-sm font-medium text-red-700 transition-colors hover:bg-red-50">{{ __('Log Out') }}</button>
            </x-slot>
        </x-dropdown>
        </div>
    </div>
</header>
</div>
