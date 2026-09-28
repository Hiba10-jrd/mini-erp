<?php

use App\Models\Customer;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;

new class extends Component
{
    public int $customerId;

    public function mount(int $customerId): void
    {
        Gate::authorize('customers.access');
        $this->customerId = $customerId;
        Customer::query()->findOrFail($customerId);
    }

    public function with(): array
    {
        Gate::authorize('customers.access');

        return ['customer' => Customer::query()->with(['paymentTerm', 'contacts'])->findOrFail($this->customerId)];
    }
}; ?>

<section class="space-y-6">
    @can('customers.manage')<div class="flex justify-end"><a href="{{ route('admin.customers.index') }}" wire:navigate class="text-sm text-indigo-600 hover:text-indigo-900">{{ __('Retour à la liste') }}</a></div>@endcan
    <div class="bg-white p-6 shadow-sm sm:rounded-lg"><div class="flex flex-col gap-2 border-b border-gray-200 pb-5 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-sm font-medium text-indigo-600">{{ $customer->code }}</p><h3 class="text-xl font-semibold text-gray-900">{{ $customer->name }}</h3><p class="text-sm text-gray-500">{{ $customer->customer_type === 'company' ? __('Entreprise') : __('Particulier') }} @if($customer->trade_name) · {{ $customer->trade_name }} @endif</p></div><span class="rounded-full px-3 py-1 text-sm {{ $customer->isArchived() ? 'bg-gray-100 text-gray-700' : 'bg-emerald-50 text-emerald-700' }}">{{ $customer->isArchived() ? __('Archivé') : __('Actif') }}</span></div><div class="mt-6 grid gap-5 sm:grid-cols-2"><div><h4 class="font-medium text-gray-900">{{ __('Coordonnées') }}</h4><dl class="mt-2 space-y-1 text-sm text-gray-600"><div><dt class="inline font-medium">{{ __('Email :') }}</dt> <dd class="inline">{{ $customer->email ?? __('Non renseigné') }}</dd></div><div><dt class="inline font-medium">{{ __('Téléphone :') }}</dt> <dd class="inline">{{ $customer->phone ?? __('Non renseigné') }}</dd></div><div><dt class="inline font-medium">{{ __('Adresse :') }}</dt> <dd class="inline">{{ collect([$customer->address, $customer->city, $customer->country])->filter()->join(', ') ?: __('Non renseignée') }}</dd></div></dl></div><div><h4 class="font-medium text-gray-900">{{ __('Informations commerciales') }}</h4><dl class="mt-2 space-y-1 text-sm text-gray-600"><div><dt class="inline font-medium">{{ __('Condition :') }}</dt> <dd class="inline">{{ $customer->paymentTerm?->label ?? __('Aucune') }}</dd></div><div><dt class="inline font-medium">{{ __('Plafond :') }}</dt> <dd class="inline">{{ $customer->credit_limit !== null ? number_format((float) $customer->credit_limit, 2, ',', ' ') : __('Non défini') }}</dd></div></dl><p class="mt-3 text-xs text-gray-500">{{ __('Aucun solde n’est calculé avant la disponibilité des modules de facturation et paiements.') }}</p></div></div></div>
    @if($customer->ice || $customer->tax_id || $customer->commercial_register)<div class="bg-white p-6 shadow-sm sm:rounded-lg"><h4 class="font-medium text-gray-900">{{ __('Identifiants légaux') }}</h4><dl class="mt-3 grid gap-3 text-sm text-gray-600 sm:grid-cols-3"><div><dt class="font-medium">{{ __('ICE') }}</dt><dd>{{ $customer->ice ?? __('Non renseigné') }}</dd></div><div><dt class="font-medium">{{ __('IF') }}</dt><dd>{{ $customer->tax_id ?? __('Non renseigné') }}</dd></div><div><dt class="font-medium">{{ __('RC') }}</dt><dd>{{ $customer->commercial_register ?? __('Non renseigné') }}</dd></div></dl></div>@endif
    <livewire:admin.customer-contacts-manager :customer-id="$customer->id" />
</section>
