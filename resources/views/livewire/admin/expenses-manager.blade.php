<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $search = '';

    public string $categoryFilter = 'all';

    public string $paymentMethodFilter = 'all';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        Gate::authorize('payments.view');
    }

    public function updating(string $property): void
    {
        if (in_array($property, [
            'search',
            'categoryFilter',
            'paymentMethodFilter',
            'dateFrom',
            'dateTo',
        ], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset([
            'search',
            'categoryFilter',
            'paymentMethodFilter',
            'dateFrom',
            'dateTo',
        ]);

        $this->resetPage();
    }

    public function with(): array
    {
        Gate::authorize('payments.view');

        $query = Expense::query()
            ->with([
                'category:id,name',
                'paymentMethod:id,name,payment_type',
                'cashRegister:id,code,name',
                'creator:id,name',
            ]);

        $search = trim($this->search);

        $query
            ->when(
                $search !== '',
                fn ($query) => $query->where(
                    function ($query) use ($search): void {
                        $query
                            ->where('reference', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhereHas(
                                'category',
                                fn ($categoryQuery) => $categoryQuery
                                    ->where('name', 'like', "%{$search}%")
                            );
                    }
                )
            )
            ->when(
                $this->categoryFilter !== 'all',
                fn ($query) => $query->where(
                    'expense_category_id',
                    (int) $this->categoryFilter
                )
            )
            ->when(
                $this->paymentMethodFilter !== 'all',
                fn ($query) => $query->where(
                    'payment_method_id',
                    (int) $this->paymentMethodFilter
                )
            )
            ->when(
                $this->dateFrom !== '',
                fn ($query) => $query->whereDate(
                    'expense_date',
                    '>=',
                    $this->dateFrom
                )
            )
            ->when(
                $this->dateTo !== '',
                fn ($query) => $query->whereDate(
                    'expense_date',
                    '<=',
                    $this->dateTo
                )
            );

        $summaryQuery = clone $query;

        return [
            'expenses' => $query
                ->latest('expense_date')
                ->latest('id')
                ->paginate(12),

            'categories' => ExpenseCategory::query()
                ->orderBy('name')
                ->get(['id', 'name']),

            'paymentMethods' => PaymentMethod::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name']),

            'expenseCount' => (clone $summaryQuery)->count(),

            'totalExpenses' => (string) (
                (clone $summaryQuery)->sum('amount')
            ),

            'totalTax' => (string) (
                (clone $summaryQuery)->sum('tax_amount')
            ),

            'cashExpenses' => (string) (
                (clone $summaryQuery)
                    ->whereNotNull('cash_register_id')
                    ->sum('amount')
            ),
        ];
    }
};
?>

<section class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">
                {{ __('Dépenses') }}
            </h3>

            <p class="mt-1 text-sm text-gray-500">
                {{ __('Suivez les charges, la TVA et les règlements associés.') }}
            </p>
        </div>

        @can('payments.create')
            <div class="flex gap-2">
                <a
                    href="{{ route('finance.cash.index') }}"
                    wire:navigate
                    class="inline-flex min-h-10 items-center justify-center border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                >
                    {{ __('Caisse') }}
                </a>

                <a
                    href="{{ route('finance.expenses.create') }}"
                    wire:navigate
                    class="inline-flex min-h-10 items-center justify-center bg-gray-900 px-4 text-sm font-semibold text-white transition hover:bg-gray-700"
                >
                    {{ __('Nouvelle dépense') }}
                </a>
            </div>
        @endcan
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="border border-gray-200 bg-white p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                {{ __('Total dépenses') }}
            </p>

            <p class="mt-2 text-2xl font-semibold text-gray-900">
                {{ number_format((float) $totalExpenses, 2, ',', ' ') }}
                <span class="text-sm font-medium text-gray-500">DH</span>
            </p>
        </div>

        <div class="border border-gray-200 bg-white p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                {{ __('TVA comprise') }}
            </p>

            <p class="mt-2 text-2xl font-semibold text-gray-900">
                {{ number_format((float) $totalTax, 2, ',', ' ') }}
                <span class="text-sm font-medium text-gray-500">DH</span>
            </p>
        </div>

        <div class="border border-gray-200 bg-white p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                {{ __('Payées en espèces') }}
            </p>

            <p class="mt-2 text-2xl font-semibold text-gray-900">
                {{ number_format((float) $cashExpenses, 2, ',', ' ') }}
                <span class="text-sm font-medium text-gray-500">DH</span>
            </p>
        </div>

        <div class="border border-gray-200 bg-white p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                {{ __('Nombre de dépenses') }}
            </p>

            <p class="mt-2 text-2xl font-semibold text-gray-900">
                {{ $expenseCount }}
            </p>
        </div>
    </div>

    <section class="border border-gray-200 bg-white p-5">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div>
                <x-input-label for="expense-search" :value="__('Recherche')" />

                <x-text-input
                    id="expense-search"
                    wire:model.live.debounce.300ms="search"
                    class="mt-1 block w-full"
                    placeholder="Référence, description..."
                />
            </div>

            <div>
                <x-input-label for="category-filter" :value="__('Catégorie')" />

                <select
                    id="category-filter"
                    wire:model.live="categoryFilter"
                    class="mt-1 block w-full border-gray-300 shadow-sm"
                >
                    <option value="all">{{ __('Toutes') }}</option>

                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="payment-filter" :value="__('Paiement')" />

                <select
                    id="payment-filter"
                    wire:model.live="paymentMethodFilter"
                    class="mt-1 block w-full border-gray-300 shadow-sm"
                >
                    <option value="all">{{ __('Tous') }}</option>

                    @foreach ($paymentMethods as $method)
                        <option value="{{ $method->id }}">
                            {{ $method->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="date-from" :value="__('Du')" />

                <input
                    id="date-from"
                    type="date"
                    wire:model.live="dateFrom"
                    class="mt-1 block w-full border-gray-300 shadow-sm"
                >
            </div>

            <div>
                <x-input-label for="date-to" :value="__('Au')" />

                <input
                    id="date-to"
                    type="date"
                    wire:model.live="dateTo"
                    class="mt-1 block w-full border-gray-300 shadow-sm"
                >
            </div>
        </div>

        <button
            type="button"
            wire:click="resetFilters"
            class="mt-4 text-sm font-medium text-gray-600 underline hover:text-gray-900"
        >
            {{ __('Réinitialiser les filtres') }}
        </button>
    </section>

    <section class="overflow-hidden border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">
                            {{ __('Date') }}
                        </th>

                        <th class="px-4 py-3 text-left font-semibold text-gray-600">
                            {{ __('Dépense') }}
                        </th>

                        <th class="px-4 py-3 text-left font-semibold text-gray-600">
                            {{ __('Paiement') }}
                        </th>

                        <th class="px-4 py-3 text-right font-semibold text-gray-600">
                            {{ __('TVA') }}
                        </th>

                        <th class="px-4 py-3 text-right font-semibold text-gray-600">
                            {{ __('Montant') }}
                        </th>

                        <th class="px-4 py-3 text-left font-semibold text-gray-600">
                            {{ __('Responsable') }}
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($expenses as $expense)
                        <tr class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">
                                {{ $expense->expense_date->format('d/m/Y') }}
                            </td>

                            <td class="px-4 py-4">
                                <p class="font-semibold text-gray-900">
                                    {{ $expense->category->name }}
                                </p>

                                <div class="mt-1 text-xs text-gray-500">
                                    @if ($expense->reference)
                                        <span>{{ $expense->reference }}</span>
                                    @endif

                                    @if ($expense->description)
                                        <span>
                                            {{ $expense->reference ? ' · ' : '' }}
                                            {{ $expense->description }}
                                        </span>
                                    @endif
                                </div>
                            </td>

                            <td class="px-4 py-4 text-gray-600">
                                <p>{{ $expense->paymentMethod->name }}</p>

                                @if ($expense->cashRegister)
                                    <p class="mt-1 text-xs text-gray-400">
                                        {{ $expense->cashRegister->name }}
                                    </p>
                                @endif
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right text-gray-600">
                                {{ number_format((float) $expense->tax_amount, 2, ',', ' ') }}
                                DH
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right font-semibold text-gray-900">
                                {{ number_format((float) $expense->amount, 2, ',', ' ') }}
                                DH
                            </td>

                            <td class="px-4 py-4 text-gray-600">
                                {{ $expense->creator?->name ?? '—' }}
                            <a class="mt-1 block text-indigo-600" href="{{ route('attachments.index', ['parentType' => 'expense', 'parentId' => $expense->id]) }}" wire:navigate>Documents</a>
</td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="6"
                                class="px-6 py-12 text-center text-sm text-gray-500"
                            >
                                {{ __('Aucune dépense trouvée.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>
    </section>

    @if ($expenses->hasPages())
        <div>
            {{ $expenses->links() }}
        </div>
    @endif
</section>