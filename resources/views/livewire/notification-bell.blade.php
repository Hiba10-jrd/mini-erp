<?php

use Livewire\Attributes\On;

new class extends \Livewire\Volt\Component
{
    public function with(): array
    {
        \Illuminate\Support\Facades\Gate::authorize('erp.access');
        $recent = auth()->user()->notifications()->limit(5)->get();

        return ['unread' => auth()->user()->notifications()->whereNull('read_at')->count(),
            'recent' => $recent, 'links' => app(\App\Services\NotificationLinkResolver::class)->links($recent)];
    }

    #[On('notification-received')]
    public function refreshNotifications(): void {}

    public function markRead(string $id): void
    {
        \Illuminate\Support\Facades\Gate::authorize('erp.access');
        auth()->user()->notifications()->findOrFail($id)->markAsRead();
        $this->dispatch('notification-received');
    }
}; ?>
<div data-notification-user="{{ auth()->id() }}" class="relative">
    <details @click.outside="$el.removeAttribute('open')" @keydown.escape.window="$el.removeAttribute('open')">
        <summary class="erp-icon-button relative cursor-pointer list-none" aria-label="{{ trans_choice('Notifications : :count non lue|Notifications : :count non lues', $unread, ['count' => $unread]) }}">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V10a6 6 0 0 0-12 0v4.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 0 1-6 0m6 0H9" /></svg>
            @if($unread)<span class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-[#087FF5] px-1 text-[10px] font-semibold text-white">{{ $unread > 99 ? '99+' : $unread }}</span>@endif
        </summary>
        <div class="erp-notification-menu">
            <div class="flex items-center justify-between border-b px-4 py-4"><h3 class="text-sm font-semibold">{{ __('Notifications') }}</h3><span class="text-xs text-slate-500">{{ trans_choice(':count non lue|:count non lues', $unread, ['count' => $unread]) }}</span></div>
            <div class="max-h-96 overflow-y-auto">
                @forelse($recent as $notification)
                    <div @class(['erp-notification-row','is-unread'=>!$notification->read_at]) wire:key="recent-{{ $notification->id }}"><p class="text-xs font-semibold">{{ isset($notification->data['title_key']) ? __($notification->data['title_key'], $notification->data['params'] ?? []) : (isset($notification->data['title']) ? __($notification->data['title']) : __('Notification')) }}</p><p class="mt-1 text-xs leading-relaxed text-slate-500">{{ isset($notification->data['message_key']) ? __($notification->data['message_key'], $notification->data['params'] ?? []) : ($notification->data['message'] ?? '') }}</p><time class="mt-2 block text-[10px] text-slate-400 erp-ltr">{{ $notification->created_at->format('d/m/Y H:i') }}</time><div class="mt-2 flex gap-3">@if($url = $links[$notification->id] ?? null)<a href="{{ $url }}" class="text-xs font-semibold text-indigo-600">{{ __('Consulter') }}</a>@endif @if(!$notification->read_at)<button type="button" wire:click="markRead('{{ $notification->id }}')" class="text-xs text-slate-600">{{ __('Marquer comme lue') }}</button>@endif</div></div>
                @empty <x-empty-state title="Aucune notification" description="Vous êtes à jour." /> @endforelse
            </div>
            <a href="{{ route('notifications.index') }}" wire:navigate class="block bg-slate-50 px-4 py-3 text-center text-xs font-semibold text-indigo-600">{{ __('Voir toutes les notifications') }} <span class="inline-block rtl:rotate-180">→</span></a>
        </div>
    </details>
</div>
