<?php

use App\Models\Customer;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'all';
    public string $customerFilter = 'all';
    public string $sourceFilter = 'all';

    public function mount(): void
    {
        Gate::authorize('sales.view');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'customerFilter', 'sourceFilter'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        Gate::authorize('sales.view');
        $statuses = [SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_DELIVERED, SalesOrder::STATUS_DELIVERED, SalesOrder::STATUS_CANCELLED];
        $status = in_array($this->statusFilter, $statuses, true) ? $this->statusFilter : 'all';
        $customerId = $this->customerFilter === 'all' ? null : filter_var($this->customerFilter, FILTER_VALIDATE_INT);
        $source = in_array($this->sourceFilter, ['quote', 'direct'], true) ? $this->sourceFilter : 'all';
        $search = trim($this->search);

        return [
            'orders' => SalesOrder::query()
                ->with(['customer:id,name,code', 'sourceQuote:id,number', 'creator:id,name'])
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->when($customerId !== false && $customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
                ->when($source === 'quote', fn ($query) => $query->whereNotNull('source_quote_id'))
                ->when($source === 'direct', fn ($query) => $query->whereNull('source_quote_id'))
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%");
                }))
                ->latest('order_date')->latest('id')->paginate(10),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'status']),
        ];
    }
}; ?>

<section class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h3 class="text-lg font-semibold text-gray-900">{{ __('Toutes les commandes') }}</h3>
        @can('sales.create')
            <a href="{{ route('sales.orders.create') }}" wire:navigate class="inline-flex min-h-10 items-center justify-center bg-gray-900 px-4 text-sm font-medium text-white hover:bg-gray-700">{{ __('Créer une commande') }}</a>
        @endcan
    </div>

    <div class="border-y border-gray-200 bg-white p-4 sm:p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div><x-input-label for="order-search" :value="__('Numéro ou client')" /><x-text-input id="order-search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full" placeholder="CMD-2026-00001" /></div>
            <div><x-input-label for="order-status" :value="__('Statut')" /><select id="order-status" wire:model.live="statusFilter" class="mt-1 block w-full border-gray-300 shadow-sm"><option value="all">{{ __('Tous les statuts') }}</option><option value="draft">{{ __('Brouillon') }}</option><option value="confirmed">{{ __('Confirmée') }}</option><option value="partially_delivered">{{ __('Partiellement livrée') }}</option><option value="delivered">{{ __('Livrée') }}</option><option value="cancelled">{{ __('Annulée') }}</option></select></div>
            <div><x-input-label for="order-customer-filter" :value="__('Client')" /><select id="order-customer-filter" wire:model.live="customerFilter" class="mt-1 block w-full border-gray-300 shadow-sm"><option value="all">{{ __('Tous les clients') }}</option>@foreach ($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->status === 'active' ? '' : ' · '.__('archivé') }}</option>@endforeach</select></div>
            <div><x-input-label for="order-source-filter" :value="__('Origine')" /><select id="order-source-filter" wire:model.live="sourceFilter" class="mt-1 block w-full border-gray-300 shadow-sm"><option value="all">{{ __('Toutes') }}</option><option value="quote">{{ __('Depuis devis') }}</option><option value="direct">{{ __('Directe') }}</option></select></div>
        </div>
    </div>

    @php
        $statusLabels = ['draft' => __('Brouillon'), 'confirmed' => __('Confirmée'), 'partially_delivered' => __('Partiellement livrée'), 'delivered' => __('Livrée'), 'cancelled' => __('Annulée')];
        $statusClasses = ['draft' => 'bg-gray-100 text-gray-700', 'confirmed' => 'bg-sky-100 text-sky-800', 'partially_delivered' => 'bg-amber-100 text-amber-800', 'delivered' => 'bg-emerald-100 text-emerald-800', 'cancelled' => 'bg-rose-100 text-rose-800'];
    @endphp
    <div class="overflow-hidden border-y border-gray-200 bg-white">
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">{{ __('Numéro') }}</th><th class="px-4 py-3">{{ __('Date') }}</th><th class="px-4 py-3">{{ __('Client') }}</th><th class="px-4 py-3">{{ __('Devis source') }}</th><th class="px-4 py-3">{{ __('Statut') }}</th><th class="px-4 py-3 text-right">{{ __('TTC') }}</th><th class="px-4 py-3">{{ __('Créé par') }}</th><th class="px-4 py-3 text-right">{{ __('Actions') }}</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($orders as $order)
                    <tr wire:key="sales-order-{{ $order->id }}" class="hover:bg-gray-50">
                        <td class="whitespace-nowrap px-4 py-4 font-semibold text-gray-900">{{ $order->number }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $order->order_date->format('d/m/Y') }}</td>
                        <td class="min-w-48 px-4 py-4 font-medium text-gray-900">{{ $order->customer_name }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $order->sourceQuote?->number ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-4"><span class="inline-block px-2 py-1 text-xs font-medium {{ $statusClasses[$order->status] ?? 'bg-gray-100 text-gray-700' }}">{{ $statusLabels[$order->status] ?? $order->status }}</span></td>
                        <td class="whitespace-nowrap px-4 py-4 text-right font-medium text-gray-900">{{ str_replace('.', ',', $order->total_ttc) }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $order->creator?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-right"><a href="{{ route('sales.orders.show', $order) }}" wire:navigate class="font-medium text-indigo-700 hover:text-indigo-900">{{ __('Consulter') }}</a>@if ($order->isEditable()) @can('sales.update')<a href="{{ route('sales.orders.edit', $order) }}" wire:navigate class="ms-3 text-gray-700 hover:text-gray-950">{{ __('Modifier') }}</a>@endcan @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-6 py-10 text-center text-gray-500">{{ __('Aucune commande ne correspond aux filtres.') }}</td></tr>
                @endforelse
            </tbody>
        </table></div>
        @if ($orders->hasPages())<div class="border-t border-gray-200 px-4 py-4">{{ $orders->links() }}</div>@endif
    </div>
</section>