<?php

use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $search = '';

    #[Url(as: 'supplier')]
    public string $supplierFilter = 'all';

    public function mount(): void
    {
        Gate::authorize('payments.view');
    }

    public function updating(string $property): void
    {
        if (in_array(
            $property,
            ['search', 'supplierFilter'],
            true
        )) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        Gate::authorize('payments.view');

        $supplierId = $this->supplierFilter === 'all'
            ? null
            : filter_var(
                $this->supplierFilter,
                FILTER_VALIDATE_INT
            );

        $search = trim($this->search);

        return [
            'payments' => SupplierPayment::query()
                ->with([
                    'supplier:id,name,trade_name,code',
                    'paymentMethod:id,name,payment_type',
                    'creator:id,name',
                    'allocations.supplierInvoice:id,number,supplier_invoice_number',
                ])
                ->when(
                    $supplierId !== false
                        && $supplierId !== null,
                    fn ($query) => $query->where(
                        'supplier_id',
                        $supplierId
                    )
                )
                ->when(
                    $search !== '',
                    fn ($query) => $query->where(
                        function ($query) use ($search): void {
                            $query
                                ->where(
                                    'reference',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'supplier',
                                    function ($supplierQuery) use ($search): void {
                                        $supplierQuery
                                            ->where(
                                                'name',
                                                'like',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'trade_name',
                                                'like',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'code',
                                                'like',
                                                "%{$search}%"
                                            );
                                    }
                                )
                                ->orWhereHas(
                                    'allocations.supplierInvoice',
                                    function ($invoiceQuery) use ($search): void {
                                        $invoiceQuery
                                            ->where(
                                                'number',
                                                'like',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'supplier_invoice_number',
                                                'like',
                                                "%{$search}%"
                                            );
                                    }
                                );
                        }
                    )
                )
                ->latest('payment_date')
                ->latest('id')
                ->paginate(12),

            'suppliers' => Supplier::query()
                ->whereIn(
                    'id',
                    SupplierPayment::query()
                        ->select('supplier_id')
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'trade_name',
                    'code',
                ]),
        ];
    }
};
?>

<section class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">
                {{ __('Paiements fournisseurs') }}
            </h3>

            <p class="mt-1 text-sm text-gray-500">
                {{ __('Consultez les règlements fournisseurs et leurs affectations aux factures FAF.') }}
            </p>
        </div>

        @can('payments.create')
            <a
                href="{{ route('purchases.payments.create') }}"
                wire:navigate
                class="inline-flex min-h-10 items-center justify-center bg-gray-900 px-4 text-sm font-semibold uppercase tracking-widest text-white transition hover:bg-gray-700"
            >
                {{ __('Nouveau paiement') }}
            </a>
        @endcan
    </div>

    @if (session('status'))
        <div class="border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <x-input-label
                    for="supplier-payment-search"
                    :value="__('Recherche')"
                />

                <x-text-input
                    id="supplier-payment-search"
                    wire:model.live.debounce.300ms="search"
                    class="mt-1 block w-full"
                    :placeholder="__('Référence, fournisseur, FAF...')"
                />
            </div>

            <div>
                <x-input-label
                    for="supplier-payment-supplier-filter"
                    :value="__('Fournisseur')"
                />

                <select
                    id="supplier-payment-supplier-filter"
                    wire:model.live="supplierFilter"
                    class="mt-1 block w-full border-gray-300 shadow-sm"
                >
                    <option value="all">
                        {{ __('Tous les fournisseurs') }}
                    </option>

                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}">
                            {{ $supplier->name }}

                            @if ($supplier->trade_name)
                                · {{ $supplier->trade_name }}
                            @endif
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    <section class="overflow-hidden border-y border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-start text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">
                            {{ __('Date') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Fournisseur') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Mode') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Référence') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Factures') }}
                        </th>

                        <th class="px-4 py-3 text-end">
                            {{ __('Montant') }}
                        </th>

                        <th class="px-4 py-3 text-end">
                            {{ __('Action') }}
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($payments as $payment)
                        <tr wire:key="supplier-payment-{{ $payment->id }}">
                            <td class="whitespace-nowrap px-4 py-4">
                                {{ $payment->payment_date->format('d/m/Y') }}
                            </td>

                            <td class="min-w-48 px-4 py-4">
                                <p class="font-medium text-gray-900">
                                    {{ $payment->supplier->name }}
                                </p>

                                <p class="text-xs text-gray-500">
                                    {{ $payment->supplier->code }}
                                </p>
                            </td>

                            <td class="whitespace-nowrap px-4 py-4">
                                {{ $payment->paymentMethod->name }}
                            </td>

                            <td class="px-4 py-4">
                                {{ $payment->reference ?? '—' }}
                            </td>

                            <td class="min-w-48 px-4 py-4">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($payment->allocations as $allocation)
                                        @can('purchases.view')
                                            <a
                                                href="{{ route(
                                                    'purchases.invoices.show',
                                                    $allocation->supplierInvoice
                                                ) }}"
                                                wire:navigate
                                                class="inline-flex bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                                            >
                                                {{ $allocation->supplierInvoice->number }}
                                            </a>
                                        @else
                                            <span class="inline-flex bg-gray-100 px-2 py-1 text-xs text-gray-700">
                                                {{ $allocation->supplierInvoice->number }}
                                            </span>
                                        @endcan
                                    @endforeach
                                </div>
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-end font-semibold text-gray-900">
                                {{ number_format(
                                    (float) $payment->amount,
                                    2,
                                    ',',
                                    ' '
                                ) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-end">
                                <a
                                    href="{{ route(
                                        'purchases.payments.show',
                                        $payment
                                    ) }}"
                                    wire:navigate
                                    class="font-medium text-indigo-700 hover:text-indigo-900"
                                >
                                    {{ __('Consulter') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="7"
                                class="px-6 py-10 text-center text-gray-500"
                            >
                                {{ __('Aucun paiement fournisseur ne correspond aux filtres.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>

        @if ($payments->hasPages())
            <div class="border-t border-gray-200 px-4 py-4">
                {{ $payments->links() }}
            </div>
        @endif
    </section>
</section>