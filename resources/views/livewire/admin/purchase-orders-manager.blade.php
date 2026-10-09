<?php

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $supplierFilter = 'all';

    public function mount(): void
    {
        Gate::authorize('purchases.view');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'supplierFilter'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        Gate::authorize('purchases.view');
        $statuses = [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CONFIRMED, PurchaseOrder::STATUS_CANCELLED];
        $status = in_array($this->statusFilter, $statuses, true) ? $this->statusFilter : 'all';
        $supplierId = $this->supplierFilter === 'all' ? null : filter_var($this->supplierFilter, FILTER_VALIDATE_INT);
        $search = trim($this->search);

        return [
            'orders' => PurchaseOrder::query()
                ->with(['supplier:id,name,code', 'creator:id,name'])
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->when($supplierId !== false && $supplierId !== null, fn ($query) => $query->where('supplier_id', $supplierId))
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('number', 'like', "%{$search}%")
                        ->orWhere('supplier_name', 'like', "%{$search}%");
                }))
                ->latest('order_date')->latest('id')->paginate(10),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'status']),
        ];
    }
}; ?>

<section class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h3 class="text-lg font-semibold text-gray-900">{{ __('Toutes les commandes fournisseurs') }}</h3>
        @can('purchases.create')<a href="{{ route('purchases.orders.create') }}" wire:navigate class="inline-flex min-h-10 items-center justify-center bg-gray-900 px-4 text-sm font-medium text-white hover:bg-gray-700">{{ __('Créer une commande') }}</a>@endcan
    </div>

    <div class="border-y border-gray-200 bg-white p-4 sm:p-5">
        <div class="grid gap-4 sm:grid-cols-3">
            <div><x-input-label for="purchase-search" :value="__('Numéro ou fournisseur')" /><x-text-input id="purchase-search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full" placeholder="BCF-2026-00001" /></div>
            <div><x-input-label for="purchase-status" :value="__('Statut')" /><select id="purchase-status" wire:model.live="statusFilter" class="mt-1 block w-full border-gray-300 shadow-sm"><option value="all">{{ __('Tous les statuts') }}</option><option value="draft">{{ __('Brouillon') }}</option><option value="confirmed">{{ __('Confirmée') }}</option><option value="cancelled">{{ __('Annulée') }}</option></select></div>
            <div><x-input-label for="purchase-supplier" :value="__('Fournisseur')" /><select id="purchase-supplier" wire:model.live="supplierFilter" class="mt-1 block w-full border-gray-300 shadow-sm"><option value="all">{{ __('Tous les fournisseurs') }}</option>@foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}{{ $supplier->status === 'active' ? '' : ' · '.__('archivé') }}</option>@endforeach</select></div>
        </div>
    </div>

    @php
        $statusLabels = ['draft' => __('Brouillon'), 'confirmed' => __('Confirmée'), 'cancelled' => __('Annulée')];
        $statusClasses = ['draft' => 'bg-gray-100 text-gray-700', 'confirmed' => 'bg-sky-100 text-sky-800', 'cancelled' => 'bg-rose-100 text-rose-800'];
    @endphp
    <div class="overflow-hidden border-y border-gray-200 bg-white">
        <div class="overflow-x-auto"><div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-start text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">{{ __('Numéro') }}</th><th class="px-4 py-3">{{ __('Date') }}</th><th class="px-4 py-3">{{ __('Fournisseur') }}</th><th class="px-4 py-3">{{ __('Date prévue') }}</th><th class="px-4 py-3">{{ __('Statut') }}</th><th class="px-4 py-3 text-end">{{ __('TTC') }}</th><th class="px-4 py-3">{{ __('Créé par') }}</th><th class="px-4 py-3 text-end">{{ __('Actions') }}</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($orders as $order)
                    <tr wire:key="purchase-order-{{ $order->id }}" class="hover:bg-gray-50">
                        <td class="whitespace-nowrap px-4 py-4 font-semibold text-gray-900">{{ $order->number }}</td><td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $order->order_date->format('d/m/Y') }}</td><td class="min-w-48 px-4 py-4 font-medium text-gray-900">{{ $order->supplier_name }}</td><td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $order->expected_date?->format('d/m/Y') ?? '—' }}</td><td class="whitespace-nowrap px-4 py-4"><span class="inline-block px-2 py-1 text-xs font-medium {{ $statusClasses[$order->status] ?? 'bg-gray-100 text-gray-700' }}">{{ $statusLabels[$order->status] ?? $order->status }}</span></td><td class="whitespace-nowrap px-4 py-4 text-end font-medium text-gray-900">{{ str_replace('.', ',', $order->total_ttc) }}</td><td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $order->creator?->name ?? '—' }}</td><td class="whitespace-nowrap px-4 py-4 text-end"><a href="{{ route('purchases.orders.show', $order) }}" wire:navigate class="font-medium text-indigo-700 hover:text-indigo-900">{{ __('Consulter') }}</a>@if ($order->isEditable()) @can('purchases.update')<a href="{{ route('purchases.orders.edit', $order) }}" wire:navigate class="ms-3 text-gray-700 hover:text-gray-950">{{ __('Modifier') }}</a>@endcan @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-6 py-10 text-center text-gray-500">{{ __('Aucune commande ne correspond aux filtres.') }}</td></tr>
                @endforelse
            </tbody>
        </table></div></div>
        @if ($orders->hasPages())<div class="border-t border-gray-200 px-4 py-4">{{ $orders->links() }}</div>@endif
    </div>
</section>
