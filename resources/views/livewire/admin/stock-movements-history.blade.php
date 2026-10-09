<?php

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $productFilter = 'all';

    public string $warehouseFilter = 'all';

    public string $typeFilter = 'all';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        Gate::authorize('stock.access');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['productFilter', 'warehouseFilter', 'typeFilter', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    #[On('stock-updated')]
    public function refreshStockHistory(int $productId): void
    {
        Gate::authorize('stock.access');
    }

    #[On('warehouse-updated')]
    public function refreshWarehouseFilters(int $warehouseId): void
    {
        Gate::authorize('stock.access');
    }

    public function with(): array
    {
        Gate::authorize('stock.access');

        $movements = StockMovement::query()
            ->with(['product:id,reference,name', 'warehouse:id,code,name', 'performer:id,name'])
            ->when($this->productFilter !== 'all', fn ($query) => $query->where('product_id', (int) $this->productFilter))
            ->when($this->warehouseFilter !== 'all', fn ($query) => $query->where('warehouse_id', (int) $this->warehouseFilter))
            ->when(in_array($this->typeFilter, $this->movementTypes(), true), fn ($query) => $query->where('type', $this->typeFilter))
            ->when($this->validDate($this->dateFrom), fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($this->validDate($this->dateTo), fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('id')
            ->paginate(15);

        return [
            'movements' => $movements,
            'products' => Product::query()->where('type', 'product')->orderBy('name')->get(['id', 'reference', 'name']),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }

    /** @return array<int, string> */
    private function movementTypes(): array
    {
        return ['entry', 'exit', 'transfer_out', 'transfer_in', 'return_in', 'return_out', 'adjustment_positive', 'adjustment_negative'];
    }

    private function validDate(string $date): ?string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
    }
}; ?>

<section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
    <div class="border-b border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900">{{ __('Historique des mouvements') }}</h3>
        <p class="mt-1 text-sm text-gray-600">{{ __('Les mouvements validés sont consultables mais ne peuvent être ni modifiés ni supprimés.') }}</p>

        <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <x-input-label for="movement-product" :value="__('Produit')" />
                <select id="movement-product" wire:model.live="productFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="all">{{ __('Tous') }}</option>
                    @foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->reference }} — {{ $product->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <x-input-label for="movement-warehouse" :value="__('Dépôt')" />
                <select id="movement-warehouse" wire:model.live="warehouseFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="all">{{ __('Tous') }}</option>
                    @foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <x-input-label for="movement-type" :value="__('Type')" />
                <select id="movement-type" wire:model.live="typeFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="all">{{ __('Tous') }}</option>
                    <option value="entry">{{ __('Entrée') }}</option><option value="exit">{{ __('Sortie') }}</option>
                    <option value="transfer_out">{{ __('Transfert sortant') }}</option><option value="transfer_in">{{ __('Transfert entrant') }}</option>
                    <option value="return_in">{{ __('Retour entrant') }}</option><option value="return_out">{{ __('Retour sortant') }}</option>
                    <option value="adjustment_positive">{{ __('Ajustement positif') }}</option><option value="adjustment_negative">{{ __('Ajustement négatif') }}</option>
                </select>
            </div>
            <div><x-input-label for="movement-from" :value="__('Du')" /><x-text-input id="movement-from" type="date" wire:model.live="dateFrom" class="mt-1 block w-full" /></div>
            <div><x-input-label for="movement-to" :value="__('Au')" /><x-text-input id="movement-to" type="date" wire:model.live="dateTo" class="mt-1 block w-full" /></div>
        </div>
    </div>

    @php
        $movementLabels = [
            'entry' => __('Entrée'), 'exit' => __('Sortie'),
            'transfer_out' => __('Transfert sortant'), 'transfer_in' => __('Transfert entrant'),
            'return_in' => __('Retour entrant'), 'return_out' => __('Retour sortant'),
            'adjustment_positive' => __('Ajustement positif'), 'adjustment_negative' => __('Ajustement négatif'),
        ];
    @endphp
    <div class="overflow-x-auto">
        <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-start text-xs uppercase text-gray-500">
                <tr><th class="px-4 py-3">{{ __('Date') }}</th><th class="px-4 py-3">{{ __('Produit') }}</th><th class="px-4 py-3">{{ __('Dépôt') }}</th><th class="px-4 py-3">{{ __('Type') }}</th><th class="px-4 py-3 text-end">{{ __('Quantité') }}</th><th class="px-4 py-3 text-end">{{ __('Avant') }}</th><th class="px-4 py-3 text-end">{{ __('Après') }}</th><th class="px-4 py-3">{{ __('Référence / note') }}</th><th class="px-4 py-3">{{ __('Utilisateur') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @forelse ($movements as $movement)
                    <tr wire:key="movement-{{ $movement->id }}">
                        <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $movement->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-4"><p class="font-medium text-gray-900">{{ $movement->product->name }}</p><p class="text-xs text-gray-500">{{ $movement->product->reference }}</p></td>
                        <td class="px-4 py-4 text-gray-700">{{ $movement->warehouse->code }} — {{ $movement->warehouse->name }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-gray-700">{{ $movementLabels[$movement->type] ?? $movement->type }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-end font-medium">{{ $movement->quantity }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-end text-gray-600">{{ $movement->quantity_before }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-end text-gray-600">{{ $movement->quantity_after }}</td>
                        <td class="max-w-xs px-4 py-4 text-gray-600"><p>{{ $movement->reference ?? '—' }}</p>@if ($movement->notes)<p class="mt-1 text-xs text-gray-500">{{ $movement->notes }}</p>@endif</td>
                        <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $movement->performer?->name ?? __('Compte supprimé') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-6 py-8 text-center text-gray-500">{{ __('Aucun mouvement ne correspond aux filtres.') }}</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </div>
    @if ($movements->hasPages())
        <div class="border-t border-gray-200 px-6 py-4">{{ $movements->links() }}</div>
    @endif
</section>
