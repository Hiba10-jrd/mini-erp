<?php

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    #[Url(as: 'supplier')]
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

        $statuses = [
            SupplierInvoice::STATUS_DRAFT,
            SupplierInvoice::STATUS_VALIDATED,
            SupplierInvoice::STATUS_CANCELLED,
        ];
        $status = in_array($this->statusFilter, $statuses, true) ? $this->statusFilter : 'all';
        $supplierId = $this->supplierFilter === 'all'
            ? null
            : filter_var($this->supplierFilter, FILTER_VALIDATE_INT);
        $search = trim($this->search);

        return [
            'invoices' => SupplierInvoice::query()
                ->with(['purchaseOrder:id,number', 'creator:id,name', 'validator:id,name'])
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->when(
                    $supplierId !== false && $supplierId !== null,
                    fn ($query) => $query->where('supplier_id', $supplierId)
                )
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('number', 'like', "%{$search}%")
                        ->orWhere('supplier_invoice_number', 'like', "%{$search}%")
                        ->orWhere('supplier_name', 'like', "%{$search}%")
                        ->orWhere('supplier_trade_name', 'like', "%{$search}%")
                        ->orWhereHas('purchaseOrder', fn ($order) => $order->where('number', 'like', "%{$search}%"));
                }))
                ->latest('invoice_date')
                ->latest('id')
                ->paginate(10),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'trade_name']),
        ];
    }
}; ?>

<section class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Toutes les factures fournisseurs') }}</h3>
            <p class="mt-1 text-sm text-gray-500">{{ __('Consultez les références fournisseurs et les numéros FAF après validation.') }}</p>
        </div>

        @can('purchases.create')
            <a href="{{ route('purchases.invoices.create') }}" wire:navigate class="inline-flex min-h-10 items-center justify-center bg-gray-900 px-4 text-sm font-medium text-white hover:bg-gray-700">
                {{ __('Créer une facture fournisseur') }}
            </a>
        @endcan
    </div>

    <div class="border-y border-gray-200 bg-white p-4 sm:p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <x-input-label for="supplier-invoice-search" :value="__('FAF, référence, BCF ou fournisseur')" />
                <x-text-input id="supplier-invoice-search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full" placeholder="FAF-2026-00001" />
            </div>
            <div>
                <x-input-label for="supplier-invoice-status" :value="__('Statut')" />
                <select id="supplier-invoice-status" wire:model.live="statusFilter" class="mt-1 block w-full border-gray-300 shadow-sm">
                    <option value="all">{{ __('Tous les statuts') }}</option>
                    <option value="draft">{{ __('Brouillon') }}</option>
                    <option value="validated">{{ __('Validée') }}</option>
                    <option value="cancelled">{{ __('Annulée') }}</option>
                </select>
            </div>
            <div>
                <x-input-label for="supplier-invoice-supplier" :value="__('Fournisseur')" />
                <select id="supplier-invoice-supplier" wire:model.live="supplierFilter" class="mt-1 block w-full border-gray-300 shadow-sm">
                    <option value="all">{{ __('Tous les fournisseurs') }}</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}@if($supplier->trade_name) · {{ $supplier->trade_name }}@endif</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    @php
        $statusLabels = ['draft' => __('Brouillon'), 'validated' => __('Validée'), 'cancelled' => __('Annulée')];
        $statusClasses = ['draft' => 'bg-gray-100 text-gray-700', 'validated' => 'bg-emerald-100 text-emerald-800', 'cancelled' => 'bg-rose-100 text-rose-800'];
    @endphp

    <div class="overflow-hidden border-y border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-start text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('FAF') }}</th>
                        <th class="px-4 py-3">{{ __('Référence fournisseur') }}</th>
                        <th class="px-4 py-3">{{ __('BCF') }}</th>
                        <th class="px-4 py-3">{{ __('Fournisseur') }}</th>
                        <th class="px-4 py-3">{{ __('Date') }}</th>
                        <th class="px-4 py-3 text-end">{{ __('TTC') }}</th>
                        <th class="px-4 py-3">{{ __('Statut') }}</th>
                        <th class="px-4 py-3 text-end">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($invoices as $invoice)
                        <tr wire:key="supplier-invoice-{{ $invoice->id }}">
                            <td class="whitespace-nowrap px-4 py-4 font-semibold">{{ $invoice->number ?? __('Brouillon #:id', ['id' => $invoice->id]) }}</td>
                            <td class="whitespace-nowrap px-4 py-4">{{ $invoice->supplier_invoice_number }}</td>
                            <td class="whitespace-nowrap px-4 py-4">{{ $invoice->purchaseOrder->number }}</td>
                            <td class="min-w-44 px-4 py-4">{{ $invoice->supplier_name }}</td>
                            <td class="whitespace-nowrap px-4 py-4">{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-end font-medium">{{ str_replace('.', ',', $invoice->total_ttc) }}</td>
                            <td class="px-4 py-4"><span class="px-2 py-1 text-xs font-medium {{ $statusClasses[$invoice->status] ?? 'bg-gray-100 text-gray-700' }}">{{ $statusLabels[$invoice->status] ?? $invoice->status }}</span></td>
                            <td class="whitespace-nowrap px-4 py-4 text-end">
                                <a href="{{ route('purchases.invoices.show', $invoice) }}" wire:navigate class="font-medium text-indigo-700">{{ __('Consulter') }}</a>
                                @if ($invoice->isEditable())
                                    @can('purchases.update')
                                        <a href="{{ route('purchases.invoices.edit', $invoice) }}" wire:navigate class="ms-3 text-gray-700">{{ __('Modifier') }}</a>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-10 text-center text-gray-500">{{ __('Aucune facture fournisseur ne correspond aux filtres.') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>
        @if ($invoices->hasPages())
            <div class="border-t border-gray-200 px-4 py-4">{{ $invoices->links() }}</div>
        @endif
    </div>
</section>
