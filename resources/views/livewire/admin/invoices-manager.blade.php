<?php

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $customerFilter = 'all';

    public function mount(): void
    {
        Gate::authorize('invoices.view');
    }

    public function updating(string $property): void
    {
        if (in_array($property, [
            'search',
            'statusFilter',
            'customerFilter',
        ], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        Gate::authorize('invoices.view');

        $statuses = [
            Invoice::STATUS_DRAFT,
            Invoice::STATUS_ISSUED,
            Invoice::STATUS_CANCELLED,
        ];

        $status = in_array($this->statusFilter, $statuses, true)
            ? $this->statusFilter
            : 'all';

        $customerId = $this->customerFilter === 'all'
            ? null
            : filter_var(
                $this->customerFilter,
                FILTER_VALIDATE_INT
            );

        $search = trim($this->search);

        return [
            'invoices' => Invoice::query()
                ->with([
                    'salesOrder:id,number',
                    'creator:id,name',
                    'issuer:id,name',
                ])
                ->when(
                    $status !== 'all',
                    fn ($query) => $query->where(
                        'status',
                        $status
                    )
                )
                ->when(
                    $customerId !== false
                        && $customerId !== null,
                    fn ($query) => $query->where(
                        'customer_id',
                        $customerId
                    )
                )
                ->when(
                    $search !== '',
                    fn ($query) => $query->where(
                        function ($query) use ($search): void {
                            $query
                                ->where(
                                    'number',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'customer_name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'customer_trade_name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'salesOrder',
                                    fn ($salesOrderQuery) => $salesOrderQuery
                                        ->where(
                                            'number',
                                            'like',
                                            "%{$search}%"
                                        )
                                );
                        }
                    )
                )
                ->latest('invoice_date')
                ->latest('id')
                ->paginate(12),

            'customers' => Customer::query()
                ->whereIn(
                    'id',
                    Invoice::query()->select('customer_id')
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'trade_name',
                    'status',
                ]),
        ];
    }
}; ?>

<section class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">
                {{ __('Toutes les factures') }}
            </h3>

            <p class="mt-1 text-sm text-gray-500">
                {{ __('Consultez les factures clients et leur état.') }}
            </p>
        </div>
    </div>

    <div class="border-y border-gray-200 bg-white p-4 sm:p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <x-input-label
                    for="invoice-search"
                    :value="__('Numéro, commande ou client')"
                />

                <x-text-input
                    id="invoice-search"
                    wire:model.live.debounce.300ms="search"
                    class="mt-1 block w-full"
                    placeholder="FAC-..."
                />
            </div>

            <div>
                <x-input-label
                    for="invoice-status"
                    :value="__('Statut')"
                />

                <select
                    id="invoice-status"
                    wire:model.live="statusFilter"
                    class="mt-1 block w-full border-gray-300 shadow-sm"
                >
                    <option value="all">
                        {{ __('Tous les statuts') }}
                    </option>

                    <option value="draft">
                        {{ __('Brouillon') }}
                    </option>

                    <option value="issued">
                        {{ __('Émise') }}
                    </option>

                    <option value="cancelled">
                        {{ __('Annulée') }}
                    </option>
                </select>
            </div>

            <div>
                <x-input-label
                    for="invoice-customer"
                    :value="__('Client')"
                />

                <select
                    id="invoice-customer"
                    wire:model.live="customerFilter"
                    class="mt-1 block w-full border-gray-300 shadow-sm"
                >
                    <option value="all">
                        {{ __('Tous les clients') }}
                    </option>

                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}">
                            {{ $customer->name }}

                            @if ($customer->trade_name)
                                · {{ $customer->trade_name }}
                            @endif
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

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

    <div class="overflow-hidden border-y border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-start text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">
                            {{ __('Numéro') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Date') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Client') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Commande') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Échéance') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Statut') }}
                        </th>

                        <th class="px-4 py-3 text-end">
                            {{ __('TVA') }}
                        </th>

                        <th class="px-4 py-3 text-end">
                            {{ __('TTC') }}
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($invoices as $invoice)
                        <tr
                            wire:key="invoice-{{ $invoice->id }}"
                            class="hover:bg-gray-50"
                        >
                            <td class="whitespace-nowrap px-4 py-4 font-semibold text-gray-900">
                               <a
    href="{{ route('sales.invoices.show', $invoice) }}"
    wire:navigate
    class="text-indigo-700 hover:text-indigo-900"
>
    @if ($invoice->number)
        {{ $invoice->number }}
    @else
        {{ __('Brouillon') }} #{{ $invoice->id }}
    @endif
</a>
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">
                                {{ $invoice->invoice_date->format('d/m/Y') }}
                            </td>

                            <td class="min-w-48 px-4 py-4">
                                <p class="font-medium text-gray-900">
                                    {{ $invoice->customer_name }}
                                </p>

                                @if ($invoice->customer_trade_name)
                                    <p class="text-xs text-gray-500">
                                        {{ $invoice->customer_trade_name }}
                                    </p>
                                @endif
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-gray-700">
                                {{ $invoice->salesOrder?->number ?? '—' }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">
                                {{ $invoice->due_date?->format('d/m/Y') ?? '—' }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-4">
                                <span
                                    class="inline-block px-2 py-1 text-xs font-medium {{ $statusClasses[$invoice->status] ?? 'bg-gray-100 text-gray-700' }}"
                                >
                                    {{ $statusLabels[$invoice->status] ?? $invoice->status }}
                                </span>
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-end text-gray-700">
                                {{ str_replace('.', ',', $invoice->tax_total) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-end font-semibold text-gray-900">
                                {{ str_replace('.', ',', $invoice->total_ttc) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="8"
                                class="px-6 py-10 text-center text-gray-500"
                            >
                                {{ __('Aucune facture ne correspond aux filtres.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>

        @if ($invoices->hasPages())
            <div class="border-t border-gray-200 px-4 py-4">
                {{ $invoices->links() }}
            </div>
        @endif
    </div>
</section>