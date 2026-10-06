<?php

use Livewire\Attributes\On;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $filter = 'all';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function markRead(string $id): void
    {
        \Illuminate\Support\Facades\Gate::authorize('erp.access');
        auth()->user()->notifications()->findOrFail($id)->markAsRead();
        $this->dispatch('notification-received');
    }

    public function markAllRead(): void
    {
        \Illuminate\Support\Facades\Gate::authorize('erp.access');
        auth()->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);
        $this->dispatch('notification-received');
    }

    #[On('notification-received')]
    public function refreshNotifications(): void {}

    public function with(): array
    {
        \Illuminate\Support\Facades\Gate::authorize('erp.access');
        $notifications = auth()->user()->notifications()
            ->when($this->filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($this->filter === 'read', fn ($q) => $q->whereNotNull('read_at'))->paginate(20);

        return ['notifications' => $notifications, 'links' => app(\App\Services\NotificationLinkResolver::class)->links($notifications->getCollection())];
    }
}; ?>
<section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-semibold">Notifications</h2>
        <button type="button" wire:click="markAllRead" wire:loading.attr="disabled" class="rounded border border-gray-300 px-3 py-2 text-sm">Tout marquer comme lu</button>
    </div>
    <label class="block text-sm">Filtrer
        <select wire:model.live="filter" class="ml-2 rounded border-gray-300">
            <option value="all">Toutes</option>
            <option value="unread">Non lues</option>
            <option value="read">Lues</option>
        </select>
    </label>
    <ul class="divide-y rounded border border-gray-200 bg-white px-4">
        @forelse($notifications as $notification)
            <li wire:key="notification-{{ $notification->id }}" class="space-y-2 py-4 text-sm">
                <h3 class="{{ $notification->read_at ? '' : 'font-semibold' }}">{{ $notification->data['title'] ?? 'Notification' }}</h3>
                <p>{{ $notification->data['message'] ?? '' }}</p>
                <p class="text-xs text-gray-500">{{ $notification->read_at ? 'Lue' : 'Non lue' }} · {{ $notification->created_at->format('d/m/Y H:i') }}</p>
                @if($url = $links[$notification->id] ?? null)<a href="{{ $url }}" class="text-indigo-600 underline">Consulter</a>@endif
                @if(!$notification->read_at)<button type="button" wire:click="markRead('{{ $notification->id }}')" class="text-indigo-600 underline">Marquer comme lue</button>@endif
            </li>
        @empty
            <li class="py-4 text-sm text-gray-500">Aucune notification.</li>
        @endforelse
    </ul>
    {{ $notifications->links() }}
</section>
