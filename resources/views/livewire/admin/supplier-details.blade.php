<?php

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

<section class="space-y-6">
    <div class="flex justify-end">
        <a
            href="{{ route('admin.suppliers.index') }}"
            wire:navigate
            class="text-sm text-indigo-600 hover:text-indigo-900"
        >
            {{ __('Retour à la liste') }}
        </a>
    </div>

    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
        <div class="flex flex-col gap-2 border-b border-gray-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">
                    {{ $supplier->code }}
                </p>

                <h3 class="text-xl font-semibold text-gray-900">
                    {{ $supplier->name }}
                </h3>

                @if ($supplier->trade_name)
                    <p class="text-sm text-gray-500">
                        {{ $supplier->trade_name }}
                    </p>
                @endif
            </div>

            <span
                class="rounded-full px-3 py-1 text-sm {{ $supplier->isArchived()
                    ? 'bg-gray-100 text-gray-700'
                    : 'bg-emerald-50 text-emerald-700' }}"
            >
                {{ $supplier->isArchived()
                    ? __('Archivé')
                    : __('Actif') }}
            </span>
        </div>

        <div class="mt-6 grid gap-5 sm:grid-cols-2">
            <div>
                <h4 class="font-medium text-gray-900">
                    {{ __('Coordonnées') }}
                </h4>

                <dl class="mt-2 space-y-1 text-sm text-gray-600">
                    <div>
                        <dt class="inline font-medium">
                            {{ __('Email :') }}
                        </dt>

                        <dd class="inline">
                            {{ $supplier->email ?? __('Non renseigné') }}
                        </dd>
                    </div>

                    <div>
                        <dt class="inline font-medium">
                            {{ __('Téléphone :') }}
                        </dt>

                        <dd class="inline">
                            {{ $supplier->phone ?? __('Non renseigné') }}
                        </dd>
                    </div>

                    <div>
                        <dt class="inline font-medium">
                            {{ __('Adresse :') }}
                        </dt>

                        <dd class="inline">
                            {{
                                collect([
                                    $supplier->address,
                                    $supplier->city,
                                    $supplier->country,
                                ])
                                    ->filter()
                                    ->join(', ')
                                ?: __('Non renseignée')
                            }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div>
                <h4 class="font-medium text-gray-900">
                    {{ __('Informations commerciales') }}
                </h4>

                <dl class="mt-2 space-y-1 text-sm text-gray-600">
                    <div>
                        <dt class="inline font-medium">
                            {{ __('Condition :') }}
                        </dt>

                        <dd class="inline">
                            {{ $supplier->paymentTerm?->label ?? __('Aucune') }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        @if ($supplier->notes)
            <div class="mt-5 border-t border-gray-200 pt-5">
                <h4 class="font-medium text-gray-900">
                    {{ __('Notes internes') }}
                </h4>

                <p class="mt-2 whitespace-pre-line text-sm text-gray-600">
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
        <div class="bg-white p-6 shadow-sm sm:rounded-lg">
            <h4 class="font-medium text-gray-900">
                {{ __('Identifiants légaux') }}
            </h4>

            <dl class="mt-3 grid gap-3 text-sm text-gray-600 sm:grid-cols-3">
                <div>
                    <dt class="font-medium">
                        {{ __('ICE') }}
                    </dt>

                    <dd>
                        {{ $supplier->ice ?? __('Non renseigné') }}
                    </dd>
                </div>

                <div>
                    <dt class="font-medium">
                        {{ __('IF') }}
                    </dt>

                    <dd>
                        {{ $supplier->tax_id ?? __('Non renseigné') }}
                    </dd>
                </div>

                <div>
                    <dt class="font-medium">
                        {{ __('RC') }}
                    </dt>

                    <dd>
                        {{ $supplier->commercial_register ?? __('Non renseigné') }}
                    </dd>
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

                        <div class="text-right text-sm">
                            <p>
                                {{ $invoice->invoice_date->format('d/m/Y') }}
                            </p>

                            <p class="text-gray-500">
                                {{ str_replace(
                                    '.',
                                    ',',
                                    $invoice->total_ttc
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