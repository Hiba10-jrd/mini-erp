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
<section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-xl font-semibold">{{ __('Notifications') }}</h2><p class="mt-2 text-sm text-slate-500">{{ __('Retrouvez les alertes et les dernières activités qui vous concernent.') }}</p></div><x-secondary-button wire:click="markAllRead" wire:loading.attr="disabled">{{ __('Tout marquer comme lu') }}</x-secondary-button></div>
    <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Filtrer les notifications') }}">
        @foreach(['all'=>'Toutes','unread'=>'Non lues','read'=>'Lues'] as $value=>$label)<button type="button" wire:click="$set('filter', '{{ $value }}')" aria-pressed="{{ $filter === $value ? 'true' : 'false' }}" @class(['erp-button', 'erp-button-primary'=>$filter === $value, 'erp-button-secondary'=>$filter !== $value])>{{ __($label) }}</button>@endforeach
    </div>
    <div class="erp-card !p-0 overflow-hidden">
        @forelse($notifications as $notification)
            <article wire:key="notification-{{ $notification->id }}" @class(['erp-notification-row', 'is-unread'=>!$notification->read_at])>
                <div class="flex items-start gap-4"><span class="erp-avatar" aria-hidden="true">{{ ($notification->data['severity'] ?? '') === 'warning' ? '!' : 'i' }}</span><div class="min-w-0 flex-1"><div class="flex flex-wrap items-center justify-between gap-2"><h3 class="text-sm font-semibold">{{ $notification->data['title'] ?? __('Notification') }}</h3><x-status-badge :status="$notification->read_at ? 'neutral' : 'info'" :label="$notification->read_at ? __('Lue') : __('Non lue')" /></div><p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $notification->data['message'] ?? '' }}</p><time class="mt-2 block text-xs text-slate-400 erp-ltr">{{ $notification->created_at->format('d/m/Y H:i') }}</time><div class="mt-3 flex flex-wrap gap-3">@if($url = $links[$notification->id] ?? null)<a href="{{ $url }}" class="text-xs font-semibold text-indigo-700">{{ __('Consulter') }} <span class="inline-block rtl:rotate-180">→</span></a>@endif @if(!$notification->read_at)<button type="button" wire:click="markRead('{{ $notification->id }}')" class="text-xs font-medium text-slate-600">{{ __('Marquer comme lue') }}</button>@endif</div></div></div>
            </article>
        @empty <x-empty-state title="Aucune notification" description="Vous êtes à jour. Vos prochaines alertes apparaîtront ici." /> @endforelse
    </div>
    {{ $notifications->links() }}
</section>
