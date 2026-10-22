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

<section class="erp-entity-shell">
    @can('customers.manage')
        <div class="flex justify-end">
            <a href="{{ route('admin.customers.index') }}" wire:navigate class="erp-link-button">{{ __('Retour à la liste') }}</a>
        </div>
    @endcan

    <div class="erp-detail-panel">
        <div class="erp-detail-header">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-violet-600">{{ $customer->code }}</p>
                <h3 class="erp-entity-name">{{ $customer->name }}</h3>
                <p class="mt-2 text-sm text-slate-500">{{ $customer->customer_type === 'company' ? __('Entreprise') : __('Particulier') }} @if($customer->trade_name) · {{ $customer->trade_name }} @endif</p>
            </div>
            <span class="erp-status-pill {{ $customer->isArchived() ? 'is-archived' : 'is-active' }}">
                {{ $customer->isArchived() ? __('Archivé') : __('Actif') }}
            </span>
        </div>

        <div class="erp-detail-grid">
            <div>
                <h4>{{ __('Coordonnées') }}</h4>
                <dl class="erp-kv-list">
                    <div><dt>{{ __('Email :') }}</dt> <dd>{{ $customer->email ?? __('Non renseigné') }}</dd></div>
                    <div><dt>{{ __('Téléphone :') }}</dt> <dd>{{ $customer->phone ?? __('Non renseigné') }}</dd></div>
                    <div><dt>{{ __('Adresse :') }}</dt> <dd>{{ collect([$customer->address, $customer->city, $customer->country])->filter()->join(', ') ?: __('Non renseignée') }}</dd></div>
                </dl>
            </div>
            <div>
                <h4>{{ __('Informations commerciales') }}</h4>
                <dl class="erp-kv-list">
                    <div><dt>{{ __('Condition :') }}</dt> <dd>{{ $customer->paymentTerm?->label ?? __('Aucune') }}</dd></div>
                    <div><dt>{{ __('Plafond :') }}</dt> <dd>{{ $customer->credit_limit !== null ? number_format((float) $customer->credit_limit, 2, ',', ' ') : __('Non défini') }}</dd></div>
                </dl>
                <p class="mt-4 text-xs text-slate-500">{{ __('Aucun solde n’est calculé avant la disponibilité des modules de facturation et paiements.') }}</p>
            </div>
        </div>
    </div>

    @if($customer->ice || $customer->tax_id || $customer->commercial_register)
        <div class="erp-detail-panel">
            <h4>{{ __('Identifiants légaux') }}</h4>
            <dl class="erp-legal-grid">
                <div><dt>{{ __('ICE') }}</dt><dd>{{ $customer->ice ?? __('Non renseigné') }}</dd></div>
                <div><dt>{{ __('IF') }}</dt><dd>{{ $customer->tax_id ?? __('Non renseigné') }}</dd></div>
                <div><dt>{{ __('RC') }}</dt><dd>{{ $customer->commercial_register ?? __('Non renseigné') }}</dd></div>
            </dl>
        </div>
    @endif

    <livewire:admin.customer-contacts-manager :customer-id="$customer->id" />
</section>
