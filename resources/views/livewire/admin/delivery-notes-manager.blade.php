<?php

use App\Models\DeliveryNote;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $warehouseFilter = 'all';

    public function mount(): void
    {
        Gate::authorize('sales.view');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'warehouseFilter'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        Gate::authorize('sales.view');

        $status = in_array($this->statusFilter, ['draft', 'validated', 'cancelled'], true) ? $this->statusFilter : 'all';
        $warehouseId = $this->warehouseFilter === 'all' ? null : filter_var($this->warehouseFilter, FILTER_VALIDATE_INT);
        $search = trim($this->search);

        return [
            'notes' => DeliveryNote::query()
                ->with(['salesOrder:id,number,customer_name', 'warehouse:id,code,name', 'creator:id,name'])
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->when($warehouseId !== false && $warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('number', 'like', "%{$search}%")
                        ->orWhereHas('salesOrder', fn ($salesQuery) => $salesQuery->where('number', 'like', "%{$search}%"))
                        ->orWhere('notes', 'like', "%{$search}%");
                }))
                ->latest('delivery_date')->latest('id')->paginate(12),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
        ];
    }
}; ?>

<section class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h3 class="text-lg font-semibold text-gray-900">{{ __('Tous les bons de livraison') }}</h3>
    </div>

    <div class="border-y border-gray-200 bg-white p-4 sm:p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <x-input-label for="delivery-note-search" :value="__('Numéro, commande ou note')" />
                <x-text-input id="delivery-note-search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full" placeholder="BL-2026-00001" />
            </div>
            <div>
                <x-input-label for="delivery-note-status" :value="__('Statut')" />
                <select id="delivery-note-status" wire:model.live="statusFilter" class="mt-1 block w-full border-gray-300 shadow-sm">
                    <option value="all">{{ __('Tous les statuts') }}</option>
                    <option value="draft">{{ __('Brouillon') }}</option>
                    <option value="validated">{{ __('Validé') }}</option>
                    <option value="cancelled">{{ __('Annulé') }}</option>
                </select>
            </div>
            <div>
                <x-input-label for="delivery-note-warehouse" :value="__('Dépôt')" />
                <select id="delivery-note-warehouse" wire:model.live="warehouseFilter" class="mt-1 block w-full border-gray-300 shadow-sm">
                    <option value="all">{{ __('Tous les dépôts') }}</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    @php
        $statusLabels = ['draft' => __('Brouillon'), 'validated' => __('Validé'), 'cancelled' => __('Annulé')];
        $statusClasses = ['draft' => 'bg-gray-100 text-gray-700', 'validated' => 'bg-emerald-100 text-emerald-800', 'cancelled' => 'bg-rose-100 text-rose-800'];
    @endphp

    <div class="overflow-hidden border-y border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-start text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Numéro') }}</th>
                        <th class="px-4 py-3">{{ __('Date') }}</th>
                        <th class="px-4 py-3">{{ __('Commande') }}</th>
                        <th class="px-4 py-3">{{ __('Client') }}</th>
                        <th class="px-4 py-3">{{ __('Dépôt') }}</th>
                        <th class="px-4 py-3">{{ __('Statut') }}</th>
                        <th class="px-4 py-3 text-end">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($notes as $note)
                        <tr wire:key="delivery-note-{{ $note->id }}" class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-4 py-4 font-semibold text-gray-900">{{ $note->number }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $note->delivery_date->format('d/m/Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-gray-900">{{ $note->salesOrder?->number ?? '—' }}</td>
                            <td class="min-w-48 px-4 py-4 text-gray-900">{{ $note->salesOrder?->customer_name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $note->warehouse ? $note->warehouse->code : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <span class="inline-block px-2 py-1 text-xs font-medium {{ $statusClasses[$note->status] ?? 'bg-gray-100 text-gray-700' }}">{{ $statusLabels[$note->status] ?? $note->status }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-end">
                                <a href="{{ route('sales.delivery-notes.show', $note) }}" wire:navigate class="font-medium text-indigo-700 hover:text-indigo-900">{{ __('Consulter') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-10 text-center text-gray-500">{{ __('Aucun bon de livraison ne correspond aux filtres.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>
        @if ($notes->hasPages())
            <div class="border-t border-gray-200 px-4 py-4">{{ $notes->links() }}</div>
        @endif
    </div>
</section>
