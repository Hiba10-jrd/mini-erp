<?php

use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Services\CustomerManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Volt\Component as VoltComponent;
use Livewire\WithPagination;

new class extends VoltComponent
{
    use WithPagination;

    public string $search = '';

    public string $typeFilter = 'all';

    public string $statusFilter = 'active';

    public string $customerType = 'individual';

    public string $name = '';

    public string $tradeName = '';

    public string $ice = '';

    public string $taxId = '';

    public string $commercialRegister = '';

    public string $address = '';

    public string $city = '';

    public string $country = '';

    public string $phone = '';

    public string $email = '';

    public string $notes = '';

    public string $paymentTermId = '';

    public string $creditLimit = '';

    #[Locked]
    public ?int $editingId = null;

    public ?string $feedback = null;

    public function mount(): void
    {
        Gate::authorize('customers.access');
    }

    public function with(): array
    {
        Gate::authorize('customers.access');
        $type = in_array($this->typeFilter, ['all', 'individual', 'company'], true) ? $this->typeFilter : 'all';
        $status = in_array($this->statusFilter, ['active', 'archived', 'all'], true) ? $this->statusFilter : 'active';
        $search = trim($this->search);

        return [
            'customers' => Customer::query()
                ->with('paymentTerm')
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->where('code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('trade_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->when($type !== 'all', fn ($query) => $query->where('customer_type', $type))
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(10),
            'paymentTerms' => PaymentTerm::query()->where('is_active', true)->orderBy('due_days')->orderBy('label')->get(),
        ];
    }

    public function updatingSearch(): void
    {
        Gate::authorize('customers.access');
        $this->resetPage();
    }

    public function updatingTypeFilter(): void
    {
        Gate::authorize('customers.access');
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        Gate::authorize('customers.access');
        $this->resetPage();
    }

    public function prepareCreate(): void
    {
        Gate::authorize('customers.manage');
        $this->resetForm();
        $this->dispatch('open-modal', name: 'manage-customer');
    }

    public function editCustomer(int $customerId): void
    {
        Gate::authorize('customers.manage');
        $customer = Customer::query()->findOrFail($customerId);
        abort_if($customer->isArchived(), 403);
        $this->editingId = $customer->id;
        $this->customerType = $customer->customer_type;
        $this->name = $customer->name;
        $this->tradeName = $customer->trade_name ?? '';
        $this->ice = $customer->ice ?? '';
        $this->taxId = $customer->tax_id ?? '';
        $this->commercialRegister = $customer->commercial_register ?? '';
        $this->address = $customer->address ?? '';
        $this->city = $customer->city ?? '';
        $this->country = $customer->country ?? '';
        $this->phone = $customer->phone ?? '';
        $this->email = $customer->email ?? '';
        $this->notes = $customer->notes ?? '';
        $this->paymentTermId = (string) ($customer->payment_term_id ?? '');
        $this->creditLimit = $customer->credit_limit === null ? '' : (string) $customer->credit_limit;
        $this->feedback = null;
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'manage-customer');
    }

    public function saveCustomer(CustomerManagementService $service): void
    {
        Gate::authorize('customers.manage');
        $this->name = trim($this->name);
        $this->email = Str::lower(trim($this->email));
        $validated = $this->validate($this->rules());
        $attributes = [
            'customer_type' => $validated['customerType'],
            'name' => $validated['name'],
            'trade_name' => $this->nullableString($validated['tradeName']),
            'ice' => $this->nullableString($validated['ice']),
            'tax_id' => $this->nullableString($validated['taxId']),
            'commercial_register' => $this->nullableString($validated['commercialRegister']),
            'address' => $this->nullableString($validated['address']),
            'city' => $this->nullableString($validated['city']),
            'country' => $this->nullableString($validated['country']),
            'phone' => $this->nullableString($validated['phone']),
            'email' => $this->nullableString($validated['email']),
            'notes' => $this->nullableString($validated['notes']),
            'payment_term_id' => $validated['paymentTermId'] === '' ? null : $validated['paymentTermId'],
            'credit_limit' => $validated['creditLimit'] === '' ? null : $validated['creditLimit'],
        ];
        if ($this->editingId === null) {
            $customer = $service->create($attributes);
            $this->feedback = __('Client :code créé.', ['code' => $customer->code]);
        } else {
            $service->update($this->editingId, $attributes);
            $this->feedback = __('Client mis à jour.');
        }
        $this->resetForm();
        $this->dispatch('close-modal', name: 'manage-customer');
    }

    public function archiveCustomer(int $customerId, CustomerManagementService $service): void
    {
        Gate::authorize('customers.manage');
        $service->archive($customerId);
        $this->feedback = __('Client archivé.');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'customerType' => ['required', Rule::in(['individual', 'company'])],
            'name' => ['required', 'string', 'max:255'],
            'tradeName' => ['nullable', 'string', 'max:255'],
            'ice' => ['nullable', 'string', 'max:100'],
            'taxId' => ['nullable', 'string', 'max:100'],
            'commercialRegister' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:5000'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'paymentTermId' => ['nullable', 'integer', Rule::exists(PaymentTerm::class, 'id')],
            'creditLimit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
        ];
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'tradeName', 'ice', 'taxId', 'commercialRegister', 'address', 'city', 'country', 'phone', 'email', 'notes', 'paymentTermId', 'creditLimit', 'feedback');
        $this->customerType = 'individual';
        $this->resetValidation();
    }
}; ?>

<div class="space-y-6">
    @if ($feedback)<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ $feedback }}</div>@endif
    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
        <div class="border-b border-gray-200 p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><h3 class="text-lg font-semibold text-gray-900">{{ __('Clients') }}</h3><p class="mt-1 text-sm text-gray-600">{{ __('Consultez les clients actifs et archivés sans afficher de données commerciales inexistantes.') }}</p></div>@can('customers.manage')<x-primary-button type="button" wire:click="prepareCreate">{{ __('Nouveau client') }}</x-primary-button>@endcan</div>
            <div class="mt-5 grid gap-4 sm:grid-cols-3"><div><x-input-label for="customer-search" :value="__('Rechercher')" /><x-text-input id="customer-search" type="search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full" placeholder="{{ __('Code, nom ou email') }}" /></div><div><x-input-label for="customer-type-filter" :value="__('Type')" /><select id="customer-type-filter" wire:model.live="typeFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"><option value="all">{{ __('Tous') }}</option><option value="individual">{{ __('Particuliers') }}</option><option value="company">{{ __('Entreprises') }}</option></select></div><div><x-input-label for="customer-status-filter" :value="__('Statut')" /><select id="customer-status-filter" wire:model.live="statusFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"><option value="active">{{ __('Actifs') }}</option><option value="archived">{{ __('Archivés') }}</option><option value="all">{{ __('Tous') }}</option></select></div></div>
        </div>
        <div class="overflow-x-auto"><div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr><th class="px-6 py-3 text-start text-xs font-semibold uppercase text-gray-600">{{ __('Code') }}</th><th class="px-6 py-3 text-start text-xs font-semibold uppercase text-gray-600">{{ __('Client') }}</th><th class="px-6 py-3 text-start text-xs font-semibold uppercase text-gray-600">{{ __('Type') }}</th><th class="px-6 py-3 text-start text-xs font-semibold uppercase text-gray-600">{{ __('Email') }}</th><th class="px-6 py-3 text-start text-xs font-semibold uppercase text-gray-600">{{ __('Statut') }}</th><th class="px-6 py-3 text-end text-xs font-semibold uppercase text-gray-600">{{ __('Actions') }}</th></tr></thead><tbody class="divide-y divide-gray-200 bg-white">@forelse($customers as $customer)<tr wire:key="customer-row-{{ $customer->id }}"><td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">{{ $customer->code }}</td><td class="px-6 py-4 text-sm text-gray-900"><a href="{{ route('admin.customers.show', $customer) }}" wire:navigate class="text-indigo-600 hover:text-indigo-900">{{ $customer->name }}</a><div class="text-xs text-gray-500">{{ $customer->trade_name }}</div></td><td class="px-6 py-4 text-sm text-gray-600">{{ $customer->customer_type === 'company' ? __('Entreprise') : __('Particulier') }}</td><td class="px-6 py-4 text-sm text-gray-600">{{ $customer->email }}</td><td class="px-6 py-4 text-sm text-gray-600">{{ $customer->isArchived() ? __('Archivé') : __('Actif') }}</td><td class="px-6 py-4 text-end text-sm"><div class="flex flex-wrap justify-end gap-3"><a href="{{ route('admin.customers.show', $customer) }}" wire:navigate class="text-indigo-600 hover:text-indigo-900">{{ __('Consulter') }}</a>@can('customers.manage') @unless($customer->isArchived())<button type="button" wire:click="editCustomer({{ $customer->id }})" class="text-indigo-600 hover:text-indigo-900">{{ __('Modifier') }}</button><button type="button" wire:click="archiveCustomer({{ $customer->id }})" wire:confirm="{{ __('Archiver ce client ?') }}" class="text-red-600 hover:text-red-900">{{ __('Archiver') }}</button>@endunless @endcan</div></td></tr>@empty<tr><td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500">{{ __('Aucun client trouvé.') }}</td></tr>@endforelse</tbody></table></div></div>
        @if($customers->hasPages())<div class="border-t border-gray-200 px-6 py-4">{{ $customers->links() }}</div>@endif
    </div>

    @can('customers.manage')
    <x-modal name="manage-customer" focusable><form wire:submit="saveCustomer" class="p-6"><h3 class="text-lg font-semibold text-gray-900">{{ $editingId ? __('Modifier le client') : __('Nouveau client') }}</h3><div class="mt-6 grid gap-5 sm:grid-cols-2"><div><x-input-label for="customer-type" :value="__('Type')" /><select id="customer-type" wire:model="customerType" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"><option value="individual">{{ __('Particulier') }}</option><option value="company">{{ __('Entreprise') }}</option></select><x-input-error :messages="$errors->get('customerType')" class="mt-2" /></div><div><x-input-label for="customer-name" :value="$customerType === 'company' ? __('Raison sociale') : __('Nom complet')" /><x-text-input id="customer-name" wire:model="name" class="mt-1 block w-full" required /><x-input-error :messages="$errors->get('name')" class="mt-2" /></div><div><x-input-label for="customer-trade-name" :value="__('Nom commercial')" /><x-text-input id="customer-trade-name" wire:model="tradeName" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('tradeName')" class="mt-2" /></div><div><x-input-label for="customer-email" :value="__('Email')" /><x-text-input id="customer-email" type="email" wire:model="email" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('email')" class="mt-2" /></div><div><x-input-label for="customer-phone" :value="__('Téléphone')" /><x-text-input id="customer-phone" wire:model="phone" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('phone')" class="mt-2" /></div><div><x-input-label for="customer-city" :value="__('Ville')" /><x-text-input id="customer-city" wire:model="city" class="mt-1 block w-full" /></div><div><x-input-label for="customer-country" :value="__('Pays')" /><x-text-input id="customer-country" wire:model="country" class="mt-1 block w-full" /></div><div class="sm:col-span-2"><x-input-label for="customer-address" :value="__('Adresse')" /><textarea id="customer-address" wire:model="address" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea></div><div><x-input-label for="customer-ice" :value="__('ICE')" /><x-text-input id="customer-ice" wire:model="ice" class="mt-1 block w-full" /></div><div><x-input-label for="customer-if" :value="__('IF')" /><x-text-input id="customer-if" wire:model="taxId" class="mt-1 block w-full" /></div><div><x-input-label for="customer-rc" :value="__('RC')" /><x-text-input id="customer-rc" wire:model="commercialRegister" class="mt-1 block w-full" /></div><div><x-input-label for="customer-credit-limit" :value="__('Plafond de crédit')" /><x-text-input id="customer-credit-limit" type="number" step="0.01" min="0" wire:model="creditLimit" class="mt-1 block w-full" placeholder="{{ __('Laisser vide si non défini') }}" /><x-input-error :messages="$errors->get('creditLimit')" class="mt-2" /></div><div class="sm:col-span-2"><x-input-label for="customer-payment-term" :value="__('Condition de paiement')" /><select id="customer-payment-term" wire:model="paymentTermId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"><option value="">{{ __('Aucune condition') }}</option>@foreach($paymentTerms as $term)<option value="{{ $term->id }}">{{ $term->label }}</option>@endforeach</select><x-input-error :messages="$errors->get('paymentTermId')" class="mt-2" /></div><div class="sm:col-span-2"><x-input-label for="customer-notes" :value="__('Notes internes')" /><textarea id="customer-notes" wire:model="notes" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea></div></div><div class="mt-6 flex justify-end gap-3"><x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Annuler') }}</x-secondary-button><x-primary-button type="submit">{{ $editingId ? __('Enregistrer') : __('Créer') }}</x-primary-button></div></form></x-modal>
    @endcan
</div>
