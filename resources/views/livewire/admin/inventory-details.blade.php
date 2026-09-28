<?php

use App\Models\StockInventory;
use App\Models\StockInventoryLine;
use App\Services\InventoryManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public int $inventoryId;

    /** @var array<int, string> */
    public array $actualQuantities = [];

    /** @var array<int, string> */
    public array $lineNotes = [];

    public ?string $feedback = null;

    public function mount(int $inventoryId): void
    {
        Gate::authorize('stock.access');
        $this->inventoryId = $inventoryId;
        $inventory = StockInventory::query()->with('lines')->findOrFail($inventoryId);

        foreach ($inventory->lines as $line) {
            $this->actualQuantities[$line->id] = $line->actual_quantity ?? '';
            $this->lineNotes[$line->id] = $line->notes ?? '';
        }
    }

    public function with(): array
    {
        Gate::authorize('stock.access');
        $inventory = StockInventory::query()
            ->with([
                'warehouse:id,code,name,is_active',
                'starter:id,name',
                'validator:id,name',
                'lines' => fn ($query) => $query->orderBy('product_id'),
                'lines.product.unit',
            ])
            ->findOrFail($this->inventoryId);

        return [
            'inventory' => $inventory,
            'lineCount' => $inventory->lines->count(),
            'differenceCount' => $inventory->lines->filter(fn (StockInventoryLine $line): bool => $line->difference !== null && (float) $line->difference !== 0.0)->count(),
            'missingCount' => $inventory->lines->whereNull('actual_quantity')->count(),
        ];
    }

    public function saveLine(int $lineId, InventoryManagementService $service): void
    {
        Gate::authorize('stock.manage');
        $quantityKey = "actualQuantities.{$lineId}";
        $notesKey = "lineNotes.{$lineId}";
        $validated = $this->validate([
            $quantityKey => ['required', 'numeric', 'min:0', 'max:999999999999.999', 'decimal:0,3'],
            $notesKey => ['nullable', 'string', 'max:5000'],
        ]);
        $line = $service->saveLine(
            $this->inventoryId,
            $lineId,
            $validated['actualQuantities'][$lineId],
            $validated['lineNotes'][$lineId] ?? null,
        );
        $this->actualQuantities[$lineId] = $line->actual_quantity;
        $this->lineNotes[$lineId] = $line->notes ?? '';
        $this->feedback = __('Ligne enregistrée pour :product.', ['product' => $line->product->name]);
    }

    public function validateInventory(InventoryManagementService $service): void
    {
        Gate::authorize('stock.manage');
        $inventory = $service->validate($this->inventoryId);

        foreach ($inventory->lines as $line) {
            $this->dispatch('stock-updated', productId: $line->product_id);
        }

        $this->dispatch('inventory-updated', inventoryId: $inventory->id);
        $this->feedback = __('Inventaire validé et stocks corrigés.');
    }

    public function cancelInventory(InventoryManagementService $service): void
    {
        Gate::authorize('stock.manage');
        $inventory = $service->cancel($this->inventoryId);
        $this->dispatch('inventory-updated', inventoryId: $inventory->id);
        $this->feedback = __('Inventaire annulé sans mouvement de stock.');
    }
}; ?>

<section class="space-y-6">
    <div class="flex justify-end">
        <a href="{{ route('admin.inventories.index') }}" wire:navigate class="text-sm text-indigo-600 hover:text-indigo-900">{{ __('Retour aux inventaires') }}</a>
    </div>

    @if ($feedback)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ $feedback }}</div>
    @endif
    <x-input-error :messages="$errors->get('inventory')" />

    @php
        $statusLabels = ['draft' => __('Brouillon'), 'in_progress' => __('En cours'), 'validated' => __('Validé'), 'cancelled' => __('Annulé')];
        $statusClasses = ['draft' => 'bg-gray-100 text-gray-700', 'in_progress' => 'bg-amber-50 text-amber-700', 'validated' => 'bg-emerald-50 text-emerald-700', 'cancelled' => 'bg-red-50 text-red-700'];
    @endphp
    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
        <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ $inventory->reference }}</p>
                <h3 class="text-xl font-semibold text-gray-900">{{ $inventory->warehouse->code }} — {{ $inventory->warehouse->name }}</h3>
                @if (! $inventory->warehouse->is_active)<p class="mt-1 text-sm text-gray-500">{{ __('Ce dépôt est actuellement inactif, mais l’inventaire reste consultable.') }}</p>@endif
            </div>
            <span class="rounded-full px-3 py-1 text-sm {{ $statusClasses[$inventory->status] }}">{{ $statusLabels[$inventory->status] }}</span>
        </div>
        <dl class="mt-5 grid gap-4 text-sm text-gray-600 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="font-medium text-gray-900">{{ __('Créé le') }}</dt><dd>{{ $inventory->started_at->format('d/m/Y H:i') }}</dd></div>
            <div><dt class="font-medium text-gray-900">{{ __('Créé par') }}</dt><dd>{{ $inventory->starter?->name ?? __('Compte supprimé') }}</dd></div>
            <div><dt class="font-medium text-gray-900">{{ __('Validé le') }}</dt><dd>{{ $inventory->validated_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
            <div><dt class="font-medium text-gray-900">{{ __('Validé par') }}</dt><dd>{{ $inventory->validator?->name ?? '—' }}</dd></div>
        </dl>
        @if ($inventory->notes)<div class="mt-5 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-700"><span class="font-medium">{{ __('Notes :') }}</span> {{ $inventory->notes }}</div>@endif
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs font-medium uppercase text-gray-500">{{ __('Lignes') }}</p><p class="mt-1 text-2xl font-semibold text-gray-900">{{ $lineCount }}</p></div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs font-medium uppercase text-gray-500">{{ __('Écarts') }}</p><p class="mt-1 text-2xl font-semibold text-gray-900">{{ $differenceCount }}</p></div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs font-medium uppercase text-gray-500">{{ __('Non saisies') }}</p><p class="mt-1 text-2xl font-semibold {{ $missingCount ? 'text-amber-700' : 'text-emerald-700' }}">{{ $missingCount }}</p></div>
    </div>

    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr><th class="px-4 py-3">{{ __('Produit') }}</th><th class="px-4 py-3">{{ __('Unité') }}</th><th class="px-4 py-3 text-right">{{ __('Théorique') }}</th><th class="px-4 py-3">{{ __('Stock réel') }}</th><th class="px-4 py-3">{{ __('Écart') }}</th><th class="px-4 py-3">{{ __('Note') }}</th>@can('stock.manage')<th class="px-4 py-3 text-right">{{ __('Action') }}</th>@endcan</tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @foreach ($inventory->lines as $line)
                        @php $difference = $line->difference === null ? null : (float) $line->difference; @endphp
                        <tr wire:key="inventory-line-{{ $line->id }}">
                            <td class="px-4 py-4"><p class="font-medium text-gray-900">{{ $line->product->name }}</p><p class="text-xs text-gray-500">{{ $line->product->reference }}</p></td>
                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $line->product->unit->symbol }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-medium text-gray-900">{{ number_format((float) $line->theoretical_quantity, 3, ',', ' ') }}</td>
                            <td class="min-w-40 px-4 py-4">
                                @if ($inventory->isEditable() && auth()->user()->can('stock.manage'))
                                    <x-text-input type="number" min="0" step="0.001" wire:model="actualQuantities.{{ $line->id }}" class="block w-full" />
                                    <x-input-error :messages="$errors->get('actualQuantities.'.$line->id)" class="mt-1" />
                                @else
                                    <span class="font-medium text-gray-900">{{ $line->actual_quantity === null ? '—' : number_format((float) $line->actual_quantity, 3, ',', ' ') }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-4">
                                @if ($difference === null)
                                    <span class="text-gray-500">{{ __('Non saisi') }}</span>
                                @elseif ($difference > 0)
                                    <span class="font-medium text-emerald-700">{{ __('Surplus') }} +{{ number_format($difference, 3, ',', ' ') }}</span>
                                @elseif ($difference < 0)
                                    <span class="font-medium text-red-700">{{ __('Manque') }} {{ number_format($difference, 3, ',', ' ') }}</span>
                                @else
                                    <span class="font-medium text-gray-700">{{ __('Conforme') }} 0,000</span>
                                @endif
                            </td>
                            <td class="min-w-52 px-4 py-4">
                                @if ($inventory->isEditable() && auth()->user()->can('stock.manage'))
                                    <x-text-input wire:model="lineNotes.{{ $line->id }}" class="block w-full" />
                                    <x-input-error :messages="$errors->get('lineNotes.'.$line->id)" class="mt-1" />
                                @else
                                    <span class="text-gray-600">{{ $line->notes ?? '—' }}</span>
                                @endif
                            </td>
                            @can('stock.manage')
                                <td class="px-4 py-4 text-right">
                                    @if ($inventory->isEditable())
                                        <x-secondary-button type="button" wire:click="saveLine({{ $line->id }})">{{ __('Enregistrer') }}</x-secondary-button>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($inventory->isEditable() && auth()->user()->can('stock.manage'))
        <div class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-gray-600">{{ __('La validation est définitive. Les corrections utiliseront le stock courant verrouillé au moment de la validation.') }}</p>
            <div class="flex shrink-0 gap-3">
                <x-secondary-button type="button" wire:click="cancelInventory" wire:confirm="{{ __('Annuler définitivement cet inventaire sans corriger le stock ?') }}">{{ __('Annuler l’inventaire') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="validateInventory" wire:confirm="{{ __('Valider définitivement cet inventaire et générer les corrections de stock ?') }}">{{ __('VALIDER L’INVENTAIRE') }}</x-primary-button>
            </div>
        </div>
    @endif
</section>
