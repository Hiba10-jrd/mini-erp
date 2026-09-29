<?php

use App\Models\Quote;
use App\Services\QuoteManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public int $quoteId;

    public function mount(int $quoteId): void
    {
        Gate::authorize('sales.view');
        $this->quoteId = $quoteId;
    }

    public function transition(string $status, QuoteManagementService $service): void
    {
        Gate::authorize('sales.update');
        $service->transition(Quote::query()->findOrFail($this->quoteId), $status);
    }

    public function archive(QuoteManagementService $service): void
    {
        Gate::authorize('sales.delete');
        $service->archiveDraft(Quote::query()->findOrFail($this->quoteId));
    }

    public function createOrder(\App\Services\SalesOrderManagementService $service): void
    {
        Gate::authorize('sales.create');
        $order = $service->createFromAcceptedQuote(Quote::query()->findOrFail($this->quoteId));
        $this->redirectRoute('sales.orders.show', ['salesOrder' => $order], navigate: true);
    }

    public function with(): array
    {
        Gate::authorize('sales.view');

        return ['quote' => Quote::query()->with(['customer', 'creator', 'items', 'salesOrder'])->findOrFail($this->quoteId)];
    }
}; ?>

<section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('sales.quotes.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:text-indigo-900">{{ __('Retour aux devis') }}</a>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('sales.quotes.pdf', $quote) }}" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Télécharger le PDF') }}</a>
            @if ($quote->salesOrder)
                <a href="{{ route('sales.orders.show', $quote->salesOrder) }}" wire:navigate class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Voir la commande') }} {{ $quote->salesOrder->number }}</a>
            @elseif ($quote->status === 'accepted' && $quote->archived_at === null)
                @can('sales.create')<x-primary-button type="button" wire:click="createOrder">{{ __('Créer la commande') }}</x-primary-button>@endcan
            @endif
            @if ($quote->isEditable())
                @can('sales.update')<a href="{{ route('sales.quotes.edit', $quote) }}" wire:navigate class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Modifier') }}</a>@endcan
                @can('sales.delete')<x-danger-button type="button" wire:click="archive" wire:confirm="{{ __('Archiver ce brouillon ?') }}">{{ __('Archiver') }}</x-danger-button>@endcan
                @can('sales.update')<x-primary-button type="button" wire:click="transition('sent')">{{ __('Marquer comme envoyé') }}</x-primary-button>@endcan
            @elseif ($quote->status === 'sent')
                @can('sales.update')<x-primary-button type="button" wire:click="transition('accepted')">{{ __('Accepter') }}</x-primary-button><x-secondary-button type="button" wire:click="transition('refused')">{{ __('Refuser') }}</x-secondary-button>@if ($quote->valid_until && $quote->valid_until->isBefore(today()))<x-secondary-button type="button" wire:click="transition('expired')">{{ __('Marquer expiré') }}</x-secondary-button>@endif @endcan
            @endif
        </div>
    </div>
    <x-input-error :messages="$errors->get('status')" />
    <x-input-error :messages="$errors->get('quote')" />

    @php
        $statusLabels = ['draft' => __('Brouillon'), 'sent' => __('Envoyé'), 'accepted' => __('Accepté'), 'refused' => __('Refusé'), 'expired' => __('Expiré')];
        $statusClasses = ['draft' => 'bg-gray-100 text-gray-700', 'sent' => 'bg-sky-100 text-sky-800', 'accepted' => 'bg-emerald-100 text-emerald-800', 'refused' => 'bg-rose-100 text-rose-800', 'expired' => 'bg-amber-100 text-amber-800'];
    @endphp
    <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
        <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div><p class="text-sm font-semibold text-indigo-700">{{ $quote->number }}</p><h3 class="mt-1 text-xl font-semibold text-gray-900">{{ $quote->customer_name }}</h3>@if ($quote->customer_trade_name)<p class="text-sm text-gray-700">{{ $quote->customer_trade_name }}</p>@endif<p class="mt-1 text-sm text-gray-500">{{ $quote->customer->code }}</p><div class="mt-2 space-y-1 text-sm text-gray-600"><p>{{ collect([$quote->customer_address, $quote->customer_city, $quote->customer_country])->filter()->implode(', ') }}</p>@if ($quote->customer_email)<p>{{ $quote->customer_email }}</p>@endif @if ($quote->customer_phone)<p>{{ $quote->customer_phone }}</p>@endif @if ($quote->customer_ice)<p>{{ __('ICE') }} : {{ $quote->customer_ice }}</p>@endif @if ($quote->customer_tax_id)<p>{{ __('IF') }} : {{ $quote->customer_tax_id }}</p>@endif @if ($quote->customer_commercial_register)<p>{{ __('RC') }} : {{ $quote->customer_commercial_register }}</p>@endif</div></div>
            <span class="inline-block self-start px-3 py-1 text-sm font-medium {{ $statusClasses[$quote->status] }}">{{ $statusLabels[$quote->status] }}{{ $quote->archived_at ? ' · '.__('archivé') : '' }}</span>
        </div>
        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('Date du devis') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $quote->quote_date->format('d/m/Y') }}</dd></div><div><dt class="text-gray-500">{{ __('Valide jusqu’au') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $quote->valid_until?->format('d/m/Y') ?? '—' }}</dd></div><div><dt class="text-gray-500">{{ __('Créé par') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $quote->creator?->name ?? '—' }}</dd></div><div><dt class="text-gray-500">{{ __('Créé le') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $quote->created_at->format('d/m/Y H:i') }}</dd></div>
        </dl>
    </section>

    <section class="overflow-hidden border-y border-gray-200 bg-white"><div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">{{ __('Désignation') }}</th><th class="px-4 py-3 text-right">{{ __('Quantité') }}</th><th class="px-4 py-3">{{ __('Unité') }}</th><th class="px-4 py-3 text-right">{{ __('PU HT') }}</th><th class="px-4 py-3 text-right">{{ __('Remise') }}</th><th class="px-4 py-3 text-right">{{ __('TVA') }}</th><th class="px-4 py-3 text-right">{{ __('HT net') }}</th><th class="px-4 py-3 text-right">{{ __('TTC') }}</th></tr></thead>
        <tbody class="divide-y divide-gray-100">@foreach ($quote->items as $item)<tr><td class="min-w-56 px-4 py-4"><p class="font-medium text-gray-900">{{ $item->description }}</p><p class="text-xs text-gray-500">{{ $item->reference ?? '—' }} · {{ $item->item_type === 'service' ? __('Service') : __('Produit') }}</p></td><td class="whitespace-nowrap px-4 py-4 text-right">{{ $item->quantity }}</td><td class="whitespace-nowrap px-4 py-4">{{ $item->unit_label ?? '—' }}</td><td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $item->unit_price) }}</td><td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $item->discount_percent) }} %<span class="block text-xs text-gray-500">{{ str_replace('.', ',', $item->discount_amount) }}</span></td><td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $item->tax_rate_percent) }} %<span class="block text-xs text-gray-500">{{ str_replace('.', ',', $item->tax_amount) }}</span></td><td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $item->subtotal_ht) }}</td><td class="whitespace-nowrap px-4 py-4 text-right font-medium">{{ str_replace('.', ',', $item->total_ttc) }}</td></tr>@endforeach</tbody>
    </table></div></section>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-4">@if ($quote->terms)<section class="border-y border-gray-200 bg-white p-5"><h3 class="text-sm font-semibold text-gray-900">{{ __('Conditions') }}</h3><p class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ $quote->terms }}</p></section>@endif @if ($quote->notes)<section class="border-y border-gray-200 bg-white p-5"><h3 class="text-sm font-semibold text-gray-900">{{ __('Notes') }}</h3><p class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ $quote->notes }}</p></section>@endif</div>
        <section class="border-y border-gray-200 bg-white p-5"><dl class="space-y-3 text-sm"><div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Brut HT') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $quote->subtotal_ht) }}</dd></div><div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Remises') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $quote->discount_total) }}</dd></div><div class="flex justify-between gap-4 border-t border-gray-200 pt-3"><dt class="font-medium text-gray-900">{{ __('HT net') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $quote->baseHt()) }}</dd></div><div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('TVA') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $quote->tax_total) }}</dd></div><div class="flex justify-between gap-4 border-t border-gray-200 pt-3 text-base"><dt class="font-semibold text-gray-900">{{ __('Total TTC') }}</dt><dd class="font-semibold text-gray-950">{{ str_replace('.', ',', $quote->total_ttc) }}</dd></div></dl></section>
    </div>
</section>