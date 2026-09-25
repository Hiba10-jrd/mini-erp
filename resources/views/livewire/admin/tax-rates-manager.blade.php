<?php

use App\Models\TaxRate;
use App\Services\TaxRateManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public ?int $editingId = null;

    public string $label = '';

    public string $rate = '';

    public bool $isActive = true;

    public bool $isDefault = false;

    public ?string $feedback = null;

    public function mount(): void
    {
        Gate::authorize('company.administer');
    }

    public function with(): array
    {
        Gate::authorize('company.administer');

        return ['taxRates' => TaxRate::query()->orderByDesc('is_active')->orderBy('rate')->orderBy('label')->get()];
    }

    public function edit(int $taxRateId): void
    {
        Gate::authorize('company.administer');
        $taxRate = TaxRate::query()->findOrFail($taxRateId);
        $this->editingId = $taxRate->id;
        $this->label = $taxRate->label;
        $this->rate = (string) $taxRate->rate;
        $this->isActive = $taxRate->is_active;
        $this->isDefault = $taxRate->is_default;
        $this->resetValidation();
    }

    public function save(TaxRateManagementService $service): void
    {
        Gate::authorize('company.administer');
        $validated = $this->validate([
            'label' => ['required', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'decimal:0,2', 'between:0,100'],
            'isActive' => ['boolean'],
            'isDefault' => ['boolean'],
        ]);

        $service->save($this->editingId, [
            'label' => trim($validated['label']),
            'rate' => $validated['rate'],
            'is_active' => $validated['isActive'],
            'is_default' => $validated['isDefault'],
        ]);
        $this->feedback = __('Le taux de TVA a été enregistré.');
        $this->resetForm();
    }

    public function prepareCreate(): void
    {
        Gate::authorize('company.administer');
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'label', 'rate', 'isDefault', 'feedback');
        $this->isActive = true;
        $this->resetValidation();
    }
}; ?>

<section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
    <div class="border-b border-gray-200 p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div><h3 class="text-lg font-semibold text-gray-900">{{ __('Taux de TVA') }}</h3><p class="mt-1 text-sm text-gray-600">{{ __('Catalogue configurable, sans présumer de la conformité fiscale.') }}</p></div>
            <x-secondary-button type="button" wire:click="prepareCreate">{{ __('Nouveau taux') }}</x-secondary-button>
        </div>
    </div>
    <form wire:submit="save" class="grid gap-5 border-b border-gray-200 p-6 sm:grid-cols-2">
        <div><x-input-label for="tax-label" :value="__('Libellé')" /><x-text-input id="tax-label" wire:model="label" class="mt-1 block w-full" placeholder="Taux standard" /><x-input-error :messages="$errors->get('label')" class="mt-2" /></div>
        <div><x-input-label for="tax-rate" :value="__('Taux (%)')" /><x-text-input id="tax-rate" type="number" step="0.01" min="0" max="100" wire:model="rate" class="mt-1 block w-full" placeholder="20.00" /><x-input-error :messages="$errors->get('rate')" class="mt-2" /></div>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="isActive" class="rounded border-gray-300 text-indigo-600">{{ __('Actif') }}</label>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="isDefault" class="rounded border-gray-300 text-indigo-600">{{ __('Taux par défaut') }}</label>
        <div class="flex gap-3 sm:col-span-2"><x-primary-button type="submit">{{ $editingId ? __('Modifier') : __('Ajouter') }}</x-primary-button>@if($editingId)<x-secondary-button type="button" wire:click="prepareCreate">{{ __('Annuler') }}</x-secondary-button>@endif</div>
        <x-input-error :messages="$errors->get('isDefault')" class="sm:col-span-2" />
    </form>
    @if ($feedback)<p class="border-b border-gray-200 px-6 py-3 text-sm text-emerald-700" role="status">{{ $feedback }}</p>@endif
    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Libellé') }}</th><th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Taux') }}</th><th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('État') }}</th><th class="px-6 py-3 text-right text-xs font-semibold uppercase text-gray-600">{{ __('Action') }}</th></tr></thead><tbody class="divide-y divide-gray-200 bg-white">@forelse($taxRates as $taxRate)<tr wire:key="tax-rate-{{ $taxRate->id }}"><td class="px-6 py-4 text-sm text-gray-900">{{ $taxRate->label }} @if($taxRate->is_default)<span class="ml-2 text-xs font-medium text-indigo-700">{{ __('Par défaut') }}</span>@endif</td><td class="px-6 py-4 text-sm text-gray-600">{{ number_format((float) $taxRate->rate, 2, ',', ' ') }} %</td><td class="px-6 py-4 text-sm text-gray-600">{{ $taxRate->is_active ? __('Actif') : __('Inactif') }}</td><td class="px-6 py-4 text-right"><button type="button" wire:click="edit({{ $taxRate->id }})" class="text-sm text-indigo-600 hover:text-indigo-900">{{ __('Modifier') }}</button></td></tr>@empty<tr><td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">{{ __('Aucun taux configuré.') }}</td></tr>@endforelse</tbody></table></div>
</section>
