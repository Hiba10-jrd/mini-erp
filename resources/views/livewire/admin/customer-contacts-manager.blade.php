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
<section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
    <div class="border-b border-gray-200 p-6"><div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h3 class="text-lg font-semibold text-gray-900">{{ __('Contacts') }}</h3><p class="mt-1 text-sm text-gray-600">{{ __('Les contacts restent associés à ce client et peuvent être désactivés.') }}</p></div>@can('customers.manage')<x-secondary-button type="button" wire:click="prepareCreate">{{ __('Ajouter un contact') }}</x-secondary-button>@endcan</div></div>
    @if($feedback)<p class="border-b border-gray-200 px-6 py-3 text-sm text-emerald-700" role="status">{{ $feedback }}</p>@endif
    @can('customers.manage')<form wire:submit="saveContact" class="grid gap-5 border-b border-gray-200 p-6 sm:grid-cols-2"><div><x-input-label for="contact-first-name" :value="__('Prénom')" /><x-text-input id="contact-first-name" wire:model="firstName" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('firstName')" class="mt-2" /></div><div><x-input-label for="contact-last-name" :value="__('Nom')" /><x-text-input id="contact-last-name" wire:model="lastName" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('lastName')" class="mt-2" /></div><div><x-input-label for="contact-job-title" :value="__('Fonction')" /><x-text-input id="contact-job-title" wire:model="jobTitle" class="mt-1 block w-full" /></div><div><x-input-label for="contact-email" :value="__('Email')" /><x-text-input id="contact-email" type="email" wire:model="email" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('email')" class="mt-2" /></div><div><x-input-label for="contact-phone" :value="__('Téléphone')" /><x-text-input id="contact-phone" wire:model="phone" class="mt-1 block w-full" /></div><label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="isPrimary" class="rounded border-gray-300 text-indigo-600">{{ __('Contact principal') }}</label><div class="flex gap-3 sm:col-span-2"><x-primary-button type="submit">{{ $editingId ? __('Modifier') : __('Ajouter') }}</x-primary-button>@if($editingId)<x-secondary-button type="button" wire:click="prepareCreate">{{ __('Annuler') }}</x-secondary-button>@endif</div></form>@endcan
    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Nom') }}</th><th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Fonction') }}</th><th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Coordonnées') }}</th><th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('État') }}</th><th class="px-6 py-3 text-right text-xs font-semibold uppercase text-gray-600">{{ __('Actions') }}</th></tr></thead><tbody class="divide-y divide-gray-200 bg-white">@forelse($contacts as $contact)<tr wire:key="customer-contact-{{ $contact->id }}"><td class="px-6 py-4 text-sm text-gray-900">{{ $contact->first_name }} {{ $contact->last_name }} @if($contact->is_primary)<span class="ml-2 text-xs text-indigo-700">{{ __('Principal') }}</span>@endif</td><td class="px-6 py-4 text-sm text-gray-600">{{ $contact->job_title }}</td><td class="px-6 py-4 text-sm text-gray-600">{{ $contact->email }}<br>{{ $contact->phone }}</td><td class="px-6 py-4 text-sm text-gray-600">{{ $contact->is_active ? __('Actif') : __('Désactivé') }}</td><td class="px-6 py-4 text-right text-sm">@can('customers.manage') @if($contact->is_active)<button type="button" wire:click="editContact({{ $contact->id }})" class="mr-3 text-indigo-600 hover:text-indigo-900">{{ __('Modifier') }}</button><button type="button" wire:click="archiveContact({{ $contact->id }})" wire:confirm="{{ __('Désactiver ce contact ?') }}" class="text-red-600 hover:text-red-900">{{ __('Désactiver') }}</button>@endif @endcan</td></tr>@empty<tr><td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">{{ __('Aucun contact enregistré.') }}</td></tr>@endforelse</tbody></table></div>
</section>
@else
<p class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-700">
    {{ __('Les coordonnées de ce client particulier sont directement enregistrées dans sa fiche.') }}
</p>
@endif
</div>
