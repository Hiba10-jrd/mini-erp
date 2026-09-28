<?php

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\StockManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    public string $operationType = 'entry';

    public string $productId = '';

    public string $warehouseId = '';

    public string $destinationWarehouseId = '';

    public string $quantity = '';

    public string $reference = '';

    public string $notes = '';

    public ?string $feedback = null;

    public function mount(): void
    {
        Gate::authorize('stock.access');
    }

    public function with(): array
    {
        Gate::authorize('stock.access');

        return [
            'products' => Product::query()
                ->where('type', 'product')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'reference', 'name']),
            'warehouses' => Warehouse::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
        ];
    }

    #[On('warehouse-updated')]
    public function refreshWarehouses(int $warehouseId): void
    {
        Gate::authorize('stock.access');
    }

    public function saveMovement(StockManagementService $service): void
    {
        Gate::authorize('stock.manage');

        $validated = $this->validate([
            'operationType' => ['required', Rule::in([
                'entry',
                'exit',
                'transfer',
                'return_in',
                'return_out',
                'adjustment_positive',
                'adjustment_negative',
            ])],
            'productId' => [
                'required',
                'integer',
                Rule::exists(Product::class, 'id')->where(fn ($query) => $query->where('type', 'product')->where('is_active', true)),
            ],
            'warehouseId' => [
                'required',
                'integer',
                Rule::exists(Warehouse::class, 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'destinationWarehouseId' => [
                Rule::requiredIf($this->operationType === 'transfer'),
                'nullable',
                'integer',
                'different:warehouseId',
                Rule::exists(Warehouse::class, 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => [Rule::requiredIf(str_starts_with($this->operationType, 'adjustment_')), 'nullable', 'string', 'max:5000'],
        ]);

        $productId = (int) $validated['productId'];
        $warehouseId = (int) $validated['warehouseId'];
        $reference = $this->nullableString($validated['reference']);
        $notes = $this->nullableString($validated['notes']);

        match ($validated['operationType']) {
            'entry' => $service->receive($productId, $warehouseId, $validated['quantity'], $reference, $notes),
            'exit' => $service->issue($productId, $warehouseId, $validated['quantity'], $reference, $notes),
            'transfer' => $service->transfer($productId, $warehouseId, (int) $validated['destinationWarehouseId'], $validated['quantity'], $reference, $notes),
            'return_in' => $service->returnIn($productId, $warehouseId, $validated['quantity'], $reference, $notes),
            'return_out' => $service->returnOut($productId, $warehouseId, $validated['quantity'], $reference, $notes),
            'adjustment_positive' => $service->adjust($productId, $warehouseId, 'positive', $validated['quantity'], $notes ?? '', $reference),
            'adjustment_negative' => $service->adjust($productId, $warehouseId, 'negative', $validated['quantity'], $notes ?? '', $reference),
        };

        $this->reset('productId', 'warehouseId', 'destinationWarehouseId', 'quantity', 'reference', 'notes');
        $this->resetValidation();
        $this->feedback = __('Mouvement de stock enregistré.');
        $this->dispatch('stock-updated', productId: $productId);
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}; ?>

<section class="bg-white p-6 shadow-sm sm:rounded-lg">
    <h3 class="text-lg font-semibold text-gray-900">{{ __('Opération de stock') }}</h3>
    <p class="mt-1 text-sm text-gray-600">{{ __('Chaque opération génère un historique immuable avec les quantités avant et après.') }}</p>

    @if ($feedback)
        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ $feedback }}</div>
    @endif

    @can('stock.manage')
        <form wire:submit="saveMovement" class="mt-5 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <div>
                <x-input-label for="stock-operation" :value="__('Type d’opération')" />
                <select id="stock-operation" wire:model.live="operationType" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="entry">{{ __('Entrée') }}</option>
                    <option value="exit">{{ __('Sortie') }}</option>
                    <option value="transfer">{{ __('Transfert') }}</option>
                    <option value="return_in">{{ __('Retour entrant') }}</option>
                    <option value="return_out">{{ __('Retour sortant') }}</option>
                    <option value="adjustment_positive">{{ __('Ajustement positif') }}</option>
                    <option value="adjustment_negative">{{ __('Ajustement négatif') }}</option>
                </select>
                <x-input-error :messages="$errors->get('operationType')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="stock-product" :value="__('Produit')" />
                <select id="stock-product" wire:model="productId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">{{ __('Sélectionner') }}</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->reference }} — {{ $product->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('productId')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="stock-warehouse" :value="$operationType === 'transfer' ? __('Dépôt source') : __('Dépôt')" />
                <select id="stock-warehouse" wire:model="warehouseId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">{{ __('Sélectionner') }}</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('warehouseId')" class="mt-2" />
            </div>
            @if ($operationType === 'transfer')
                <div>
                    <x-input-label for="stock-destination" :value="__('Dépôt destination')" />
                    <select id="stock-destination" wire:model="destinationWarehouseId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">{{ __('Sélectionner') }}</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('destinationWarehouseId')" class="mt-2" />
                </div>
            @endif
            <div>
                <x-input-label for="stock-quantity" :value="__('Quantité')" />
                <x-text-input id="stock-quantity" type="number" min="0.001" step="0.001" wire:model="quantity" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="stock-reference" :value="__('Référence')" />
                <x-text-input id="stock-reference" wire:model="reference" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('reference')" class="mt-2" />
            </div>
            <div class="md:col-span-2 lg:col-span-3">
                <x-input-label for="stock-notes" :value="str_starts_with($operationType, 'adjustment_') ? __('Motif obligatoire') : __('Notes')" />
                <textarea id="stock-notes" wire:model="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-2" />
            </div>
            <div class="md:col-span-2 lg:col-span-3">
                <x-primary-button type="submit">{{ __('Valider l’opération') }}</x-primary-button>
            </div>
        </form>
    @else
        <div class="mt-4 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ __('Votre accès est limité à la consultation des stocks.') }}</div>
    @endcan
</section>
