<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $warehouseFilter = 'all';

    public string $categoryFilter = 'all';

    public string $productFilter = 'all';

    public string $alertFilter = 'all';

    public function mount(): void
    {
        Gate::authorize('stock.access');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'warehouseFilter', 'categoryFilter', 'productFilter', 'alertFilter'], true)) {
            $this->resetPage();
        }
    }

    #[On('stock-updated')]
    public function refreshStockBalances(int $productId): void
    {
        Gate::authorize('stock.access');
    }

    #[On('warehouse-updated')]
    public function refreshWarehouseBalances(int $warehouseId): void
    {
        Gate::authorize('stock.access');
    }

    public function with(): array
    {
        Gate::authorize('stock.access');

        $stockTotals = WarehouseStock::query()
            ->selectRaw('product_id, SUM(quantity) as total_stock')
            ->groupBy('product_id');

        $balances = Product::query()
            ->select([
                'products.*',
                'warehouses.id as warehouse_id',
                'warehouses.code as warehouse_code',
                'warehouses.name as warehouse_name',
                'warehouses.is_active as warehouse_is_active',
                DB::raw('COALESCE(warehouse_stocks.quantity, 0) as stock_quantity'),
                DB::raw('COALESCE(stock_totals.total_stock, 0) as total_stock'),
            ])
            ->with('category')
            ->crossJoin('warehouses')
            ->leftJoin('warehouse_stocks', function (JoinClause $join): void {
                $join->on('warehouse_stocks.product_id', '=', 'products.id')
                    ->on('warehouse_stocks.warehouse_id', '=', 'warehouses.id');
            })
            ->leftJoinSub($stockTotals, 'stock_totals', function (JoinClause $join): void {
                $join->on('stock_totals.product_id', '=', 'products.id');
            })
            ->where('products.type', 'product')
            ->when(trim($this->search) !== '', function ($query): void {
                $search = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($search): void {
                    $query->where('products.reference', 'like', $search)
                        ->orWhere('products.name', 'like', $search)
                        ->orWhere('products.barcode', 'like', $search);
                });
            })
            ->when($this->warehouseFilter !== 'all', fn ($query) => $query->where('warehouses.id', (int) $this->warehouseFilter))
            ->when($this->categoryFilter !== 'all', fn ($query) => $query->where('products.category_id', (int) $this->categoryFilter))
            ->when($this->productFilter !== 'all', fn ($query) => $query->where('products.id', (int) $this->productFilter))
            ->when($this->alertFilter === 'rupture', fn ($query) => $query->whereRaw('COALESCE(warehouse_stocks.quantity, 0) = 0'))
            ->when($this->alertFilter === 'low', fn ($query) => $query
                ->whereRaw('COALESCE(warehouse_stocks.quantity, 0) > 0')
                ->whereNotNull('products.minimum_stock')
                ->whereRaw('COALESCE(warehouse_stocks.quantity, 0) <= products.minimum_stock'))
            ->orderBy('products.name')
            ->orderBy('warehouses.name')
            ->paginate(10);

        return [
            'balances' => $balances,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'code', 'name']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->where('type', 'product')->orderBy('name')->get(['id', 'reference', 'name']),
        ];
    }
}; ?>

<section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
    <div class="border-b border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900">{{ __('Soldes par dépôt') }}</h3>
        <p class="mt-1 text-sm text-gray-600">{{ __('Les ruptures et seuils minimum sont calculés séparément pour chaque dépôt.') }}</p>

        <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <x-input-label for="balance-search" :value="__('Recherche')" />
                <x-text-input id="balance-search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full" placeholder="Référence, nom, code-barres" />
            </div>
            <div>
                <x-input-label for="balance-warehouse" :value="__('Dépôt')" />
                <select id="balance-warehouse" wire:model.live="warehouseFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="all">{{ __('Tous') }}</option>
                    @foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <x-input-label for="balance-category" :value="__('Catégorie')" />
                <select id="balance-category" wire:model.live="categoryFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="all">{{ __('Toutes') }}</option>
                    @foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <x-input-label for="balance-product" :value="__('Produit')" />
                <select id="balance-product" wire:model.live="productFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="all">{{ __('Tous') }}</option>
                    @foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->reference }} — {{ $product->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <x-input-label for="balance-alert" :value="__('Alerte')" />
                <select id="balance-alert" wire:model.live="alertFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="all">{{ __('Toutes') }}</option>
                    <option value="low">{{ __('Stock faible') }}</option>
                    <option value="rupture">{{ __('Rupture') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr><th class="px-6 py-3">{{ __('Produit') }}</th><th class="px-6 py-3">{{ __('Dépôt') }}</th><th class="px-6 py-3 text-right">{{ __('Disponible') }}</th><th class="px-6 py-3 text-right">{{ __('Total global') }}</th><th class="px-6 py-3 text-right">{{ __('Minimum') }}</th><th class="px-6 py-3">{{ __('État') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @forelse ($balances as $balance)
                    @php
                        $quantity = (float) $balance->stock_quantity;
                        $isRupture = $quantity === 0.0;
                        $isLow = ! $isRupture && $balance->minimum_stock !== null && $quantity <= (float) $balance->minimum_stock;
                    @endphp
                    <tr wire:key="balance-{{ $balance->id }}-{{ $balance->warehouse_id }}">
                        <td class="px-6 py-4"><p class="font-medium text-gray-900">{{ $balance->name }}</p><p class="text-xs text-gray-500">{{ $balance->reference }}@if ($balance->category) · {{ $balance->category->name }} @endif</p></td>
                        <td class="px-6 py-4 text-gray-700">{{ $balance->warehouse_code }} — {{ $balance->warehouse_name }}@if (! $balance->warehouse_is_active)<span class="ms-2 text-xs text-gray-500">{{ __('Inactif') }}</span>@endif</td>
                        <td class="whitespace-nowrap px-6 py-4 text-right font-semibold text-gray-900">{{ number_format($quantity, 3, ',', ' ') }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-right font-semibold text-indigo-700">{{ number_format((float) $balance->total_stock, 3, ',', ' ') }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-right text-gray-600">{{ $balance->minimum_stock ?? '—' }}</td>
                        <td class="px-6 py-4">
                            @if ($isRupture)
                                <span class="rounded-full bg-red-50 px-2 py-1 text-xs text-red-700">{{ __('Rupture') }}</span>
                            @elseif ($isLow)
                                <span class="rounded-full bg-amber-50 px-2 py-1 text-xs text-amber-700">{{ __('Stock faible') }}</span>
                            @else
                                <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs text-emerald-700">{{ __('Disponible') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">{{ __('Aucun solde ne correspond aux filtres.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($balances->hasPages())
        <div class="border-t border-gray-200 px-6 py-4">{{ $balances->links() }}</div>
    @endif
</section>
