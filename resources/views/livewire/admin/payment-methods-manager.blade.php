<?php

use App\Models\PaymentMethod;
use App\Services\PaymentMethodManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $sortOrder = '0';

    public bool $isActive = true;

    public ?string $feedback = null;

    public function mount(): void
    {
        Gate::authorize('company.administer');
    }

    public function with(): array
    {
        Gate::authorize('company.administer');

        return ['paymentMethods' => PaymentMethod::query()->orderBy('sort_order')->orderBy('name')->get()];
    }

    public function edit(int $paymentMethodId): void
    {
        Gate::authorize('company.administer');
        $method = PaymentMethod::query()->findOrFail($paymentMethodId);
        $this->editingId = $method->id;
        $this->name = $method->name;
        $this->sortOrder = (string) $method->sort_order;
        $this->isActive = $method->is_active;
        $this->resetValidation();
    }

    public function save(PaymentMethodManagementService $service): void
    {
        Gate::authorize('company.administer');
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:65535'],
            'isActive' => ['boolean'],
        ]);

        $service->save($this->editingId, [
            'name' => trim($validated['name']),
            'sort_order' => $validated['sortOrder'],
            'is_active' => $validated['isActive'],
        ]);
        $this->feedback = __('Le mode de paiement a été enregistré.');
        $this->resetForm();
    }

    public function prepareCreate(): void
    {
        Gate::authorize('company.administer');
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'feedback');
        $this->sortOrder = '0';
        $this->isActive = true;
        $this->resetValidation();
    }
}; ?>

<section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
    <div class="border-b border-gray-200 p-6"><div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h3 class="text-lg font-semibold text-gray-900">{{ __('Modes de paiement') }}</h3><p class="mt-1 text-sm text-gray-600">{{ __('Définissez les moyens acceptés par l’entreprise.') }}</p></div><x-secondary-button type="button" wire:click="prepareCreate">{{ __('Nouveau mode') }}</x-secondary-button></div></div>
    <form wire:submit="save" class="grid gap-5 border-b border-gray-200 p-6 sm:grid-cols-3"><div class="sm:col-span-2"><x-input-label for="payment-method-name" :value="__('Nom')" /><x-text-input id="payment-method-name" wire:model="name" class="mt-1 block w-full" placeholder="Virement bancaire" /><x-input-error :messages="$errors->get('name')" class="mt-2" /></div><div><x-input-label for="payment-method-order" :value="__('Ordre')" /><x-text-input id="payment-method-order" type="number" min="0" wire:model="sortOrder" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('sortOrder')" class="mt-2" /></div><label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="isActive" class="rounded border-gray-300 text-indigo-600">{{ __('Disponible') }}</label><div class="flex gap-3 sm:col-span-3"><x-primary-button type="submit">{{ $editingId ? __('Modifier') : __('Ajouter') }}</x-primary-button>@if($editingId)<x-secondary-button type="button" wire:click="prepareCreate">{{ __('Annuler') }}</x-secondary-button>@endif</div></form>
    @if ($feedback)<p class="border-b border-gray-200 px-6 py-3 text-sm text-emerald-700" role="status">{{ $feedback }}</p>@endif
    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Mode') }}</th><th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('État') }}</th><th class="px-6 py-3 text-right text-xs font-semibold uppercase text-gray-600">{{ __('Action') }}</th></tr></thead><tbody class="divide-y divide-gray-200 bg-white">@forelse($paymentMethods as $method)<tr wire:key="payment-method-{{ $method->id }}"><td class="px-6 py-4 text-sm text-gray-900">{{ $method->name }}</td><td class="px-6 py-4 text-sm text-gray-600">{{ $method->is_active ? __('Disponible') : __('Indisponible') }}</td><td class="px-6 py-4 text-right"><button type="button" wire:click="edit({{ $method->id }})" class="text-sm text-indigo-600 hover:text-indigo-900">{{ __('Modifier') }}</button></td></tr>@empty<tr><td colspan="3" class="px-6 py-8 text-center text-sm text-gray-500">{{ __('Aucun mode configuré.') }}</td></tr>@endforelse</tbody></table></div>
</section>
