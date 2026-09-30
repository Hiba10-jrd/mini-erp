<?php

use App\Models\SalesOrder;
use App\Services\InvoiceManagementService;
use App\Services\SalesOrderManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public int $orderId;

    public function mount(int $orderId): void
    {
        Gate::authorize('sales.view');
        $this->orderId = $orderId;
    }

    public function confirmOrder(SalesOrderManagementService $service): void
    {
        Gate::authorize('sales.update');
        $service->transition(SalesOrder::query()->findOrFail($this->orderId), SalesOrder::STATUS_CONFIRMED);
    }

    public function cancelOrder(SalesOrderManagementService $service): void
    {
        Gate::authorize('sales.delete');
        $service->transition(SalesOrder::query()->findOrFail($this->orderId), SalesOrder::STATUS_CANCELLED);
    }

    public function createDeliveryNote(): void
    {
        Gate::authorize('sales.create');
        $order = SalesOrder::query()->with('items')->findOrFail($this->orderId);
        $remaining = $order->items->sum(fn ($item): float => (float) ($item->ordered_quantity - $item->delivered_quantity));

        abort_unless(in_array($order->status, [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_DELIVERED], true) && $remaining > 0, 403, __('Cette commande ne peut pas recevoir de bon de livraison.'));

        $draft = \App\Models\DeliveryNote::query()
            ->where('sales_order_id', $order->id)
            ->where('status', 'draft')
            ->latest('created_at')
            ->first();

        if ($draft !== null) {
            $this->redirectRoute('sales.delivery-notes.edit', ['deliveryNote' => $draft], navigate: true);

            return;
        }

        $this->redirectRoute('sales.orders.delivery-notes.create', ['salesOrder' => $order], navigate: true);
    }

    public function with(
        InvoiceManagementService $invoiceService
    ): array {
        Gate::authorize('sales.view');

        $order = SalesOrder::query()
            ->with([
                'customer',
                'sourceQuote',
                'creator',
                'items',
                'histories.user',
            ])
            ->findOrFail($this->orderId);

        $canCreateInvoice = false;

        $invoiceableStatuses = [
            SalesOrder::STATUS_CONFIRMED,
            SalesOrder::STATUS_PARTIALLY_DELIVERED,
            SalesOrder::STATUS_DELIVERED,
        ];

        if (
            Gate::allows('invoices.create')
            && in_array($order->status, $invoiceableStatuses, true)
        ) {
            $canCreateInvoice = $invoiceService
                ->availableItemsForOrder($order) !== [];
        }

        return [
            'order' => $order,

            'canCancel' => in_array(
                $order->status,
                [
                    SalesOrder::STATUS_DRAFT,
                    SalesOrder::STATUS_CONFIRMED,
                ],
                true
            ) && $order->items->every(
                fn ($item): bool => $item->delivered_quantity === '0.000'
            ),

            'canCreateDeliveryNote' => in_array(
                $order->status,
                [
                    SalesOrder::STATUS_CONFIRMED,
                    SalesOrder::STATUS_PARTIALLY_DELIVERED,
                ],
                true
            ) && $order->items->sum(
                fn ($item): float => (float) (
                    $item->ordered_quantity
                    - $item->delivered_quantity
                )
            ) > 0,

            'canCreateInvoice' => $canCreateInvoice,
        ];
    }
}; ?>

<section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('sales.orders.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:text-indigo-900">{{ __('Retour aux commandes') }}</a>
        <div class="flex flex-wrap gap-2">
            @if ($order->isEditable())
                @can('sales.update')<a href="{{ route('sales.orders.edit', $order) }}" wire:navigate class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700">{{ __('Modifier') }}</a><x-primary-button type="button" wire:click="confirmOrder">{{ __('Confirmer la commande') }}</x-primary-button>@endcan
            @endif
            @if ($canCreateDeliveryNote)
                @can('sales.create')<x-primary-button type="button" wire:click="createDeliveryNote">{{ __('Créer un bon de livraison') }}</x-primary-button>@endcan
            @endif
            @if ($canCreateInvoice)
    @can('invoices.create')
        <a
            href="{{ route(
                'sales.orders.invoices.create',
                $order
            ) }}"
            wire:navigate
            class="inline-flex min-h-10 items-center bg-gray-800 px-4 text-sm font-semibold uppercase tracking-widest text-white transition hover:bg-gray-700"
        >
            {{ __('Créer une facture') }}
        </a>
    @endcan
@endif
            @if ($canCancel)
                @can('sales.delete')<x-danger-button type="button" wire:click="cancelOrder" wire:confirm="{{ __('Annuler cette commande ?') }}">{{ __('Annuler la commande') }}</x-danger-button>@endcan
            @endif
        </div>
    </div>
    <x-input-error :messages="$errors->get('status')" />
    <x-input-error :messages="$errors->get('order')" />

    @php
        $statusLabels = ['draft' => __('Brouillon'), 'confirmed' => __('Confirmée'), 'partially_delivered' => __('Partiellement livrée'), 'delivered' => __('Livrée'), 'cancelled' => __('Annulée')];
        $statusClasses = ['draft' => 'bg-gray-100 text-gray-700', 'confirmed' => 'bg-sky-100 text-sky-800', 'partially_delivered' => 'bg-amber-100 text-amber-800', 'delivered' => 'bg-emerald-100 text-emerald-800', 'cancelled' => 'bg-rose-100 text-rose-800'];
        $historyLabels = ['created' => __('Création'), 'created_from_quote' => __('Création depuis devis'), 'draft_updated' => __('Modification du brouillon'), 'confirmed' => __('Confirmation'), 'cancelled' => __('Annulation')];
    @endphp
    <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
        <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div><p class="text-sm font-semibold text-indigo-700">{{ $order->number }}</p><h3 class="mt-1 text-xl font-semibold text-gray-900">{{ $order->customer_name }}</h3>@if ($order->customer_trade_name)<p class="text-sm text-gray-700">{{ $order->customer_trade_name }}</p>@endif<div class="mt-2 space-y-1 text-sm text-gray-600"><p>{{ collect([$order->customer_address, $order->customer_city, $order->customer_country])->filter()->implode(', ') }}</p>@if ($order->customer_email)<p>{{ $order->customer_email }}</p>@endif @if ($order->customer_phone)<p>{{ $order->customer_phone }}</p>@endif @if ($order->customer_ice)<p>{{ __('ICE') }} : {{ $order->customer_ice }}</p>@endif @if ($order->customer_tax_id)<p>{{ __('IF') }} : {{ $order->customer_tax_id }}</p>@endif @if ($order->customer_commercial_register)<p>{{ __('RC') }} : {{ $order->customer_commercial_register }}</p>@endif</div></div>
            <span class="inline-block self-start px-3 py-1 text-sm font-medium {{ $statusClasses[$order->status] ?? 'bg-gray-100 text-gray-700' }}">{{ $statusLabels[$order->status] ?? $order->status }}</span>
        </div>
        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('Date de commande') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->order_date->format('d/m/Y') }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Devis source') }}</dt><dd class="mt-1 font-medium text-gray-900">@if ($order->sourceQuote)<a class="text-indigo-700 hover:text-indigo-900" href="{{ route('sales.quotes.show', $order->sourceQuote) }}" wire:navigate>{{ $order->sourceQuote->number }}</a>@else — @endif</dd></div>
            <div><dt class="text-gray-500">{{ __('Créé par') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->creator?->name ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Créé le') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->created_at->format('d/m/Y H:i') }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Confirmée le') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->confirmed_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Annulée le') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->cancelled_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
        </dl>
    </section>

    <section class="overflow-hidden border-y border-gray-200 bg-white"><div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">{{ __('Désignation') }}</th><th class="px-4 py-3 text-right">{{ __('Commandé') }}</th><th class="px-4 py-3 text-right">{{ __('Livré') }}</th><th class="px-4 py-3 text-right">{{ __('Reste') }}</th><th class="px-4 py-3">{{ __('Unité') }}</th><th class="px-4 py-3 text-right">{{ __('PU HT') }}</th><th class="px-4 py-3 text-right">{{ __('Remise') }}</th><th class="px-4 py-3 text-right">{{ __('TVA') }}</th><th class="px-4 py-3 text-right">{{ __('HT net') }}</th><th class="px-4 py-3 text-right">{{ __('TTC') }}</th></tr></thead>
        <tbody class="divide-y divide-gray-100">@foreach ($order->items as $item)<tr><td class="min-w-56 px-4 py-4"><p class="font-medium text-gray-900">{{ $item->description }}</p><p class="text-xs text-gray-500">{{ $item->reference ?? '—' }} · {{ $item->item_type === 'service' ? __('Service') : __('Produit') }}</p></td><td class="whitespace-nowrap px-4 py-4 text-right">{{ $item->ordered_quantity }}</td><td class="whitespace-nowrap px-4 py-4 text-right">{{ $item->delivered_quantity }}</td><td class="whitespace-nowrap px-4 py-4 text-right">{{ $order->remainingQuantity($item) }}</td><td class="whitespace-nowrap px-4 py-4">{{ $item->unit_label ?? '—' }}</td><td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $item->unit_price) }}</td><td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $item->discount_percent) }} %<span class="block text-xs text-gray-500">{{ str_replace('.', ',', $item->discount_amount) }}</span></td><td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $item->tax_rate_percent) }} %<span class="block text-xs text-gray-500">{{ str_replace('.', ',', $item->tax_amount) }}</span></td><td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $item->subtotal_ht) }}</td><td class="whitespace-nowrap px-4 py-4 text-right font-medium">{{ str_replace('.', ',', $item->total_ttc) }}</td></tr>@endforeach</tbody>
    </table></div></section>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-4">@if ($order->terms)<section class="border-y border-gray-200 bg-white p-5"><h3 class="text-sm font-semibold text-gray-900">{{ __('Conditions') }}</h3><p class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ $order->terms }}</p></section>@endif @if ($order->notes)<section class="border-y border-gray-200 bg-white p-5"><h3 class="text-sm font-semibold text-gray-900">{{ __('Notes') }}</h3><p class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ $order->notes }}</p></section>@endif</div>
        <section class="border-y border-gray-200 bg-white p-5"><dl class="space-y-3 text-sm"><div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Brut HT') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $order->subtotal_ht) }}</dd></div><div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Remises') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $order->discount_total) }}</dd></div><div class="flex justify-between gap-4 border-t border-gray-200 pt-3"><dt class="font-medium text-gray-900">{{ __('HT net') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $order->baseHt()) }}</dd></div><div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('TVA') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $order->tax_total) }}</dd></div><div class="flex justify-between gap-4 border-t border-gray-200 pt-3 text-base"><dt class="font-semibold text-gray-900">{{ __('Total TTC') }}</dt><dd class="font-semibold text-gray-950">{{ str_replace('.', ',', $order->total_ttc) }}</dd></div></dl></section>
    </div>

    <section class="border-y border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-4"><h3 class="text-base font-semibold text-gray-900">{{ __('Historique') }}</h3></div>
        <ol class="divide-y divide-gray-100">@forelse ($order->histories as $history)<li class="flex flex-col gap-1 px-5 py-4 sm:flex-row sm:items-start sm:justify-between"><div><p class="font-medium text-gray-900">{{ $historyLabels[$history->event] ?? $history->event }}</p>@if ($history->description)<p class="text-sm text-gray-600">{{ $history->description }}</p>@endif<p class="text-xs text-gray-500">{{ $history->user?->name ?? '—' }}</p></div><time class="whitespace-nowrap text-sm text-gray-500">{{ $history->created_at?->format('d/m/Y H:i') }}</time></li>@empty<li class="px-5 py-4 text-sm text-gray-500">{{ __('Aucun événement.') }}</li>@endforelse</ol>
    </section>
</section>