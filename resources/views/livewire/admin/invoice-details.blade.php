<?php

use App\Models\Invoice;
use App\Services\InvoiceManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public int $invoiceId;

    public function mount(int $invoiceId): void
    {
        Gate::authorize('invoices.view');

        $this->invoiceId = $invoiceId;
    }

    public function issueInvoice(
        InvoiceManagementService $service
    ): void {
        Gate::authorize('invoices.validate');

        $invoice = Invoice::query()
            ->findOrFail($this->invoiceId);

        $service->issue($invoice);

        session()->flash(
            'status',
            __('La facture a été émise avec succès.')
        );
    }

    public function cancelInvoice(
        InvoiceManagementService $service
    ): void {
        Gate::authorize('invoices.create');

        $invoice = Invoice::query()
            ->findOrFail($this->invoiceId);

        $service->cancelDraft($invoice);

        session()->flash(
            'status',
            __('Le brouillon de facture a été annulé.')
        );
    }

    public function with(): array
    {
        Gate::authorize('invoices.view');

        return [
            'invoice' => Invoice::query()
                ->with([
                    'salesOrder:id,number',
                    'creator:id,name',
                    'issuer:id,name',
                    'items',
                ])
                ->findOrFail($this->invoiceId),
        ];
    }
};
?>

<section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a
            href="{{ route('sales.invoices.index') }}"
            wire:navigate
            class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
        >
            {{ __('Retour aux factures') }}
        </a>

        <div class="flex flex-wrap gap-2">
            @if ($invoice->isIssued())
                <a
                    href="{{ route('sales.invoices.pdf', $invoice) }}"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    {{ __('Télécharger le PDF') }}
                </a>

                @can('invoices.create')
                    <a
                        href="{{ route('sales.invoices.credit-notes.create', $invoice) }}"
                        wire:navigate
                        class="inline-flex min-h-10 items-center bg-gray-800 px-4 text-sm font-semibold uppercase tracking-widest text-white transition hover:bg-gray-700"
                    >
                        {{ __('Créer un avoir') }}
                    </a>
                @endcan
            @endif

            @if ($invoice->isEditable())
                @can('invoices.validate')
                    <x-primary-button
                        type="button"
                        wire:click="issueInvoice"
                        wire:confirm="{{ __('Émettre définitivement cette facture ? Après émission, elle ne pourra plus être modifiée ni annulée.') }}"
                    >
                        {{ __('Émettre la facture') }}
                    </x-primary-button>
                @endcan

                @can('invoices.create')
                    <x-danger-button
                        type="button"
                        wire:click="cancelInvoice"
                        wire:confirm="{{ __('Annuler ce brouillon de facture ?') }}"
                    >
                        {{ __('Annuler le brouillon') }}
                    </x-danger-button>
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
        :messages="$errors->get('invoice')"
    />

    @php
        $statusLabels = [
            'draft' => __('Brouillon'),
            'issued' => __('Émise'),
            'cancelled' => __('Annulée'),
        ];

        $statusClasses = [
            'draft' => 'bg-gray-100 text-gray-700',
            'issued' => 'bg-emerald-100 text-emerald-800',
            'cancelled' => 'bg-rose-100 text-rose-800',
        ];
    @endphp

    <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
        <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-indigo-700">
                    {{ $invoice->number ?? __('Brouillon #:id', ['id' => $invoice->id]) }}
                </p>

                <h3 class="mt-1 text-xl font-semibold text-gray-900">
                    {{ $invoice->customer_name }}
                </h3>

                @if ($invoice->customer_trade_name)
                    <p class="text-sm text-gray-700">
                        {{ $invoice->customer_trade_name }}
                    </p>
                @endif

                <div class="mt-2 space-y-1 text-sm text-gray-600">
                    <p>
                        {{
                            collect([
                                $invoice->customer_address,
                                $invoice->customer_city,
                                $invoice->customer_country,
                            ])->filter()->implode(', ')
                        }}
                    </p>

                    @if ($invoice->customer_email)
                        <p>
                            {{ $invoice->customer_email }}
                        </p>
                    @endif

                    @if ($invoice->customer_phone)
                        <p>
                            {{ $invoice->customer_phone }}
                        </p>
                    @endif
                </div>
            </div>

            <span
                class="inline-block self-start px-3 py-1 text-sm font-medium {{ $statusClasses[$invoice->status] ?? 'bg-gray-100 text-gray-700' }}"
            >
                {{ $statusLabels[$invoice->status] ?? $invoice->status }}
            </span>
        </div>

        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-gray-500">
                    {{ __('Date facture') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $invoice->invoice_date->format('d/m/Y') }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Échéance') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $invoice->due_date?->format('d/m/Y') ?? '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Commande') }}
                </dt>

                <dd class="mt-1 font-medium">
                    @if ($invoice->salesOrder)
                        <a
                            href="{{ route('sales.orders.show', $invoice->salesOrder) }}"
                            wire:navigate
                            class="text-indigo-700 hover:text-indigo-900"
                        >
                            {{ $invoice->salesOrder->number }}
                        </a>
                    @else
                        —
                    @endif
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Créée par') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $invoice->creator?->name ?? '—' }}
                </dd>
            </div>
        </dl>
    </section>

    <section class="overflow-hidden border-y border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
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
                            {{ __('HT') }}
                        </th>

                        <th class="px-4 py-3 text-right">
                            {{ __('TTC') }}
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="min-w-56 px-4 py-4">
                                <p class="font-medium text-gray-900">
                                    {{ $item->description }}
                                </p>

                                <p class="text-xs text-gray-500">
                                    {{ $item->reference ?? '—' }}
                                </p>
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right">
                                {{ $item->quantity }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-4">
                                {{ $item->unit_label ?? '—' }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right">
                                {{ str_replace('.', ',', $item->unit_price) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right">
                                {{ str_replace('.', ',', $item->discount_percent) }} %
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right">
                                {{ str_replace('.', ',', $item->tax_rate_percent) }} %
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right">
                                {{ str_replace('.', ',', $item->subtotal_ht) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right font-medium">
                                {{ str_replace('.', ',', $item->total_ttc) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-4">
            @if ($invoice->terms)
                <section class="border-y border-gray-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-gray-900">
                        {{ __('Conditions') }}
                    </h3>

                    <p class="mt-2 whitespace-pre-line text-sm text-gray-600">
                        {{ $invoice->terms }}
                    </p>
                </section>
            @endif

            @if ($invoice->notes)
                <section class="border-y border-gray-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-gray-900">
                        {{ __('Notes') }}
                    </h3>

                    <p class="mt-2 whitespace-pre-line text-sm text-gray-600">
                        {{ $invoice->notes }}
                    </p>
                </section>
            @endif
        </div>

        <section class="border-y border-gray-200 bg-white p-5">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-600">
                        {{ __('Brut HT') }}
                    </dt>

                    <dd class="font-medium">
                        {{ str_replace('.', ',', $invoice->subtotal_ht) }}
                    </dd>
                </div>

                <div class="flex justify-between gap-4">
                    <dt class="text-gray-600">
                        {{ __('Remises') }}
                    </dt>

                    <dd class="font-medium">
                        {{ str_replace('.', ',', $invoice->discount_total) }}
                    </dd>
                </div>

                <div class="flex justify-between gap-4">
                    <dt class="text-gray-600">
                        {{ __('TVA') }}
                    </dt>

                    <dd class="font-medium">
                        {{ str_replace('.', ',', $invoice->tax_total) }}
                    </dd>
                </div>

                <div class="flex justify-between gap-4 border-t border-gray-200 pt-3 text-base">
                    <dt class="font-semibold text-gray-900">
                        {{ __('Total TTC') }}
                    </dt>

                    <dd class="font-semibold text-gray-950">
                        {{ str_replace('.', ',', $invoice->total_ttc) }}
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</section>