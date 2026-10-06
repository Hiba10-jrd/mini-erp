<?php

use App\Models\PaymentTerm;
use App\Models\Supplier;
use App\Services\SupplierManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'active';

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

    #[Locked]
    public ?int $editingId = null;

    public ?string $feedback = null;

    public function mount(): void
    {
        Gate::authorize('suppliers.access');
    }

    public function with(): array
    {
        Gate::authorize('suppliers.access');
        $status = in_array($this->statusFilter, ['active', 'archived', 'all'], true) ? $this->statusFilter : 'active';
        $search = trim($this->search);

        return [
            'suppliers' => Supplier::query()
                ->with('paymentTerm')
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->where('code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('trade_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(10),
            'paymentTerms' => PaymentTerm::query()
                ->where('is_active', true)
                ->orderBy('due_days')
                ->orderBy('label')
                ->get(),
        ];
    }

    public function updatingSearch(): void
    {
        Gate::authorize('suppliers.access');
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        Gate::authorize('suppliers.access');
        $this->resetPage();
    }

    public function prepareCreate(): void
    {
        Gate::authorize('suppliers.manage');
        $this->resetForm();
        $this->feedback = null;
        $this->dispatch('open-modal', name: 'manage-supplier');
    }

    public function editSupplier(int $supplierId): void
    {
        Gate::authorize('suppliers.manage');
        $supplier = Supplier::query()->findOrFail($supplierId);
        abort_if($supplier->isArchived(), 403);
        $this->editingId = $supplier->id;
        $this->name = $supplier->name;
        $this->tradeName = $supplier->trade_name ?? '';
        $this->ice = $supplier->ice ?? '';
        $this->taxId = $supplier->tax_id ?? '';
        $this->commercialRegister = $supplier->commercial_register ?? '';
        $this->address = $supplier->address ?? '';
        $this->city = $supplier->city ?? '';
        $this->country = $supplier->country ?? '';
        $this->phone = $supplier->phone ?? '';
        $this->email = $supplier->email ?? '';
        $this->notes = $supplier->notes ?? '';
        $this->paymentTermId = (string) ($supplier->payment_term_id ?? '');
        $this->feedback = null;
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'manage-supplier');
    }

    public function saveSupplier(SupplierManagementService $service): void
    {
        Gate::authorize('suppliers.manage');
        $this->name = trim($this->name);
        $this->email = Str::lower(trim($this->email));
        $validated = $this->validate($this->rules());
        $attributes = [
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
        ];

        if ($this->editingId === null) {
            $supplier = $service->create($attributes);
            $this->feedback = __('Fournisseur :code créé.', ['code' => $supplier->code]);
        } else {
            $service->update($this->editingId, $attributes);
            $this->feedback = __('Fournisseur mis à jour.');
        }

        $this->resetForm();
        $this->dispatch('close-modal', name: 'manage-supplier');
    }

    public function archiveSupplier(int $supplierId, SupplierManagementService $service): void
    {
        Gate::authorize('suppliers.manage');
        $service->archive($supplierId);
        $this->feedback = __('Fournisseur archivé.');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
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
        ];
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'tradeName', 'ice', 'taxId', 'commercialRegister', 'address', 'city', 'country', 'phone', 'email', 'notes', 'paymentTermId');
        $this->resetValidation();
    }
}; ?>

<div class="space-y-6">
    @if ($feedback)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
            {{ $feedback }}
        </div>
    @endif

    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
        <div class="border-b border-gray-200 p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('Fournisseurs') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Consultez les fournisseurs actifs et archivés et leurs conditions de paiement.') }}</p>
                </div>
                @can('suppliers.manage')
                    <x-primary-button type="button" wire:click="prepareCreate">{{ __('Nouveau fournisseur') }}</x-primary-button>
                @endcan
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="supplier-search" :value="__('Rechercher')" />
                    <x-text-input id="supplier-search" type="search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full" placeholder="{{ __('Code, nom ou email') }}" />
                </div>
                <div>
                    <x-input-label for="supplier-status-filter" :value="__('Statut')" />
                    <select id="supplier-status-filter" wire:model.live="statusFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="active">{{ __('Actifs') }}</option>
                        <option value="archived">{{ __('Archivés') }}</option>
                        <option value="all">{{ __('Tous') }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Code') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Fournisseur') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Email') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Condition') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Statut') }}</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase text-gray-600">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse ($suppliers as $supplier)
                        <tr wire:key="supplier-row-{{ $supplier->id }}">
                            <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">{{ $supplier->code }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <a href="{{ route('admin.suppliers.show', $supplier) }}" wire:navigate class="text-indigo-600 hover:text-indigo-900">{{ $supplier->name }}</a>
                                <div class="text-xs text-gray-500">{{ $supplier->trade_name }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $supplier->email }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $supplier->paymentTerm?->label ?? __('Aucune') }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $supplier->isArchived() ? __('Archivé') : __('Actif') }}</td>
                            <td class="px-6 py-4 text-right text-sm">
                                <div class="flex flex-wrap justify-end gap-3">
                                    <a href="{{ route('admin.suppliers.show', $supplier) }}" wire:navigate class="text-indigo-600 hover:text-indigo-900">{{ __('Consulter') }}</a>
                                    @can('suppliers.manage')
                                        @unless ($supplier->isArchived())
                                            <button type="button" wire:click="editSupplier({{ $supplier->id }})" class="text-indigo-600 hover:text-indigo-900">{{ __('Modifier') }}</button>
                                            <button type="button" wire:click="archiveSupplier({{ $supplier->id }})" wire:confirm="{{ __('Archiver ce fournisseur ?') }}" class="text-red-600 hover:text-red-900">{{ __('Archiver') }}</button>
                                        @endunless
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500">{{ __('Aucun fournisseur trouvé.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>

        @if ($suppliers->hasPages())
            <div class="border-t border-gray-200 px-6 py-4">{{ $suppliers->links() }}</div>
        @endif
    </div>

    @can('suppliers.manage')
        <x-modal name="manage-supplier" focusable>
            <form wire:submit="saveSupplier" class="p-6">
                <h3 class="text-lg font-semibold text-gray-900">{{ $editingId ? __('Modifier le fournisseur') : __('Nouveau fournisseur') }}</h3>
                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="supplier-name" :value="__('Raison sociale / nom')" />
                        <x-text-input id="supplier-name" wire:model="name" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="supplier-trade-name" :value="__('Nom commercial')" />
                        <x-text-input id="supplier-trade-name" wire:model="tradeName" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('tradeName')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="supplier-email" :value="__('Email')" />
                        <x-text-input id="supplier-email" type="email" wire:model="email" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="supplier-phone" :value="__('Téléphone')" />
                        <x-text-input id="supplier-phone" wire:model="phone" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="supplier-city" :value="__('Ville')" />
                        <x-text-input id="supplier-city" wire:model="city" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('city')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="supplier-country" :value="__('Pays')" />
                        <x-text-input id="supplier-country" wire:model="country" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('country')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="supplier-address" :value="__('Adresse')" />
                        <textarea id="supplier-address" wire:model="address" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
                        <x-input-error :messages="$errors->get('address')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="supplier-ice" :value="__('ICE')" />
                        <x-text-input id="supplier-ice" wire:model="ice" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('ice')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="supplier-if" :value="__('IF')" />
                        <x-text-input id="supplier-if" wire:model="taxId" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('taxId')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="supplier-rc" :value="__('RC')" />
                        <x-text-input id="supplier-rc" wire:model="commercialRegister" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('commercialRegister')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="supplier-payment-term" :value="__('Condition de paiement')" />
                        <select id="supplier-payment-term" wire:model="paymentTermId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">{{ __('Aucune condition') }}</option>
                            @foreach ($paymentTerms as $term)
                                <option value="{{ $term->id }}">{{ $term->label }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('paymentTermId')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="supplier-notes" :value="__('Notes internes')" />
                        <textarea id="supplier-notes" wire:model="notes" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Annuler') }}</x-secondary-button>
                    <x-primary-button type="submit">{{ $editingId ? __('Enregistrer') : __('Créer') }}</x-primary-button>
                </div>
            </form>
        </x-modal>
    @endcan
</div>
