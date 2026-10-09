<?php

use App\Models\Warehouse;
use App\Services\WarehouseManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public ?int $editingWarehouseId = null;

    public string $code = '';

    public string $name = '';

    public string $address = '';

    public string $city = '';

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
            'warehouses' => Warehouse::query()->orderByDesc('is_active')->orderBy('name')->get(),
        ];
    }

    public function editWarehouse(int $warehouseId): void
    {
        Gate::authorize('stock.manage');
        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        $this->editingWarehouseId = $warehouse->id;
        $this->code = $warehouse->code;
        $this->name = $warehouse->name;
        $this->address = $warehouse->address ?? '';
        $this->city = $warehouse->city ?? '';
        $this->notes = $warehouse->notes ?? '';
        $this->feedback = null;
        $this->resetValidation();
    }

    public function saveWarehouse(WarehouseManagementService $service): void
    {
        Gate::authorize('stock.manage');
        $this->code = mb_strtoupper(trim($this->code));
        $this->name = trim($this->name);

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique(Warehouse::class, 'code')->ignore($this->editingWarehouseId)],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:5000'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $creating = $this->editingWarehouseId === null;
        $warehouse = $service->save($this->editingWarehouseId, [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'address' => $this->nullableString($validated['address']),
            'city' => $this->nullableString($validated['city']),
            'notes' => $this->nullableString($validated['notes']),
        ]);

        $this->resetWarehouseForm();
        $this->feedback = $creating ? __('Dépôt créé.') : __('Dépôt mis à jour.');
        $this->dispatch('warehouse-updated', warehouseId: $warehouse->id);
    }

    public function toggleWarehouse(int $warehouseId, WarehouseManagementService $service): void
    {
        Gate::authorize('stock.manage');
        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        $service->setActive($warehouse->id, ! $warehouse->is_active);
        $this->feedback = $warehouse->is_active ? __('Dépôt désactivé.') : __('Dépôt activé.');
        $this->dispatch('warehouse-updated', warehouseId: $warehouse->id);
    }

    public function resetWarehouseForm(): void
    {
        Gate::authorize('stock.manage');
        $this->reset('editingWarehouseId', 'code', 'name', 'address', 'city', 'notes');
        $this->resetValidation();
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}; ?>

<section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
    <div class="border-b border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900">{{ __('Dépôts') }}</h3>
        <p class="mt-1 text-sm text-gray-600">{{ __('Les dépôts sont désactivés plutôt que supprimés afin de préserver leur historique.') }}</p>

        @if ($feedback)
            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ $feedback }}</div>
        @endif

        @can('stock.manage')
            <form wire:submit="saveWarehouse" class="mt-5 grid gap-4 md:grid-cols-2">
                <div>
                    <x-input-label for="warehouse-code" :value="__('Code')" />
                    <x-text-input id="warehouse-code" wire:model="code" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('code')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="warehouse-name" :value="__('Nom')" />
                    <x-text-input id="warehouse-name" wire:model="name" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="warehouse-city" :value="__('Ville')" />
                    <x-text-input id="warehouse-city" wire:model="city" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('city')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="warehouse-address" :value="__('Adresse')" />
                    <x-text-input id="warehouse-address" wire:model="address" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('address')" class="mt-2" />
                </div>
                <div class="md:col-span-2">
                    <x-input-label for="warehouse-notes" :value="__('Notes')" />
                    <textarea id="warehouse-notes" wire:model="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                </div>
                <div class="flex gap-3 md:col-span-2">
                    <x-primary-button type="submit">{{ $editingWarehouseId ? __('Enregistrer') : __('Ajouter le dépôt') }}</x-primary-button>
                    @if ($editingWarehouseId)
                        <x-secondary-button type="button" wire:click="resetWarehouseForm">{{ __('Annuler') }}</x-secondary-button>
                    @endif
                </div>
            </form>
        @endcan
    </div>

    <div class="overflow-x-auto">
        <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-start text-xs uppercase text-gray-500">
                <tr><th class="px-6 py-3">{{ __('Code') }}</th><th class="px-6 py-3">{{ __('Dépôt') }}</th><th class="px-6 py-3">{{ __('Localisation') }}</th><th class="px-6 py-3">{{ __('Statut') }}</th><th class="px-6 py-3 text-end">{{ __('Actions') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @forelse ($warehouses as $warehouse)
                    <tr wire:key="warehouse-{{ $warehouse->id }}">
                        <td class="whitespace-nowrap px-6 py-4 font-medium text-gray-900">{{ $warehouse->code }}</td>
                        <td class="px-6 py-4 text-gray-700"><p class="font-medium">{{ $warehouse->name }}</p><p class="text-xs text-gray-500">{{ $warehouse->notes }}</p></td>
                        <td class="px-6 py-4 text-gray-600">{{ collect([$warehouse->address, $warehouse->city])->filter()->join(', ') ?: __('Non renseignée') }}</td>
                        <td class="px-6 py-4"><span class="rounded-full px-2 py-1 text-xs {{ $warehouse->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">{{ $warehouse->is_active ? __('Actif') : __('Inactif') }}</span></td>
                        <td class="whitespace-nowrap px-6 py-4 text-end">
                            @can('stock.manage')
                                <button type="button" wire:click="editWarehouse({{ $warehouse->id }})" class="text-indigo-600 hover:text-indigo-900">{{ __('Modifier') }}</button>
                                <button type="button" wire:click="toggleWarehouse({{ $warehouse->id }})" class="ms-3 text-gray-600 hover:text-gray-900">{{ $warehouse->is_active ? __('Désactiver') : __('Activer') }}</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">{{ __('Aucun dépôt configuré.') }}</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </div>
</section>
