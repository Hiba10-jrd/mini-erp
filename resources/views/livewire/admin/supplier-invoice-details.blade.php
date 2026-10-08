<?php

use App\Models\SupplierInvoice;
use App\Services\SupplierInvoiceManagementService;
use App\Services\SupplierPaymentManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public int $invoiceId;

    public function mount(int $invoiceId): void
    {
        Gate::authorize('purchases.view');

        $this->invoiceId = $invoiceId;
    }

    public function validateSupplierInvoice(
        SupplierInvoiceManagementService $service
    ): void {
        Gate::authorize('purchases.update');

        $service->validate(
            SupplierInvoice::query()
                ->findOrFail($this->invoiceId)
        );

        session()->flash(
            'status',
            __('La facture fournisseur a été validée avec succès.')
        );
    }

    public function cancelSupplierInvoice(
        SupplierInvoiceManagementService $service
    ): void {
        Gate::authorize('purchases.delete');

        $service->cancelDraft(
            SupplierInvoice::query()
                ->findOrFail($this->invoiceId)
        );

        session()->flash(
            'status',
            __('Le brouillon de facture fournisseur a été annulé.')
        );
    }

    public function with(): array
    {
        Gate::authorize('purchases.view');

        $invoice = SupplierInvoice::query()
            ->with([
                'purchaseOrder:id,number',
                'supplier:id,name,code',
                'creator:id,name',
                'validator:id,name',
                'items.goodsReceiptItem.goodsReceipt',
                'histories.user:id,name',
                'paymentAllocations.payment.paymentMethod:id,name',
                'paymentAllocations.payment.creator:id,name',
            ])
            ->findOrFail($this->invoiceId);

        $paymentService = app(
            SupplierPaymentManagementService::class
        );

        return [
            'invoice' => $invoice,

            'sourceReceipts' => $invoice->items
                ->map(
                    fn ($item) => $item
                        ->goodsReceiptItem
                        ?->goodsReceipt
                )
                ->filter()
                ->unique('id')
                ->sortBy('receipt_date')
                ->values(),

            'paidAmount' => $paymentService->paidAmount($invoice),

            'remainingAmount' => $paymentService->remainingAmount($invoice),

            'paymentState' => $paymentService->paymentState($invoice),
        ];
    }
};
?>

<section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a
            href="{{ route('purchases.invoices.index') }}"
            wire:navigate
            class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
        >
            {{ __('Retour aux factures fournisseurs') }}
        </a>

        <div class="flex flex-wrap gap-2">
            @if ($invoice->isEditable())
                @can('purchases.update')
                    <a
                        href="{{ route(
                            'purchases.invoices.edit',
                            $invoice
                        ) }}"
                        wire:navigate
                        class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        {{ __('Modifier') }}
                    </a>

                    <x-primary-button
                        type="button"
                        wire:click="validateSupplierInvoice"
                        wire:confirm="{{ __('Valider définitivement cette facture fournisseur ? Aucun mouvement de stock ne sera créé.') }}"
                        wire:loading.attr="disabled"
                        wire:target="validateSupplierInvoice"
                    >
                        {{ __('Valider la facture fournisseur') }}
                    </x-primary-button>
                @endcan

                @can('purchases.delete')
                    <x-danger-button
                        type="button"
                        wire:click="cancelSupplierInvoice"
                        wire:confirm="{{ __('Annuler ce brouillon de facture fournisseur ?') }}"
                        wire:loading.attr="disabled"
                        wire:target="cancelSupplierInvoice"
                    >
                        {{ __('Annuler le brouillon') }}
                    </x-danger-button>
                @endcan
            @endif

            @if (
                $invoice->status
                    === SupplierInvoice::STATUS_VALIDATED
                && bccomp(
                    $remainingAmount,
                    '0.00',
                    2
                ) === 1
            )
                @can('payments.create')
                    <a
                        href="{{ route(
                            'purchases.invoices.payments.create',
                            $invoice
                        ) }}"
                        wire:navigate
                        class="inline-flex min-h-10 items-center bg-indigo-700 px-4 text-sm font-semibold text-danger hover:bg-indigo-600"
                    >
                        {{ __('Enregistrer un paiement') }}
                    </a>
                @endcan
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <x-input-error
        :messages="$errors->get('status')"
    />

    <x-input-error
        :messages="$errors->get('items')"
    />

    <x-input-error
        :messages="$errors->get('supplier_invoice_number')"
    />

    @php
        $statusLabels = [
            'draft' => __('Brouillon'),
            'validated' => __('Validée'),
            'cancelled' => __('Annulée'),
        ];

        $statusClasses = [
            'draft' => 'bg-gray-100 text-gray-700',
            'validated' => 'bg-emerald-100 text-emerald-800',
            'cancelled' => 'bg-rose-100 text-rose-800',
        ];

        $historyLabels = [
            'created' => __('Création'),
            'draft_updated' => __('Modification du brouillon'),
            'validated' => __('Validation de la facture fournisseur'),
            'cancelled' => __('Annulation'),
        ];
    @endphp

    <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
        <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-indigo-700">
                    {{
                        $invoice->number
                        ?? __('Brouillon #:id', [
                            'id' => $invoice->id,
                        ])
                    }}
                </p>

                <h3 class="mt-1 text-xl font-semibold text-gray-900">
                    {{ $invoice->supplier_name }}
                </h3>

                @if ($invoice->supplier_trade_name)
                    <p class="text-sm text-gray-700">
                        {{ $invoice->supplier_trade_name }}
                    </p>
                @endif

                <p class="mt-2 text-sm font-medium text-gray-700">
                    {{ __('Référence fournisseur') }}
                    :
                    {{ $invoice->supplier_invoice_number }}
                </p>

                <div class="mt-2 space-y-1 text-sm text-gray-600">
                    <p>
                        {{
                            collect([
                                $invoice->supplier_address,
                                $invoice->supplier_city,
                                $invoice->supplier_country,
                            ])
                                ->filter()
                                ->implode(', ')
                        }}
                    </p>

                    @if ($invoice->supplier_email)
                        <p>
                            {{ $invoice->supplier_email }}
                        </p>
                    @endif

                    @if ($invoice->supplier_phone)
                        <p>
                            {{ $invoice->supplier_phone }}
                        </p>
                    @endif

                    @if ($invoice->supplier_ice)
                        <p>
                            {{ __('ICE') }}
                            :
                            {{ $invoice->supplier_ice }}
                        </p>
                    @endif

                    @if ($invoice->supplier_tax_id)
                        <p>
                            {{ __('IF') }}
                            :
                            {{ $invoice->supplier_tax_id }}
                        </p>
                    @endif

                    @if ($invoice->supplier_commercial_register)
                        <p>
                            {{ __('RC') }}
                            :
                            {{ $invoice->supplier_commercial_register }}
                        </p>
                    @endif
                </div>
            </div>

            <span
                class="inline-block self-start px-3 py-1 text-sm font-medium {{ $statusClasses[$invoice->status] ?? 'bg-gray-100 text-gray-700' }}"
            >
                {{
                    $statusLabels[$invoice->status]
                    ?? $invoice->status
                }}
            </span>
        </div>

        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-gray-500">
                    {{ __('Date de facture') }}
                </dt>

                <dd class="mt-1 font-medium">
                    {{ $invoice->invoice_date->format('d/m/Y') }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Échéance') }}
                </dt>

                <dd class="mt-1 font-medium">
                    {{ $invoice->due_date?->format('d/m/Y') ?? '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('BCF source') }}
                </dt>

                <dd class="mt-1 font-medium">
                    <a
                        href="{{ route(
                            'purchases.orders.show',
                            $invoice->purchaseOrder
                        ) }}"
                        wire:navigate
                        class="text-indigo-700"
                    >
                        {{ $invoice->purchaseOrder->number }}
                    </a>
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Condition de paiement') }}
                </dt>

                <dd class="mt-1 font-medium">
                    {{ $invoice->payment_term_label ?? '—' }}

                    @if ($invoice->payment_term_days !== null)
                        ({{ $invoice->payment_term_days }} j)
                    @endif
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Créée par') }}
                </dt>

                <dd class="mt-1 font-medium">
                    {{ $invoice->creator?->name ?? '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Validée par') }}
                </dt>

                <dd class="mt-1 font-medium">
                    {{ $invoice->validator?->name ?? '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Validée le') }}
                </dt>

                <dd class="mt-1 font-medium">
                    {{
                        $invoice->validated_at
                            ?->format('d/m/Y H:i')
                        ?? '—'
                    }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Annulée le') }}
                </dt>

                <dd class="mt-1 font-medium">
                    {{
                        $invoice->cancelled_at
                            ?->format('d/m/Y H:i')
                        ?? '—'
                    }}
                </dd>
            </div>
        </dl>

        @if ($invoice->notes)
            <div class="mt-5 border-t border-gray-200 pt-4">
                <p class="text-sm font-semibold">
                    {{ __('Notes') }}
                </p>

                <p class="mt-1 whitespace-pre-line text-sm text-gray-600">
                    {{ $invoice->notes }}
                </p>
            </div>
        @endif
    </section>

    <section class="border-y border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-4">
            <h3 class="font-semibold text-gray-900">
                {{ __('Réceptions sources') }}
            </h3>
        </div>

        <div class="divide-y divide-gray-100">
            @foreach ($sourceReceipts as $receipt)
                <a
                    href="{{ route(
                        'purchases.receipts.show',
                        $receipt
                    ) }}"
                    wire:navigate
                    class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-gray-50"
                >
                    <span class="font-medium text-indigo-700">
                        {{ $receipt->number }}
                    </span>

                    <span class="text-sm text-gray-500">
                        {{ $receipt->receipt_date->format('d/m/Y') }}
                    </span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="overflow-hidden border-y border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">
                            {{ __('BRF') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Désignation') }}
                        </th>

                        <th class="px-4 py-3 text-right">
                            {{ __('Quantité') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Unité') }}
                        </th>

                        <th class="px-4 py-3 text-right">
                            {{ __('PU HT') }}
                        </th>

                        <th class="px-4 py-3 text-right">
                            {{ __('Remise') }}
                        </th>

                        <th class="px-4 py-3 text-right">
                            {{ __('TVA') }}
                        </th>

                        <th class="px-4 py-3 text-right">
                            {{ __('HT net') }}
                        </th>

                        <th class="px-4 py-3 text-right">
                            {{ __('TTC') }}
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-4">
                                <a
                                    href="{{ route(
                                        'purchases.receipts.show',
                                        $item
                                            ->goodsReceiptItem
                                            ->goodsReceipt
                                    ) }}"
                                    wire:navigate
                                    class="text-indigo-700"
                                >
                                    {{
                                        $item
                                            ->goodsReceiptItem
                                            ->goodsReceipt
                                            ->number
                                    }}
                                </a>
                            </td>

                            <td class="min-w-52 px-4 py-4">
                                <p class="font-medium">
                                    {{ $item->description }}
                                </p>

                                <p class="text-xs text-gray-500">
                                    {{ $item->reference ?? '—' }}
                                </p>
                            </td>

                            <td class="px-4 py-4 text-right">
                                {{ $item->quantity }}
                            </td>

                            <td class="px-4 py-4">
                                {{ $item->unit_label ?? '—' }}
                            </td>

                            <td class="px-4 py-4 text-right">
                                {{ str_replace(
                                    '.',
                                    ',',
                                    $item->unit_price
                                ) }}
                            </td>

                            <td class="px-4 py-4 text-right">
                                {{ str_replace(
                                    '.',
                                    ',',
                                    $item->discount_percent
                                ) }}
                                %

                                <span class="block text-xs text-gray-500">
                                    {{ str_replace(
                                        '.',
                                        ',',
                                        $item->discount_amount
                                    ) }}
                                </span>
                            </td>

                            <td class="px-4 py-4 text-right">
                                {{ str_replace(
                                    '.',
                                    ',',
                                    $item->tax_rate_percent
                                ) }}
                                %

                                <span class="block text-xs text-gray-500">
                                    {{ str_replace(
                                        '.',
                                        ',',
                                        $item->tax_amount
                                    ) }}
                                </span>
                            </td>

                            <td class="px-4 py-4 text-right">
                                {{ str_replace(
                                    '.',
                                    ',',
                                    $item->subtotal_ht
                                ) }}
                            </td>

                            <td class="px-4 py-4 text-right font-medium">
                                {{ str_replace(
                                    '.',
                                    ',',
                                    $item->total_ttc
                                ) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    </section>

    <section class="border-y border-gray-200 bg-white p-5">
        <dl class="ms-auto max-w-sm space-y-3 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-gray-600">
                    {{ __('Brut HT') }}
                </dt>

                <dd class="font-medium">
                    {{ str_replace(
                        '.',
                        ',',
                        $invoice->subtotal_ht
                    ) }}
                </dd>
            </div>

            <div class="flex justify-between gap-4">
                <dt class="text-gray-600">
                    {{ __('Remises') }}
                </dt>

                <dd class="font-medium">
                    {{ str_replace(
                        '.',
                        ',',
                        $invoice->discount_total
                    ) }}
                </dd>
            </div>

            <div class="flex justify-between gap-4 border-t border-gray-200 pt-3">
                <dt class="font-medium">
                    {{ __('HT net') }}
                </dt>

                <dd class="font-medium">
                    {{ str_replace(
                        '.',
                        ',',
                        $invoice->baseHt()
                    ) }}
                </dd>
            </div>

            <div class="flex justify-between gap-4">
                <dt class="text-gray-600">
                    {{ __('TVA') }}
                </dt>

                <dd class="font-medium">
                    {{ str_replace(
                        '.',
                        ',',
                        $invoice->tax_total
                    ) }}
                </dd>
            </div>

            <div class="flex justify-between gap-4 border-t border-gray-200 pt-3 text-base">
                <dt class="font-semibold">
                    {{ __('Total TTC') }}
                </dt>

                <dd class="font-semibold">
                    {{ str_replace(
                        '.',
                        ',',
                        $invoice->total_ttc
                    ) }}
                </dd>
            </div>
        </dl>
    </section>

    @if ($invoice->status === SupplierInvoice::STATUS_VALIDATED)
        @php
            $paymentLabels = [
                'unpaid' => __('Non payée'),
                'partially_paid' => __('Partiellement payée'),
                'paid' => __('Payée'),
                'overdue' => __('En retard'),
            ];

            $paymentClasses = [
                'unpaid' => 'bg-gray-100 text-gray-700',
                'partially_paid' => 'bg-amber-100 text-amber-800',
                'paid' => 'bg-emerald-100 text-emerald-800',
                'overdue' => 'bg-rose-100 text-rose-800',
            ];
        @endphp

        <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="font-semibold text-gray-900">
                        {{ __('Situation du règlement') }}
                    </h3>

                    <p class="mt-1 text-sm text-gray-500">
                        {{ __('Suivi automatique des paiements affectés à cette facture fournisseur.') }}
                    </p>
                </div>

                <span
                    class="inline-flex self-start px-3 py-1 text-sm font-medium {{ $paymentClasses[$paymentState] ?? 'bg-gray-100 text-gray-700' }}"
                >
                    {{
                        $paymentLabels[$paymentState]
                        ?? $paymentState
                    }}
                </span>
            </div>

            <dl class="mt-5 grid gap-4 sm:grid-cols-3">
                <div class="border border-gray-200 p-4">
                    <dt class="text-sm text-gray-500">
                        {{ __('Total TTC') }}
                    </dt>

                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                        {{ str_replace(
                            '.',
                            ',',
                            $invoice->total_ttc
                        ) }}
                    </dd>
                </div>

                <div class="border border-gray-200 p-4">
                    <dt class="text-sm text-gray-500">
                        {{ __('Déjà payé') }}
                    </dt>

                    <dd class="mt-1 text-lg font-semibold text-emerald-700">
                        {{ str_replace(
                            '.',
                            ',',
                            $paidAmount
                        ) }}
                    </dd>
                </div>

                <div class="border border-gray-200 p-4">
                    <dt class="text-sm text-gray-500">
                        {{ __('Reste à payer') }}
                    </dt>

                    <dd class="mt-1 text-lg font-semibold text-amber-700">
                        {{ str_replace(
                            '.',
                            ',',
                            $remainingAmount
                        ) }}
                    </dd>
                </div>
            </dl>

            @can('payments.view')
                @if ($invoice->paymentAllocations->isNotEmpty())
                    <div class="mt-6 border-t border-gray-200 pt-5">
                        <h4 class="font-medium text-gray-900">
                            {{ __('Paiements enregistrés') }}
                        </h4>

                        <div class="mt-3 divide-y divide-gray-100 border-y border-gray-200">
                            @foreach (
                                $invoice
                                    ->paymentAllocations
                                    ->sortByDesc(
                                        fn ($allocation) =>
                                            $allocation
                                                ->payment
                                                ->payment_date
                                                ->getTimestamp()
                                    )
                                as $allocation
                            )
                                <a
                                    href="{{ route(
                                        'purchases.payments.show',
                                        $allocation->payment
                                    ) }}"
                                    wire:navigate
                                    class="flex flex-col gap-2 py-4 hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <p class="font-medium text-indigo-700">
                                            {{ __('Paiement #:id', [
                                                'id' =>
                                                    $allocation
                                                        ->payment
                                                        ->id,
                                            ]) }}
                                        </p>

                                        <p class="text-sm text-gray-500">
                                            {{
                                                $allocation
                                                    ->payment
                                                    ->payment_date
                                                    ->format('d/m/Y')
                                            }}

                                            ·

                                            {{
                                                $allocation
                                                    ->payment
                                                    ->paymentMethod
                                                    ->name
                                            }}

                                            @if (
                                                $allocation
                                                    ->payment
                                                    ->reference
                                            )
                                                ·
                                                {{
                                                    $allocation
                                                        ->payment
                                                        ->reference
                                                }}
                                            @endif
                                        </p>
                                    </div>

                                    <p class="font-semibold text-gray-900">
                                        {{ str_replace(
                                            '.',
                                            ',',
                                            $allocation->amount
                                        ) }}
                                    </p>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endcan
        </section>
    @endif

    <section class="border-y border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-4">
            <h3 class="font-semibold text-gray-900">
                {{ __('Historique') }}
            </h3>
        </div>

        <ol class="divide-y divide-gray-100">
            @forelse ($invoice->histories as $history)
                <li class="flex flex-col gap-1 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="font-medium">
                            {{
                                $historyLabels[$history->event]
                                ?? $history->event
                            }}
                        </p>

                        @if ($history->description)
                            <p class="text-sm text-gray-600">
                                {{ __($history->description) }}
                            </p>
                        @endif

                        <p class="text-xs text-gray-500">
                            {{ $history->user?->name ?? '—' }}
                        </p>
                    </div>

                    <time class="whitespace-nowrap text-sm text-gray-500">
                        {{
                            $history->created_at
                                ?->format('d/m/Y H:i')
                            ?? '—'
                        }}
                    </time>
                </li>
            @empty
                <li class="px-5 py-4 text-sm text-gray-500">
                    {{ __('Aucun historique disponible.') }}
                </li>
            @endforelse
        </ol>
    </section>
</section>