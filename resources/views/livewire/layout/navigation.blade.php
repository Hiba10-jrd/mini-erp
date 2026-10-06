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
    <div class="flex min-w-0 items-center gap-3">
        <button x-ref="navToggle" type="button" @click="sidebarOpen = true; $nextTick(() => $refs.sidebarClose.focus())" :aria-expanded="sidebarOpen" aria-controls="erp-sidebar" aria-label="Ouvrir la navigation" class="erp-icon-button lg:hidden"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg></button>
        <a href="{{ route('dashboard') }}" class="lg:hidden" aria-label="Mini ERP"><x-application-logo class="h-10 w-10 rounded-lg" /></a>
        <div class="hidden min-w-0 sm:block"><x-page-context /></div>
    </div>
    <div class="flex shrink-0 items-center gap-2 sm:gap-4">
        @can('erp.access')<livewire:notification-bell />@endcan
        <x-dropdown align="right" width="48">
            <x-slot name="trigger">
                <button type="button" class="erp-profile-trigger" aria-label="Menu du profil" :aria-expanded="open">
                    <span class="erp-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="hidden max-w-40 truncate sm:block" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></span><span aria-hidden="true">⌄</span>
                </button>
            </x-slot>
            <x-slot name="content">
                <p class="truncate border-b px-4 py-3 text-xs text-slate-500">{{ auth()->user()->email }}</p>
                <x-dropdown-link :href="route('profile')">{{ __('Profile') }}</x-dropdown-link>
                <button wire:click="logout" type="button" class="block w-full px-4 py-3 text-left text-sm text-red-700 hover:bg-red-50">{{ __('Log Out') }}</button>
            </x-slot>
        </x-dropdown>
    </div>
</header>
</div>
