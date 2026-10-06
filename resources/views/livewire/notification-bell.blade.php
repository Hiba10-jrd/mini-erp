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
        <summary class="cursor-pointer rounded border border-gray-300 px-3 py-2 text-sm" aria-label="Notifications : {{ $unread }} non lues">
            Notifications @if($unread)<span class="font-semibold">({{ $unread }})</span>@endif
        </summary>
        <div class="absolute right-0 z-50 mt-2 w-80 rounded border border-gray-200 bg-white p-4 shadow">
            <p class="text-sm font-semibold">{{ $unread }} non lues</p>
            <ul class="mt-2 max-h-96 divide-y overflow-y-auto">
                @forelse($recent as $notification)
                    <li wire:key="recent-{{ $notification->id }}" class="space-y-2 py-3 text-sm">
                        <p class="{{ $notification->read_at ? '' : 'font-semibold' }}">{{ $notification->data['title'] ?? 'Notification' }}</p>
                        <p>{{ $notification->data['message'] ?? '' }}</p>
                        <time class="block text-xs text-gray-500">{{ $notification->created_at->format('d/m/Y H:i') }}</time>
                        @if($url = $links[$notification->id] ?? null)<a href="{{ $url }}" class="text-indigo-600 underline">Consulter</a>@endif
                        @if(!$notification->read_at)<button type="button" wire:click="markRead('{{ $notification->id }}')" class="text-indigo-600 underline">Marquer comme lue</button>@endif
                    </li>
                @empty
                    <li class="py-3 text-sm text-gray-500">Aucune notification.</li>
                @endforelse
            </ul>
            <a href="{{ route('notifications.index') }}" wire:navigate class="mt-3 block text-sm text-indigo-600 underline">Voir toutes les notifications</a>
        </div>
    </details>
</div>
