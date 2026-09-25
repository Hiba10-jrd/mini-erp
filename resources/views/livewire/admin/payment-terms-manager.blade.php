<?php

use App\Models\PaymentTerm;
use App\Services\PaymentTermManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public ?int $editingId = null;

    public string $label = '';

    public string $dueDays = '0';

    public string $description = '';

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

        return ['paymentTerms' => PaymentTerm::query()->orderByDesc('is_active')->orderBy('due_days')->orderBy('label')->get()];
    }

    public function edit(int $paymentTermId): void
    {
        Gate::authorize('company.administer');
        $term = PaymentTerm::query()->findOrFail($paymentTermId);
        $this->editingId = $term->id;
        $this->label = $term->label;
        $this->dueDays = (string) $term->due_days;
        $this->description = $term->description ?? '';
        $this->isActive = $term->is_active;
        $this->isDefault = $term->is_default;
        $this->resetValidation();
    }

    public function save(PaymentTermManagementService $service): void
    {
        Gate::authorize('company.administer');
        $validated = $this->validate([
            'label' => ['required', 'string', 'max:100'],
            'dueDays' => ['required', 'integer', 'min:0', 'max:3650'],
            'description' => ['nullable', 'string', 'max:1000'],
            'isActive' => ['boolean'],
            'isDefault' => ['boolean'],
        ]);

        $service->save($this->editingId, [
            'label' => trim($validated['label']),
            'due_days' => $validated['dueDays'],
            'description' => trim($validated['description'] ?? '') ?: null,
            'is_active' => $validated['isActive'],
            'is_default' => $validated['isDefault'],
        ]);
        $this->feedback = __('La condition de paiement a été enregistrée.');
        $this->resetForm();
    }

    public function prepareCreate(): void
    {
        Gate::authorize('company.administer');
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'label', 'description', 'isDefault', 'feedback');
        $this->dueDays = '0';
        $this->isActive = true;
        $this->resetValidation();
    }
}; ?>

<section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
    <div class="border-b border-gray-200 p-6"><div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h3 class="text-lg font-semibold text-gray-900">{{ __('Conditions de paiement') }}</h3><p class="mt-1 text-sm text-gray-600">{{ __('Préparez les conditions réutilisables dans les futurs documents.') }}</p></div><x-secondary-button type="button" wire:click="prepareCreate">{{ __('Nouvelle condition') }}</x-secondary-button></div></div>
    <form wire:submit="save" class="grid gap-5 border-b border-gray-200 p-6 sm:grid-cols-2"><div><x-input-label for="payment-term-label" :value="__('Libellé')" /><x-text-input id="payment-term-label" wire:model="label" class="mt-1 block w-full" placeholder="Paiement à 30 jours" /><x-input-error :messages="$errors->get('label')" class="mt-2" /></div><div><x-input-label for="payment-term-days" :value="__('Délai (jours)')" /><x-text-input id="payment-term-days" type="number" min="0" wire:model="dueDays" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('dueDays')" class="mt-2" /></div><div class="sm:col-span-2"><x-input-label for="payment-term-description" :value="__('Description')" /><textarea id="payment-term-description" wire:model="description" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea><x-input-error :messages="$errors->get('description')" class="mt-2" /></div><label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="isActive" class="rounded border-gray-300 text-indigo-600">{{ __('Active') }}</label><label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="isDefault" class="rounded border-gray-300 text-indigo-600">{{ __('Par défaut') }}</label><div class="flex gap-3 sm:col-span-2"><x-primary-button type="submit">{{ $editingId ? __('Modifier') : __('Ajouter') }}</x-primary-button>@if($editingId)<x-secondary-button type="button" wire:click="prepareCreate">{{ __('Annuler') }}</x-secondary-button>@endif</div></form>
    @if ($feedback)<p class="border-b border-gray-200 px-6 py-3 text-sm text-emerald-700" role="status">{{ $feedback }}</p>@endif
    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Condition') }}</th><th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Délai') }}</th><th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('État') }}</th><th class="px-6 py-3 text-right text-xs font-semibold uppercase text-gray-600">{{ __('Action') }}</th></tr></thead><tbody class="divide-y divide-gray-200 bg-white">@forelse($paymentTerms as $term)<tr wire:key="payment-term-{{ $term->id }}"><td class="px-6 py-4 text-sm text-gray-900">{{ $term->label }} @if($term->is_default)<span class="ml-2 text-xs font-medium text-indigo-700">{{ __('Par défaut') }}</span>@endif</td><td class="px-6 py-4 text-sm text-gray-600">{{ $term->due_days }} {{ __('jours') }}</td><td class="px-6 py-4 text-sm text-gray-600">{{ $term->is_active ? __('Active') : __('Inactive') }}</td><td class="px-6 py-4 text-right"><button type="button" wire:click="edit({{ $term->id }})" class="text-sm text-indigo-600 hover:text-indigo-900">{{ __('Modifier') }}</button></td></tr>@empty<tr><td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">{{ __('Aucune condition configurée.') }}</td></tr>@endforelse</tbody></table></div>
</section>
