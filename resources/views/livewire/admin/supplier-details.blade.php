<?php

use App\Models\SupplierInvoice;

use App\Models\Supplier;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public int $supplierId;

    public function mount(int $supplierId): void
    {
        Gate::authorize('suppliers.access');

        $this->supplierId = $supplierId;

        Supplier::query()->findOrFail($supplierId);
    }

    public function with(): array
    {
        Gate::authorize('suppliers.access');

        $query = Supplier::query()
            ->with('paymentTerm');

        if (Gate::allows('purchases.view')) {
            $query->with([
                'supplierInvoices' => fn ($invoices) => $invoices
                    ->reorder()
                    ->with('purchaseOrder:id,number')
                    ->latest('invoice_date')
                    ->latest('id'),
            ]);
        }

        if (Gate::allows('payments.view')) {
            $query->with([
                'supplierPayments' => fn ($payments) => $payments
                    ->with([
                        'paymentMethod:id,name',
                        'allocations.supplierInvoice:id,number',
                    ])
                    ->latest('payment_date')
                    ->latest('id'),
            ]);
        }

        return [
            'supplier' => $query->findOrFail($this->supplierId),
        ];
    }
};
?>

<section class="erp-entity-shell">
    <div class="flex justify-end">
        <a
            href="{{ route('admin.suppliers.index') }}"
            wire:navigate
            class="erp-link-button"
        >
            {{ __('Retour à la liste') }}
        </a>
    </div>

    <div class="erp-detail-panel">
        <div class="erp-detail-header">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-violet-600">
                    {{ $supplier->code }}
                </p>

                <h3 class="erp-entity-name">
                    {{ $supplier->name }}
                </h3>

                @if ($supplier->trade_name)
                    <p class="mt-2 text-sm text-slate-500">
                        {{ $supplier->trade_name }}
                    </p>
                @endif
            </div>

            <span class="erp-status-pill {{ $supplier->isArchived() ? 'is-archived' : 'is-active' }}">
                {{ $supplier->isArchived() ? __('Archivé') : __('Actif') }}
            </span>
        </div>

        <div class="erp-detail-grid">
            <div>
                <h4>{{ __('Coordonnées') }}</h4>

                <dl class="erp-kv-list">
                    <div>
                        <dt>{{ __('Email :') }}</dt>
                        <dd>{{ $supplier->email ?? __('Non renseigné') }}</dd>
                    </div>

                    <div>
                        <dt>{{ __('Téléphone :') }}</dt>
                        <dd>{{ $supplier->phone ?? __('Non renseigné') }}</dd>
                    </div>

                    <div>
                        <dt>{{ __('Adresse :') }}</dt>
                        <dd>{{ collect([ $supplier->address, $supplier->city, $supplier->country ])->filter()->join(', ') ?: __('Non renseignée') }}</dd>
                    </div>
                </dl>
            </div>

            <div>
                <h4>{{ __('Informations commerciales') }}</h4>

                <dl class="erp-kv-list">
                    <div>
                        <dt>{{ __('Condition :') }}</dt>
                        <dd>{{ $supplier->paymentTerm?->label ?? __('Aucune') }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        @if ($supplier->notes)
            <div class="erp-card-section">
                <div class="erp-card-section-header">
                    <div>
                        <h4>{{ __('Notes internes') }}</h4>
                    </div>
                </div>

                <p class="mt-4 whitespace-pre-line text-sm text-slate-600">
                    {{ $supplier->notes }}
                </p>
            </div>
        @endif
    </div>

    @if (
        $supplier->ice
        || $supplier->tax_id
        || $supplier->commercial_register
    )
        <div class="erp-detail-panel">
            <h4>{{ __('Identifiants légaux') }}</h4>

            <dl class="erp-legal-grid">
                <div>
                    <dt>{{ __('ICE') }}</dt>
                    <dd>{{ $supplier->ice ?? __('Non renseigné') }}</dd>
                </div>

                <div>
                    <dt>{{ __('IF') }}</dt>
                    <dd>{{ $supplier->tax_id ?? __('Non renseigné') }}</dd>
                </div>

                <div>
                    <dt>{{ __('RC') }}</dt>
                    <dd>{{ $supplier->commercial_register ?? __('Non renseigné') }}</dd>
                </div>
            </dl>
        </div>
    @endif
    @can('purchases.view')
        <div class="bg-white p-6 shadow-sm sm:rounded-lg">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h4 class="font-medium text-gray-900">
                        {{ __('Factures fournisseurs') }}
                    </h4>

                    <p class="mt-1 text-sm text-gray-500">
                        {{ __('Historique des factures enregistrées pour ce fournisseur.') }}
                    </p>
                </div>

                <a
                    href="{{ route(
                        'purchases.invoices.index',
                        ['supplier' => $supplier->id]
                    ) }}"
                    wire:navigate
                    class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
                >
                    {{ __('Voir toutes les factures') }}
                </a>
            </div>

            <div class="mt-4 divide-y divide-gray-100 border-y border-gray-200">
                @forelse ($supplier->supplierInvoices as $invoice)
                    <a
                        href="{{ route(
                            'purchases.invoices.show',
                            $invoice
                        ) }}"
                        wire:navigate
                        class="flex flex-col gap-2 py-4 hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p class="font-medium text-indigo-700">
                                {{
                                    $invoice->number
                                    ?? __('Brouillon #:id', [
                                        'id' => $invoice->id,
                                    ])
                                }}
                            </p>

                            <p class="text-sm text-gray-500">
                                {{ $invoice->supplier_invoice_number }}
                                ·
                                {{ $invoice->purchaseOrder->number }}
                            </p>
                        </div>

                        <div class="text-end text-sm">
                            <p>
                                {{ $invoice->invoice_date->format('d/m/Y') }}
                            </p>

                            <p class="text-gray-500">
                                {{ str_replace(
                                    '.',
                                    ',',
                                    ($invoice->status === SupplierInvoice::STATUS_VALIDATED ? $invoice->payableAmount() : $invoice->total_ttc)
                                ) }}
                            </p>
                        </div>
                    </a>
                @empty
                    <p class="py-4 text-sm text-gray-500">
                        {{ __('Aucune facture fournisseur.') }}
                    </p>
                @endforelse
            </div>
        </div>
    @endcan

    @can('payments.view')
        <div class="bg-white p-6 shadow-sm sm:rounded-lg">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h4 class="font-medium text-gray-900">
                        {{ __('Paiements fournisseurs') }}
                    </h4>

                    <p class="mt-1 text-sm text-gray-500">
                        {{ __('Historique des règlements enregistrés pour ce fournisseur.') }}
                    </p>
                </div>

                <a
                    href="{{ route(
                        'purchases.payments.index',
                        ['supplier' => $supplier->id]
                    ) }}"
                    wire:navigate
                    class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
                >
                    {{ __('Voir tous les paiements') }}
                </a>
            </div>

            <div class="mt-4 divide-y divide-gray-100 border-y border-gray-200">
                @forelse ($supplier->supplierPayments as $payment)
                    <a
                        href="{{ route(
                            'purchases.payments.show',
                            $payment
                        ) }}"
                        wire:navigate
                        class="flex flex-col gap-2 py-4 hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p class="font-medium text-indigo-700">
                                {{ __('Paiement #:id', [
                                    'id' => $payment->id,
                                ]) }}
                            </p>

                            <p class="text-sm text-gray-500">
                                {{ $payment->payment_date->format('d/m/Y') }}
                                ·
                                {{ $payment->paymentMethod->name }}

                                @if ($payment->reference)
                                    · {{ $payment->reference }}
                                @endif
                            </p>

                            @if ($payment->allocations->isNotEmpty())
                                <p class="mt-1 text-xs text-gray-500">
                                    @foreach (
                                        $payment->allocations
                                        as $allocation
                                    )
                                        {{
                                            $allocation
                                                ->supplierInvoice
                                                ->number
                                        }}

                                        @if (! $loop->last)
                                            ·
                                        @endif
                                    @endforeach
                                </p>
                            @endif
                        </div>

                        <p class="font-semibold text-gray-900">
                            {{ str_replace(
                                '.',
                                ',',
                                $payment->amount
                            ) }}
                        </p>
                    </a>
                @empty
                    <p class="py-4 text-sm text-gray-500">
                        {{ __('Aucun paiement fournisseur enregistré.') }}
                    </p>
                @endforelse
            </div>
        </div>
    @endcan

    <livewire:admin.supplier-contacts-manager
        :supplier-id="$supplier->id"
    />
</section>
