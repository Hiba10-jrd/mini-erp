<?php

use App\Models\PurchaseOrder;
use App\Services\GoodsReceiptManagementService;
use App\Services\PurchaseOrderManagementService;
use App\Services\SupplierInvoiceManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public int $orderId;

    public function mount(int $orderId): void
    {
        Gate::authorize('purchases.view');
        $this->orderId = $orderId;
    }

    public function confirmOrder(PurchaseOrderManagementService $service): void
    {
        Gate::authorize('purchases.update');
        $service->confirm(PurchaseOrder::query()->findOrFail($this->orderId));
    }

    public function cancelOrder(PurchaseOrderManagementService $service): void
    {
        Gate::authorize('purchases.delete');
        $service->cancelDraft(PurchaseOrder::query()->findOrFail($this->orderId));
    }

    public function with(
        GoodsReceiptManagementService $receiptService,
        SupplierInvoiceManagementService $invoiceService,
    ): array {
        Gate::authorize('purchases.view');

        $order = PurchaseOrder::query()->with(['supplier', 'paymentTerm', 'creator', 'confirmedBy', 'items', 'histories.user', 'goodsReceipts.warehouse', 'supplierInvoices'])->findOrFail($this->orderId);
        $available = $receiptService->availableItemsForOrder($order);
        $canCreateSupplierInvoice = $order->status === PurchaseOrder::STATUS_CONFIRMED
            && $invoiceService->availableItemsForOrder($order) !== [];

        return [
            'order' => $order,
            'available' => $available,
            'canCreateReceipt' => $order->status === PurchaseOrder::STATUS_CONFIRMED && $receiptService->hasRemaining($order),
            'canCreateSupplierInvoice' => $canCreateSupplierInvoice,
        ];
    }
}; ?>

<section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('purchases.orders.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:text-indigo-900">{{ __('Retour aux commandes fournisseurs') }}</a>
        <div class="flex flex-wrap gap-2">
            @if ($order->isEditable())
                @can('purchases.update')<a href="{{ route('purchases.orders.edit', $order) }}" wire:navigate class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700">{{ __('Modifier') }}</a><x-primary-button type="button" wire:click="confirmOrder">{{ __('Confirmer la commande') }}</x-primary-button>@endcan
                @can('purchases.delete')<x-danger-button type="button" wire:click="cancelOrder" wire:confirm="{{ __('Annuler cette commande fournisseur ?') }}">{{ __('Annuler la commande') }}</x-danger-button>@endcan
            @endif
            @if($canCreateReceipt) @can('purchases.create')<a href="{{ route('purchases.orders.receipts.create', $order) }}" wire:navigate class="inline-flex min-h-10 items-center bg-gray-900 px-4 text-sm font-medium text-white">{{ __('Créer une réception') }}</a>@endcan @endif
            @if($canCreateSupplierInvoice) @can('purchases.create')<a href="{{ route('purchases.orders.invoices.create', $order) }}" wire:navigate class="inline-flex min-h-10 items-center bg-indigo-700 px-4 text-sm font-medium text-white hover:bg-indigo-600">{{ __('Créer une facture fournisseur') }}</a>@endcan @endif
        </div>
    </div>
    <x-input-error :messages="$errors->get('status')" /><x-input-error :messages="$errors->get('order')" />

    @php
        $statusLabels = ['draft' => __('Brouillon'), 'confirmed' => __('Confirmée'), 'cancelled' => __('Annulée')];
        $statusClasses = ['draft' => 'bg-gray-100 text-gray-700', 'confirmed' => 'bg-sky-100 text-sky-800', 'cancelled' => 'bg-rose-100 text-rose-800'];
        $historyLabels = ['created' => __('Création'), 'draft_updated' => __('Modification du brouillon'), 'confirmed' => __('Confirmation'), 'cancelled' => __('Annulation'), 'goods_receipt_created' => __('Réception créée'), 'goods_receipt_validated' => __('Réception validée'), 'goods_receipt_cancelled' => __('Réception annulée'), 'supplier_invoice_created' => __('Facture fournisseur créée'), 'supplier_invoice_validated' => __('Validation de la facture fournisseur'), 'supplier_invoice_cancelled' => __('Facture fournisseur annulée')];
    @endphp
    <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
        <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div><p class="text-sm font-semibold text-indigo-700">{{ $order->number }}</p><h3 class="mt-1 text-xl font-semibold text-gray-900">{{ $order->supplier_name }}</h3>@if ($order->supplier_trade_name)<p class="text-sm text-gray-700">{{ $order->supplier_trade_name }}</p>@endif<div class="mt-2 space-y-1 text-sm text-gray-600"><p>{{ collect([$order->supplier_address, $order->supplier_city, $order->supplier_country])->filter()->implode(', ') }}</p>@if ($order->supplier_email)<p>{{ $order->supplier_email }}</p>@endif @if ($order->supplier_phone)<p>{{ $order->supplier_phone }}</p>@endif @if ($order->supplier_ice)<p>{{ __('ICE') }} : {{ $order->supplier_ice }}</p>@endif @if ($order->supplier_tax_id)<p>{{ __('IF') }} : {{ $order->supplier_tax_id }}</p>@endif @if ($order->supplier_commercial_register)<p>{{ __('RC') }} : {{ $order->supplier_commercial_register }}</p>@endif</div></div>
            <span class="inline-block self-start px-3 py-1 text-sm font-medium {{ $statusClasses[$order->status] ?? 'bg-gray-100 text-gray-700' }}">{{ $statusLabels[$order->status] ?? $order->status }}</span>
        </div>
        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('Date de commande') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->order_date->format('d/m/Y') }}</dd></div><div><dt class="text-gray-500">{{ __('Date prévue') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->expected_date?->format('d/m/Y') ?? '—' }}</dd></div><div><dt class="text-gray-500">{{ __('Condition de paiement') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->payment_term_label ?? '—' }}@if ($order->payment_term_days !== null) <span class="text-gray-500">({{ $order->payment_term_days }} j)</span>@endif</dd></div><div><dt class="text-gray-500">{{ __('Créé par') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->creator?->name ?? '—' }}</dd></div><div><dt class="text-gray-500">{{ __('Créé le') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->created_at->format('d/m/Y H:i') }}</dd></div><div><dt class="text-gray-500">{{ __('Confirmé par') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->confirmedBy?->name ?? '—' }}</dd></div><div><dt class="text-gray-500">{{ __('Confirmée le') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->confirmed_at?->format('d/m/Y H:i') ?? '—' }}</dd></div><div><dt class="text-gray-500">{{ __('Annulée le') }}</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->cancelled_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
        </dl>
    </section>

    <section class="overflow-hidden border-y border-gray-200 bg-white"><div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">{{ __('Désignation') }}</th><th class="px-4 py-3 text-right">{{ __('Commandé') }}</th><th class="px-4 py-3 text-right">{{ __('Reçu') }}</th><th class="px-4 py-3 text-right">{{ __('Restant') }}</th><th class="px-4 py-3">{{ __('Unité') }}</th><th class="px-4 py-3 text-right">{{ __('PU HT') }}</th><th class="px-4 py-3 text-right">{{ __('Remise') }}</th><th class="px-4 py-3 text-right">{{ __('TVA') }}</th><th class="px-4 py-3 text-right">{{ __('HT net') }}</th><th class="px-4 py-3 text-right">{{ __('TTC') }}</th></tr></thead>
        <tbody class="divide-y divide-gray-100">@foreach ($order->items as $item)<tr><td class="min-w-56 px-4 py-4"><p class="font-medium text-gray-900">{{ $item->description }}</p><p class="text-xs text-gray-500">{{ $item->reference ?? '—' }} · {{ $item->item_type === 'service' ? __('Service') : __('Produit') }}</p></td><td class="whitespace-nowrap px-4 py-4 text-right">{{ $item->quantity }}</td><td class="whitespace-nowrap px-4 py-4 text-right">{{ $available[$item->id]['received'] }}</td><td class="whitespace-nowrap px-4 py-4 text-right font-medium">{{ $available[$item->id]['remaining'] }}</td><td class="whitespace-nowrap px-4 py-4">{{ $item->unit_label ?? '—' }}</td><td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $item->unit_price) }}</td><td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $item->discount_percent) }} %<span class="block text-xs text-gray-500">{{ str_replace('.', ',', $item->discount_amount) }}</span></td><td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $item->tax_rate_percent) }} %<span class="block text-xs text-gray-500">{{ str_replace('.', ',', $item->tax_amount) }}</span></td><td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $item->subtotal_ht) }}</td><td class="whitespace-nowrap px-4 py-4 text-right font-medium">{{ str_replace('.', ',', $item->total_ttc) }}</td></tr>@endforeach</tbody>
    </table></div></section>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-4">@if ($order->terms)<section class="border-y border-gray-200 bg-white p-5"><h3 class="text-sm font-semibold text-gray-900">{{ __('Conditions') }}</h3><p class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ $order->terms }}</p></section>@endif @if ($order->notes)<section class="border-y border-gray-200 bg-white p-5"><h3 class="text-sm font-semibold text-gray-900">{{ __('Notes') }}</h3><p class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ $order->notes }}</p></section>@endif</div>
        <section class="border-y border-gray-200 bg-white p-5"><dl class="space-y-3 text-sm"><div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Brut HT') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $order->subtotal_ht) }}</dd></div><div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Remises') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $order->discount_total) }}</dd></div><div class="flex justify-between gap-4 border-t border-gray-200 pt-3"><dt class="font-medium text-gray-900">{{ __('HT net') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $order->baseHt()) }}</dd></div><div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('TVA') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $order->tax_total) }}</dd></div><div class="flex justify-between gap-4 border-t border-gray-200 pt-3 text-base"><dt class="font-semibold text-gray-900">{{ __('Total TTC') }}</dt><dd class="font-semibold text-gray-950">{{ str_replace('.', ',', $order->total_ttc) }}</dd></div></dl></section>
    </div>

    <section class="border-y border-gray-200 bg-white"><div class="border-b border-gray-200 px-5 py-4"><h3 class="text-base font-semibold text-gray-900">{{ __('Réceptions fournisseurs') }}</h3></div><div class="divide-y divide-gray-100">@forelse($order->goodsReceipts as $receipt)<a href="{{ route('purchases.receipts.show', $receipt) }}" wire:navigate class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-gray-50"><div><p class="font-medium text-indigo-700">{{ $receipt->number }}</p><p class="text-sm text-gray-500">{{ $receipt->warehouse->code }} · {{ $receipt->receipt_date->format('d/m/Y') }}</p></div><span class="text-sm text-gray-600">{{ ['draft' => __('Brouillon'), 'validated' => __('Validée'), 'cancelled' => __('Annulée')][$receipt->status] ?? $receipt->status }}</span></a>@empty<p class="px-5 py-4 text-sm text-gray-500">{{ __('Aucune réception.') }}</p>@endforelse</div></section>

    <section class="border-y border-gray-200 bg-white"><div class="border-b border-gray-200 px-5 py-4"><h3 class="text-base font-semibold text-gray-900">{{ __('Factures fournisseurs') }}</h3></div><div class="divide-y divide-gray-100">@forelse($order->supplierInvoices as $invoice)<a href="{{ route('purchases.invoices.show', $invoice) }}" wire:navigate class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-gray-50"><div><p class="font-medium text-indigo-700">{{ $invoice->number ?? __('Brouillon #:id', ['id' => $invoice->id]) }}</p><p class="text-sm text-gray-500">{{ $invoice->supplier_invoice_number }} · {{ $invoice->invoice_date->format('d/m/Y') }}</p></div><span class="text-sm text-gray-600">{{ ['draft' => __('Brouillon'), 'validated' => __('Validée'), 'cancelled' => __('Annulée')][$invoice->status] ?? $invoice->status }}</span></a>@empty<p class="px-5 py-4 text-sm text-gray-500">{{ __('Aucune facture fournisseur.') }}</p>@endforelse</div></section>

    <section class="border-y border-gray-200 bg-white"><div class="border-b border-gray-200 px-5 py-4"><h3 class="text-base font-semibold text-gray-900">{{ __('Historique') }}</h3></div><ol class="divide-y divide-gray-100">@forelse ($order->histories as $history)<li class="flex flex-col gap-1 px-5 py-4 sm:flex-row sm:items-start sm:justify-between"><div><p class="font-medium text-gray-900">{{ $historyLabels[$history->event] ?? $history->event }}</p>@if ($history->description)<p class="text-sm text-gray-600">{{ $history->description }}</p>@endif<p class="text-xs text-gray-500">{{ $history->user?->name ?? '—' }}</p></div><time class="whitespace-nowrap text-sm text-gray-500">{{ $history->created_at?->format('d/m/Y H:i') }}</time></li>@empty<li class="px-5 py-4 text-sm text-gray-500">{{ __('Aucun événement.') }}</li>@endforelse</ol></section>
</section>
