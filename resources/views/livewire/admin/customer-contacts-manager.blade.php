<?php

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Services\CustomerContactManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public int $customerId;

    #[Locked]
    public ?int $editingId = null;

    public string $firstName = '';

    public string $lastName = '';

    public string $jobTitle = '';

    public string $email = '';

    public string $phone = '';

    public bool $isPrimary = false;

    public ?string $feedback = null;

    public function mount(int $customerId): void
    {
        Gate::authorize('customers.access');
        $this->customerId = $customerId;
        Customer::query()->findOrFail($customerId);
    }

    public function with(): array
    {
        Gate::authorize('customers.access');

        return ['customer' => Customer::query()->findOrFail($this->customerId), 'contacts' => CustomerContact::query()->where('customer_id', $this->customerId)->orderByDesc('is_active')->orderByDesc('is_primary')->orderBy('last_name')->get()];
    }

    public function editContact(int $contactId): void
    {
        Gate::authorize('customers.manage');
        $this->ensureCustomerIsCompany();
        $contact = CustomerContact::query()->where('customer_id', $this->customerId)->findOrFail($contactId);
        $this->editingId = $contact->id;
        $this->firstName = $contact->first_name;
        $this->lastName = $contact->last_name;
        $this->jobTitle = $contact->job_title ?? '';
        $this->email = $contact->email ?? '';
        $this->phone = $contact->phone ?? '';
        $this->isPrimary = $contact->is_primary;
        $this->feedback = null;
        $this->resetValidation();
    }

    public function prepareCreate(): void
    {
        Gate::authorize('customers.manage');
        $this->ensureCustomerIsCompany();
        $this->resetForm();
    }

    public function saveContact(CustomerContactManagementService $service): void
    {
        Gate::authorize('customers.manage');
        $this->ensureCustomerIsCompany();
        $validated = $this->validate([
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'jobTitle' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'isPrimary' => ['boolean'],
        ]);
        $attributes = [
            'first_name' => trim($validated['firstName']),
            'last_name' => trim($validated['lastName']),
            'job_title' => trim($validated['jobTitle'] ?? '') ?: null,
            'email' => trim($validated['email'] ?? '') ?: null,
            'phone' => trim($validated['phone'] ?? '') ?: null,
            'is_primary' => $validated['isPrimary'],
            'is_active' => true,
        ];
        if ($this->editingId === null) {
            $service->create($this->customerId, $attributes);
            $this->feedback = __('Contact ajouté.');
        } else {
            $service->update($this->customerId, $this->editingId, $attributes);
            $this->feedback = __('Contact mis à jour.');
        }
        $this->resetForm();
    }

    public function archiveContact(int $contactId, CustomerContactManagementService $service): void
    {
        Gate::authorize('customers.manage');
        $this->ensureCustomerIsCompany();
        $service->archive($this->customerId, $contactId);
        $this->feedback = __('Contact désactivé.');
    }

    private function ensureCustomerIsCompany(): void
    {
        $customer = Customer::query()->findOrFail($this->customerId);

        if ($customer->customer_type !== 'company') {
            throw ValidationException::withMessages([
                'customer' => __('Les contacts ne peuvent être gérés que pour un client de type entreprise.'),
            ]);
        }
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'firstName', 'lastName', 'jobTitle', 'email', 'phone', 'isPrimary');
        $this->resetValidation();
    }
}; ?>

<div>
@if($customer->customer_type === 'company')
<section class="erp-panel">
    <div class="erp-panel-header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h3>{{ __('Contacts') }}</h3>
                <p>{{ __('Les contacts restent associés à ce client et peuvent être désactivés.') }}</p>
            </div>
            @can('customers.manage')
                <x-secondary-button type="button" wire:click="prepareCreate" class="erp-secondary-button erp-action erp-action-primary">{{ __('Ajouter un contact') }}</x-secondary-button>
            @endcan
        </div>
    </div>

    @if($feedback)
        <p class="border-b border-slate-200 px-6 py-3 text-sm font-medium text-emerald-700" role="status">{{ $feedback }}</p>
    @endif

    @can('customers.manage')
        <form wire:submit="saveContact" class="erp-card-section grid gap-5 sm:grid-cols-2">
            <div>
                <x-input-label for="contact-first-name" :value="__('Prénom')" />
                <x-text-input id="contact-first-name" wire:model="firstName" class="erp-input-field mt-1 block w-full" />
                <x-input-error :messages="$errors->get('firstName')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="contact-last-name" :value="__('Nom')" />
                <x-text-input id="contact-last-name" wire:model="lastName" class="erp-input-field mt-1 block w-full" />
                <x-input-error :messages="$errors->get('lastName')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="contact-job-title" :value="__('Fonction')" />
                <x-text-input id="contact-job-title" wire:model="jobTitle" class="erp-input-field mt-1 block w-full" />
            </div>
            <div>
                <x-input-label for="contact-email" :value="__('Email')" />
                <x-text-input id="contact-email" type="email" wire:model="email" class="erp-input-field mt-1 block w-full" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="contact-phone" :value="__('Téléphone')" />
                <x-text-input id="contact-phone" wire:model="phone" class="erp-input-field mt-1 block w-full" />
            </div>
           <div class="flex items-center gap-2 pt-7">
    <input
        id="contact-is-primary"
        type="checkbox"
        wire:model="isPrimary"
        class="rounded border-slate-300 text-violet-600"
    >

    <label
        for="contact-is-primary"
        class="text-sm font-medium text-slate-200"
    >
        {{ __('Contact principal') }}
    </label>
</div>

            <div class="flex gap-3 sm:col-span-2">
                <x-primary-button type="submit" class="erp-primary-button">{{ $editingId ? __('Modifier') : __('Ajouter') }}</x-primary-button>
                @if($editingId)
                    <x-secondary-button type="button" wire:click="prepareCreate" class="erp-secondary-button erp-action erp-action-neutral">{{ __('Annuler') }}</x-secondary-button>
                @endif
            </div>
        </form>
    @endcan

    <div class="erp-table-shell">
        <div class="overflow-x-auto">
            <table class="erp-table-base">
                <thead>
                    <tr>
                        <th>{{ __('Nom') }}</th>
                        <th>{{ __('Fonction') }}</th>
                        <th>{{ __('Coordonnées') }}</th>
                        <th>{{ __('État') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contacts as $contact)
                        <tr wire:key="customer-contact-{{ $contact->id }}">
                            <td>
                                {{ $contact->first_name }} {{ $contact->last_name }}
                                @if($contact->is_primary)
                                    <span class="ms-2 text-xs font-semibold text-violet-700">{{ __('Principal') }}</span>
                                @endif
                            </td>
                            <td>{{ $contact->job_title }}</td>
                            <td>{{ $contact->email }}<br>{{ $contact->phone }}</td>
                            <td>
                                <span class="erp-status-pill {{ $contact->is_active ? 'is-active' : 'is-archived' }}">
                                    {{ $contact->is_active ? __('Actif') : __('Désactivé') }}
                                </span>
                            </td>
                            <td class="text-end">
                                @can('customers.manage')
                                    @if($contact->is_active)
                                        <div class="erp-inline-actions">
                                            <button type="button" wire:click="editContact({{ $contact->id }})" class="erp-secondary-button erp-action erp-action-edit">{{ __('Modifier') }}</button>
                                            <button type="button" wire:click="archiveContact({{ $contact->id }})" wire:confirm="{{ __('Désactiver ce contact ?') }}" class="erp-link-button-danger erp-action erp-action-warning">{{ __('Désactiver') }}</button>
                                        </div>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="erp-row-empty">{{ __('Aucun contact enregistré.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@else
<p class="rounded-lg border border-violet-100 bg-violet-50 px-4 py-3 text-sm font-medium text-violet-700">
    {{ __('Les coordonnées de ce client particulier sont directement enregistrées dans sa fiche.') }}
</p>
@endif
</div>
