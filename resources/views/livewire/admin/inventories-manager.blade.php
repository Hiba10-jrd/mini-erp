<?php

use App\Models\StockInventory;
use App\Models\Warehouse;
use App\Services\InventoryManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $warehouseFilter = 'all';

    public string $statusFilter = 'all';

    public string $warehouseId = '';

    public string $notes = '';

    public function mount(): void
    {
        Gate::authorize('stock.access');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'warehouseFilter', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        Gate::authorize('stock.access');
        $statuses = [
            StockInventory::STATUS_DRAFT,
            StockInventory::STATUS_IN_PROGRESS,
            StockInventory::STATUS_VALIDATED,
            StockInventory::STATUS_CANCELLED,
        ];
        $status = in_array($this->statusFilter, $statuses, true) ? $this->statusFilter : 'all';
        $search = trim($this->search);

        return [
            'inventories' => StockInventory::query()
                ->with(['warehouse:id,code,name,is_active', 'starter:id,name', 'validator:id,name'])
                ->when($search !== '', fn ($query) => $query->where('reference', 'like', "%{$search}%"))
                ->when($this->warehouseFilter !== 'all', fn ($query) => $query->where('warehouse_id', (int) $this->warehouseFilter))
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->latest('id')
                ->paginate(10),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'code', 'name', 'is_active']),
            'activeWarehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }

    public function createInventory(InventoryManagementService $service): void
    {
        Gate::authorize('stock.manage');
        $validated = $this->validate([
            'warehouseId' => [
                'required',
                'integer',
                Rule::exists(Warehouse::class, 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $inventory = $service->create((int) $validated['warehouseId'], $validated['notes']);

        $this->redirectRoute('admin.inventories.show', ['stockInventory' => $inventory->id], navigate: true);
    }
}; ?>

<section class="space-y-6">
    @can('stock.manage')
        <div class="bg-white p-6 shadow-sm sm:rounded-lg">
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Nouvel inventaire') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('Le stock théorique sera figé dès la création pour tous les produits physiques actifs.') }}</p>
            <form wire:submit="createInventory" class="mt-5 grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] md:items-end">
                <div>
                    <x-input-label for="inventory-warehouse" :value="__('Dépôt actif')" />
                    <select id="inventory-warehouse" wire:model="warehouseId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">{{ __('Sélectionner') }}</option>
                        @foreach ($activeWarehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('warehouseId')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="inventory-notes" :value="__('Notes')" />
                    <x-text-input id="inventory-notes" wire:model="notes" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                </div>
                <x-primary-button type="submit" class="justify-center">{{ __('Créer et compter') }}</x-primary-button>
            </form>
        </div>
    @endcan

    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
        <div class="border-b border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Inventaires') }}</h3>
            <div class="mt-5 grid gap-3 sm:grid-cols-3">
                <div>
                    <x-input-label for="inventory-search" :value="__('Référence')" />
                    <x-text-input id="inventory-search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full" placeholder="INV-2026-00001" />
                </div>
                <div>
                    <x-input-label for="inventory-warehouse-filter" :value="__('Dépôt')" />
                    <select id="inventory-warehouse-filter" wire:model.live="warehouseFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="all">{{ __('Tous') }}</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}{{ $warehouse->is_active ? '' : ' · '.__('inactif') }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="inventory-status-filter" :value="__('Statut')" />
                    <select id="inventory-status-filter" wire:model.live="statusFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="all">{{ __('Tous') }}</option>
                        <option value="draft">{{ __('Brouillon') }}</option>
                        <option value="in_progress">{{ __('En cours') }}</option>
                        <option value="validated">{{ __('Validé') }}</option>
                        <option value="cancelled">{{ __('Annulé') }}</option>
                    </select>
                </div>
            </div>
        </div>

        @php
            $statusLabels = ['draft' => __('Brouillon'), 'in_progress' => __('En cours'), 'validated' => __('Validé'), 'cancelled' => __('Annulé')];
            $statusClasses = ['draft' => 'bg-gray-100 text-gray-700', 'in_progress' => 'bg-amber-50 text-amber-700', 'validated' => 'bg-emerald-50 text-emerald-700', 'cancelled' => 'bg-red-50 text-red-700'];
        @endphp
        <div class="overflow-x-auto">
            <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-start text-xs uppercase text-gray-500">
                    <tr><th class="px-4 py-3">{{ __('Référence') }}</th><th class="px-4 py-3">{{ __('Dépôt') }}</th><th class="px-4 py-3">{{ __('Statut') }}</th><th class="px-4 py-3">{{ __('Création') }}</th><th class="px-4 py-3">{{ __('Créé par') }}</th><th class="px-4 py-3">{{ __('Date de validation') }}</th><th class="px-4 py-3">{{ __('Validé par') }}</th><th class="px-4 py-3 text-end">{{ __('Action') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse ($inventories as $inventory)
                        <tr wire:key="inventory-{{ $inventory->id }}">
                            <td class="whitespace-nowrap px-4 py-4 font-medium text-gray-900">{{ $inventory->reference }}</td>
                            <td class="px-4 py-4 text-gray-700">{{ $inventory->warehouse->code }} — {{ $inventory->warehouse->name }}@if (! $inventory->warehouse->is_active)<span class="ms-1 text-xs text-gray-500">{{ __('inactif') }}</span>@endif</td>
                            <td class="px-4 py-4"><span class="rounded-full px-2 py-1 text-xs {{ $statusClasses[$inventory->status] }}">{{ $statusLabels[$inventory->status] }}</span></td>
                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $inventory->started_at->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-4 text-gray-600">{{ $inventory->starter?->name ?? __('Compte supprimé') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $inventory->validated_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="px-4 py-4 text-gray-600">{{ $inventory->validator?->name ?? '—' }}</td>
                            <td class="px-4 py-4 text-end"><a href="{{ route('admin.inventories.show', $inventory) }}" wire:navigate class="text-indigo-600 hover:text-indigo-900">{{ __('Consulter') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">{{ __('Aucun inventaire ne correspond aux filtres.') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>
        @if ($inventories->hasPages())
            <div class="border-t border-gray-200 px-6 py-4">{{ $inventories->links() }}</div>
        @endif
    </div>
</section>
