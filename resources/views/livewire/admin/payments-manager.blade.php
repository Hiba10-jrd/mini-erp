<?php

use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $search = '';

    public string $customerFilter = 'all';

    public function mount(): void
    {
        Gate::authorize('payments.view');
    }

    public function updating(string $property): void
    {
        if (in_array(
            $property,
            [
                'search',
                'customerFilter',
            ],
            true
        )) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        Gate::authorize('payments.view');

        $customerId = $this->customerFilter === 'all'
            ? null
            : filter_var(
                $this->customerFilter,
                FILTER_VALIDATE_INT
            );

        $search = trim($this->search);

        return [
            'payments' => Payment::query()
                ->with([
                    'customer:id,name,trade_name',
                    'paymentMethod:id,name',
                    'allocations.invoice:id,number',
                    'creator:id,name',
                ])
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
                                    'reference',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'customer',
                                    function ($customerQuery) use ($search): void {
                                        $customerQuery
                                            ->where(
                                                'name',
                                                'like',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'trade_name',
                                                'like',
                                                "%{$search}%"
                                            );
                                    }
                                )
                                ->orWhereHas(
                                    'allocations.invoice',
                                    fn ($invoiceQuery) => $invoiceQuery
                                        ->where(
                                            'number',
                                            'like',
                                            "%{$search}%"
                                        )
                                );
                        }
                    )
                )
                ->latest('payment_date')
                ->latest('id')
                ->paginate(12),

            'customers' => Customer::query()
                ->whereIn(
                    'id',
                    Payment::query()->select('customer_id')
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'trade_name',
                ]),
        ];
    }
};
?>

<section class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">
                {{ __('Paiements clients') }}
            </h3>

            <p class="mt-1 text-sm text-gray-500">
                {{ __('Consultez les règlements clients et leurs affectations aux factures.') }}
            </p>
        </div>

        @can('payments.create')
            <a
                href="{{ route('sales.payments.create') }}"
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
                    for="payment-search"
                    :value="__('Recherche')"
                />

                <x-text-input
                    id="payment-search"
                    wire:model.live.debounce.300ms="search"
                    class="mt-1 block w-full"
                    :placeholder="__('Référence, client ou facture')"
                />
            </div>

            <div>
                <x-input-label
                    for="payment-customer-filter"
                    :value="__('Client')"
                />

                <select
                    id="payment-customer-filter"
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
    </section>

    <section class="border-y border-gray-200 bg-white">
        @forelse ($payments as $payment)
            <article class="border-b border-gray-200 p-5 last:border-b-0 sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="font-semibold text-gray-900">
                            {{ $payment->customer->name }}
                        </p>

                        @if ($payment->customer->trade_name)
                            <p class="text-sm text-gray-500">
                                {{ $payment->customer->trade_name }}
                            </p>
                        @endif

                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-500">
                            <span>
                                {{ $payment->payment_date->format('d/m/Y') }}
                            </span>

                            <span>
                                {{ $payment->paymentMethod->name }}
                            </span>

                            @if ($payment->reference)
                                <span>
                                    {{ __('Réf. : :reference', [
                                        'reference' => $payment->reference,
                                    ]) }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="sm:text-end">
                        <p class="text-lg font-semibold text-gray-900">
                            {{ number_format(
                                (float) $payment->amount,
                                2,
                                ',',
                                ' '
                            ) }}
                        </p>

                        @if ($payment->creator)
                            <p class="mt-1 text-xs text-gray-500">
                                {{ __('Saisi par :name', [
                                    'name' => $payment->creator->name,
                                ]) }}
                            </p>
                        @endif
                    </div>
                </div>

                <div class="mt-4 border-t border-gray-100 pt-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        {{ __('Affectations') }}
                    </p>

                    <div class="mt-2 space-y-2">
                        @foreach ($payment->allocations as $allocation)
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <a
                                    href="{{ route(
                                        'sales.invoices.show',
                                        $allocation->invoice
                                    ) }}"
                                    wire:navigate
                                    class="font-medium text-indigo-700 hover:text-indigo-900"
                                >
                                    {{ $allocation->invoice->number }}
                                </a>

                                <span class="font-medium text-gray-700">
                                    {{ number_format(
                                        (float) $allocation->amount,
                                        2,
                                        ',',
                                        ' '
                                    ) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($payment->notes)
                    <div class="mt-4 border-t border-gray-100 pt-4">
                        <p class="text-sm text-gray-600">
                            {{ $payment->notes }}
                        </p>
                    </div>
                @endif
                <a class="mt-3 block text-indigo-600" href="{{ route('attachments.index', ['parentType' => 'payment', 'parentId' => $payment->id]) }}" wire:navigate>{{ __('Documents') }}</a>
            </article>
        @empty
            <div class="p-6 text-sm text-gray-500">
                {{ __('Aucun paiement trouvé.') }}
            </div>
        @endforelse
    </section>

    @if ($payments->hasPages())
        <div>
            {{ $payments->links() }}
        </div>
    @endif
</section>
