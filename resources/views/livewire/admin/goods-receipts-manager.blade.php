<?php

use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'all';
    public string $supplierFilter = 'all';
    public string $warehouseFilter = 'all';

    public function mount(): void { Gate::authorize('purchases.view'); }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'supplierFilter', 'warehouseFilter'], true)) $this->resetPage();
    }

    public function with(): array
    {
        Gate::authorize('purchases.view');
        $statuses = [GoodsReceipt::STATUS_DRAFT, GoodsReceipt::STATUS_VALIDATED, GoodsReceipt::STATUS_CANCELLED];
        $status = in_array($this->statusFilter, $statuses, true) ? $this->statusFilter : 'all';
        $supplierId = $this->supplierFilter === 'all' ? null : filter_var($this->supplierFilter, FILTER_VALIDATE_INT);
        $warehouseId = $this->warehouseFilter === 'all' ? null : filter_var($this->warehouseFilter, FILTER_VALIDATE_INT);
        $search = trim($this->search);

        return [
            'receipts' => GoodsReceipt::query()->with(['purchaseOrder:id,number,supplier_id,supplier_name', 'warehouse:id,code,name', 'creator:id,name'])
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->when($supplierId !== false && $supplierId !== null, fn ($query) => $query->whereHas('purchaseOrder', fn ($order) => $order->where('supplier_id', $supplierId)))
                ->when($warehouseId !== false && $warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('number', 'like', "%{$search}%")
                        ->orWhereHas('purchaseOrder', fn ($order) => $order->where('number', 'like', "%{$search}%")->orWhere('supplier_name', 'like', "%{$search}%"));
                }))->latest('receipt_date')->latest('id')->paginate(10),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'code', 'name', 'is_active']),
        ];
    }
}; ?>

<section class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><h3 class="text-lg font-semibold text-gray-900">{{ __('Toutes les réceptions') }}</h3>@can('purchases.create')<a href="{{ route('purchases.receipts.create') }}" wire:navigate class="inline-flex min-h-10 items-center justify-center bg-gray-900 px-4 text-sm font-medium text-white hover:bg-gray-700">{{ __('Créer une réception') }}</a>@endcan</div>
    <div class="border-y border-gray-200 bg-white p-4 sm:p-5"><div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><x-input-label for="receipt-search" :value="__('Numéro, commande ou fournisseur')" /><x-text-input id="receipt-search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full" placeholder="BRF-2026-00001" /></div>
        <div><x-input-label for="receipt-status" :value="__('Statut')" /><select id="receipt-status" wire:model.live="statusFilter" class="mt-1 block w-full border-gray-300 shadow-sm"><option value="all">{{ __('Tous') }}</option><option value="draft">{{ __('Brouillon') }}</option><option value="validated">{{ __('Validée') }}</option><option value="cancelled">{{ __('Annulée') }}</option></select></div>
        <div><x-input-label for="receipt-supplier" :value="__('Fournisseur')" /><select id="receipt-supplier" wire:model.live="supplierFilter" class="mt-1 block w-full border-gray-300 shadow-sm"><option value="all">{{ __('Tous') }}</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></div>
        <div><x-input-label for="receipt-warehouse" :value="__('Dépôt')" /><select id="receipt-warehouse" wire:model.live="warehouseFilter" class="mt-1 block w-full border-gray-300 shadow-sm"><option value="all">{{ __('Tous') }}</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach</select></div>
    </div></div>
    @php($labels = ['draft' => __('Brouillon'), 'validated' => __('Validée'), 'cancelled' => __('Annulée')])
    @php($classes = ['draft' => 'bg-gray-100 text-gray-700', 'validated' => 'bg-emerald-100 text-emerald-800', 'cancelled' => 'bg-rose-100 text-rose-800'])
    <div class="overflow-hidden border-y border-gray-200 bg-white"><div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">{{ __('Numéro') }}</th><th class="px-4 py-3">{{ __('Commande') }}</th><th class="px-4 py-3">{{ __('Fournisseur') }}</th><th class="px-4 py-3">{{ __('Dépôt') }}</th><th class="px-4 py-3">{{ __('Date') }}</th><th class="px-4 py-3">{{ __('Statut') }}</th><th class="px-4 py-3">{{ __('Créé par') }}</th><th class="px-4 py-3 text-right">{{ __('Actions') }}</th></tr></thead><tbody class="divide-y divide-gray-100">
        @forelse($receipts as $receipt)<tr wire:key="receipt-{{ $receipt->id }}"><td class="px-4 py-4 font-semibold">{{ $receipt->number }}</td><td class="px-4 py-4">{{ $receipt->purchaseOrder->number }}</td><td class="px-4 py-4">{{ $receipt->purchaseOrder->supplier_name }}</td><td class="px-4 py-4">{{ $receipt->warehouse->code }}</td><td class="px-4 py-4">{{ $receipt->receipt_date->format('d/m/Y') }}</td><td class="px-4 py-4"><span class="px-2 py-1 text-xs font-medium {{ $classes[$receipt->status] ?? 'bg-gray-100' }}">{{ $labels[$receipt->status] ?? $receipt->status }}</span></td><td class="px-4 py-4">{{ $receipt->creator?->name ?? '—' }}</td><td class="px-4 py-4 text-right"><a href="{{ route('purchases.receipts.show', $receipt) }}" wire:navigate class="font-medium text-indigo-700">{{ __('Consulter') }}</a>@if($receipt->isEditable()) @can('purchases.update')<a href="{{ route('purchases.receipts.edit', $receipt) }}" wire:navigate class="ms-3 text-gray-700">{{ __('Modifier') }}</a>@endcan @endif</td></tr>@empty<tr><td colspan="8" class="px-6 py-10 text-center text-gray-500">{{ __('Aucune réception ne correspond aux filtres.') }}</td></tr>@endforelse
    </tbody></table></div>@if($receipts->hasPages())<div class="border-t border-gray-200 px-4 py-4">{{ $receipts->links() }}</div>@endif</div>
</section>
