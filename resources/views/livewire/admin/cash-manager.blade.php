<?php

use App\Models\CashRegister;
use App\Models\CashTransaction;
use App\Models\ExpenseCategory;
use App\Services\CashManagementService;
use App\Services\ExpenseCategoryManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $registerId = '';

    public string $transactionType = CashTransaction::TYPE_ENTRY;

    public string $transactionDate = '';

    public string $amount = '';

    public string $reference = '';

    public string $description = '';

    public string $newRegisterCode = '';

    public string $newRegisterName = '';

    public string $newRegisterInitialBalance = '0.00';

    public bool $showTransactionForm = false;

    public bool $showRegisterForm = false;

    public string $categoryName = '';

    public string $categoryDescription = '';

    public bool $showCategoryForm = false;

    public function mount(): void
    {
        Gate::authorize('payments.view');

        $this->transactionDate = today()->toDateString();
    }

    public function prepareTransaction(
        int $cashRegisterId,
        string $type
    ): void {
        Gate::authorize('payments.create');

        if (! in_array(
            $type,
            CashTransaction::types(),
            true
        )) {
            abort(422);
        }

        CashRegister::query()
            ->where('is_active', true)
            ->findOrFail($cashRegisterId);

        $this->resetValidation();

        $this->registerId = (string) $cashRegisterId;
        $this->transactionType = $type;
        $this->transactionDate = today()->toDateString();
        $this->amount = '';
        $this->reference = '';
        $this->description = '';

        $this->showTransactionForm = true;
        $this->showRegisterForm = false;
        $this->showCategoryForm = false;
    }

    public function cancelTransaction(): void
    {
        $this->resetValidation();

        $this->showTransactionForm = false;
    }

    public function saveTransaction(
        CashManagementService $service
    ): void {
        Gate::authorize('payments.create');

        $validated = $this->validate([
            'registerId' => [
                'required',
                'integer',
                Rule::exists('cash_registers', 'id'),
            ],
            'transactionType' => [
                'required',
                Rule::in(CashTransaction::types()),
            ],
            'transactionDate' => [
                'required',
                'date',
            ],
            'amount' => [
                'required',
                'regex:/^\d+(?:\.\d{1,2})?$/',
            ],
            'reference' => [
                'nullable',
                'string',
                'max:120',
            ],
            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $service->createManualTransaction(
            (int) $validated['registerId'],
            [
                'transaction_date' => $validated['transactionDate'],
                'type' => $validated['transactionType'],
                'amount' => $validated['amount'],
                'reference' => $validated['reference'] ?? null,
                'description' => $validated['description'] ?? null,
            ]
        );

        $this->showTransactionForm = false;
        $this->resetPage();

        session()->flash(
            'status',
            __('Le mouvement de caisse a été enregistré.')
        );
    }

    public function prepareRegister(): void
    {
        Gate::authorize('payments.create');

        $this->resetValidation();

        $this->newRegisterCode = '';
        $this->newRegisterName = '';
        $this->newRegisterInitialBalance = '0.00';

        $this->showRegisterForm = true;
        $this->showTransactionForm = false;
        $this->showCategoryForm = false;
    }

    public function cancelRegister(): void
    {
        $this->resetValidation();

        $this->showRegisterForm = false;
    }

    public function saveRegister(
        CashManagementService $service
    ): void {
        Gate::authorize('payments.create');

        $validated = $this->validate([
            'newRegisterCode' => [
                'required',
                'string',
                'max:30',
            ],
            'newRegisterName' => [
                'required',
                'string',
                'max:120',
            ],
            'newRegisterInitialBalance' => [
                'required',
                'regex:/^\d+(?:\.\d{1,2})?$/',
            ],
        ], [
            'newRegisterCode.required' => __('Le code est obligatoire.'),
            'newRegisterName.required' => __('Le nom est obligatoire.'),
            'newRegisterInitialBalance.regex' => __('Le solde initial doit contenir au maximum deux décimales.'),
        ]);

        $service->createRegister([
            'code' => $validated['newRegisterCode'],
            'name' => $validated['newRegisterName'],
            'initial_balance' => $validated['newRegisterInitialBalance'],
            'is_active' => true,
        ]);

        $this->showRegisterForm = false;

        session()->flash(
            'status',
            __('La caisse a été créée.')
        );
    }

    public function prepareCategory(): void
    {
        Gate::authorize('payments.create');

        $this->resetValidation();

        $this->categoryName = '';
        $this->categoryDescription = '';

        $this->showCategoryForm = true;
        $this->showRegisterForm = false;
        $this->showTransactionForm = false;
    }

    public function cancelCategory(): void
    {
        $this->resetValidation();

        $this->showCategoryForm = false;
    }

    public function saveCategory(
        ExpenseCategoryManagementService $service
    ): void {
        Gate::authorize('payments.create');

        $validated = $this->validate([
            'categoryName' => [
                'required',
                'string',
                'max:120',
            ],
            'categoryDescription' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ], [
            'categoryName.required' => __('Le nom de la catégorie est obligatoire.'),
        ]);

        $service->create([
            'name' => $validated['categoryName'],
            'description' => $validated['categoryDescription'] ?? null,
            'is_active' => true,
        ]);

        $this->showCategoryForm = false;
        $this->categoryName = '';
        $this->categoryDescription = '';

        session()->flash(
            'status',
            __('La catégorie de dépense a été créée.')
        );
    }

    public function toggleCategory(
        int $categoryId,
        ExpenseCategoryManagementService $service
    ): void {
        Gate::authorize('payments.create');

        $category = ExpenseCategory::query()
            ->findOrFail($categoryId);

        $service->setActive(
            $category,
            ! $category->is_active
        );

        session()->flash(
            'status',
            $category->is_active
                ? __('La catégorie a été désactivée.')
                : __('La catégorie a été activée.')
        );
    }

    public function with(): array
    {
        Gate::authorize('payments.view');

        $service = app(CashManagementService::class);

        $registers = CashRegister::query()
            ->withCount([
                'transactions as entries_count' => fn ($query) => $query
                    ->where(
                        'type',
                        CashTransaction::TYPE_ENTRY
                    ),
                'transactions as exits_count' => fn ($query) => $query
                    ->where(
                        'type',
                        CashTransaction::TYPE_EXIT
                    ),
            ])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $registers->each(function (
            CashRegister $register
        ) use ($service): void {
            $register->setAttribute(
                'current_balance',
                $service->currentBalance($register)
            );

            $register->setAttribute(
                'entries_total',
                (string) CashTransaction::query()
                    ->where(
                        'cash_register_id',
                        $register->id
                    )
                    ->where(
                        'type',
                        CashTransaction::TYPE_ENTRY
                    )
                    ->sum('amount')
            );

            $register->setAttribute(
                'exits_total',
                (string) CashTransaction::query()
                    ->where(
                        'cash_register_id',
                        $register->id
                    )
                    ->where(
                        'type',
                        CashTransaction::TYPE_EXIT
                    )
                    ->sum('amount')
            );
        });

        return [
            'registers' => $registers,

            'categories' => ExpenseCategory::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),

            'transactions' => CashTransaction::query()
                ->with([
                    'cashRegister:id,code,name',
                    'expense:id,expense_category_id,reference,description',
                    'expense.category:id,name',
                    'creator:id,name',
                ])
                ->latest('transaction_date')
                ->latest('id')
                ->paginate(15),
        ];
    }
};
?>

<section class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">
                {{ __('Caisse') }}
            </h3>

            <p class="mt-1 text-sm text-gray-500">
                {{ __('Consultez les soldes, les catégories et l’historique des mouvements.') }}
            </p>
        </div>

        @can('payments.create')
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    wire:click="prepareCategory"
                    class="inline-flex min-h-10 items-center justify-center border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                >
                    {{ __('Nouvelle catégorie') }}
                </button>

                <button
                    type="button"
                    wire:click="prepareRegister"
                    class="inline-flex min-h-10 items-center justify-center bg-gray-900 px-4 text-sm font-semibold text-white transition hover:bg-gray-700"
                >
                    {{ __('Nouvelle caisse') }}
                </button>
            </div>
        @endcan
    </div>

    @if (session('status'))
        <div class="border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($showRegisterForm)
        <section class="border border-gray-200 bg-white p-5 sm:p-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h4 class="font-semibold text-gray-900">
                        {{ __('Nouvelle caisse') }}
                    </h4>

                    <p class="mt-1 text-sm text-gray-500">
                        {{ __('Définissez le solde initial avec précision.') }}
                    </p>
                </div>

                <button
                    type="button"
                    wire:click="cancelRegister"
                    class="text-sm font-medium text-gray-500 hover:text-gray-900"
                >
                    {{ __('Fermer') }}
                </button>
            </div>

            <form
                wire:submit="saveRegister"
                class="mt-5 grid gap-4 md:grid-cols-3"
            >
                <div>
                    <x-input-label
                        for="cash-code"
                        :value="__('Code *')"
                    />

                    <x-text-input
                        id="cash-code"
                        wire:model="newRegisterCode"
                        class="mt-1 block w-full"
                        placeholder="CAISSE-01"
                    />

                    <x-input-error
                        :messages="$errors->get('newRegisterCode')"
                        class="mt-2"
                    />
                </div>

                <div>
                    <x-input-label
                        for="cash-name"
                        :value="__('Nom *')"
                    />

                    <x-text-input
                        id="cash-name"
                        wire:model="newRegisterName"
                        class="mt-1 block w-full"
                        :placeholder="__('Caisse principale')"
                    />

                    <x-input-error
                        :messages="$errors->get('newRegisterName')"
                        class="mt-2"
                    />
                </div>

                <div>
                    <x-input-label
                        for="cash-initial-balance"
                        :value="__('Solde initial *')"
                    />

                    <x-text-input
                        id="cash-initial-balance"
                        wire:model="newRegisterInitialBalance"
                        class="mt-1 block w-full"
                        inputmode="decimal"
                        placeholder="0.00"
                    />

                    <x-input-error
                        :messages="$errors->get('newRegisterInitialBalance')"
                        class="mt-2"
                    />
                </div>

                <div class="flex justify-end md:col-span-3">
                    <button
                        type="submit"
                        class="inline-flex min-h-10 items-center justify-center bg-gray-900 px-4 text-sm font-semibold text-white transition hover:bg-gray-700"
                    >
                        {{ __('Créer la caisse') }}
                    </button>
                </div>
            </form>
        </section>
    @endif

    @if ($showCategoryForm)
        <section class="border border-gray-200 bg-white p-5 sm:p-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h4 class="font-semibold text-gray-900">
                        {{ __('Nouvelle catégorie de dépense') }}
                    </h4>

                    <p class="mt-1 text-sm text-gray-500">
                        {{ __('Cette catégorie sera disponible dans le formulaire de dépense.') }}
                    </p>
                </div>

                <button
                    type="button"
                    wire:click="cancelCategory"
                    class="text-sm font-medium text-gray-500 hover:text-gray-900"
                >
                    {{ __('Fermer') }}
                </button>
            </div>

            <form
                wire:submit="saveCategory"
                class="mt-5 grid gap-4 md:grid-cols-2"
            >
                <div>
                    <x-input-label
                        for="expense-category-name"
                        :value="__('Nom *')"
                    />

                    <x-text-input
                        id="expense-category-name"
                        wire:model="categoryName"
                        class="mt-1 block w-full"
                    />

                    <x-input-error
                        :messages="$errors->get('categoryName')"
                        class="mt-2"
                    />
                </div>

                <div>
                    <x-input-label
                        for="expense-category-description"
                        :value="__('Description')"
                    />

                    <x-text-input
                        id="expense-category-description"
                        wire:model="categoryDescription"
                        class="mt-1 block w-full"
                    />

                    <x-input-error
                        :messages="$errors->get('categoryDescription')"
                        class="mt-2"
                    />
                </div>

                <div class="flex justify-end gap-2 md:col-span-2">
                    <button
                        type="button"
                        wire:click="cancelCategory"
                        class="border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    >
                        {{ __('Annuler') }}
                    </button>

                    <button
                        type="submit"
                        class="bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700"
                    >
                        {{ __('Créer la catégorie') }}
                    </button>
                </div>
            </form>
        </section>
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($registers as $register)
            <article class="border border-gray-200 bg-white p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="font-semibold text-gray-900">
                            {{ $register->name }}
                        </p>

                        <p class="mt-1 text-xs font-medium uppercase tracking-wide text-gray-400">
                            {{ $register->code }}
                        </p>
                    </div>

                    @if ($register->is_active)
                        <span class="bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">
                            {{ __('Active') }}
                        </span>
                    @else
                        <span class="bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-500">
                            {{ __('Inactive') }}
                        </span>
                    @endif
                </div>

                <div class="mt-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        {{ __('Solde actuel') }}
                    </p>

                    <p class="mt-2 text-2xl font-semibold text-gray-900">
                        {{ number_format(
                            (float) $register->current_balance,
                            2,
                            ',',
                            ' '
                        ) }}

                        <span class="text-sm font-medium text-gray-500">
                            {{ __('DH') }}
                        </span>
                    </p>
                </div>

                <dl class="mt-5 grid grid-cols-3 gap-3 border-t border-gray-100 pt-4 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500">
                            {{ __('Initial') }}
                        </dt>

                        <dd class="mt-1 font-medium text-gray-900">
                            {{ number_format(
                                (float) $register->initial_balance,
                                2,
                                ',',
                                ' '
                            ) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs text-gray-500">
                            {{ __('Entrées') }}
                        </dt>

                        <dd class="mt-1 font-medium text-emerald-700">
                            {{ number_format(
                                (float) $register->entries_total,
                                2,
                                ',',
                                ' '
                            ) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs text-gray-500">
                            {{ __('Sorties') }}
                        </dt>

                        <dd class="mt-1 font-medium text-red-700">
                            {{ number_format(
                                (float) $register->exits_total,
                                2,
                                ',',
                                ' '
                            ) }}
                        </dd>
                    </div>
                </dl>

                @can('payments.create')
                    @if ($register->is_active)
                        <div class="mt-5 flex gap-2">
                            <button
                                type="button"
                                wire:click="prepareTransaction({{ $register->id }}, 'entry')"
                                class="flex-1 border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                            >
                                {{ __('Entrée') }}
                            </button>

                            <button
                                type="button"
                                wire:click="prepareTransaction({{ $register->id }}, 'exit')"
                                class="flex-1 bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-700"
                            >
                                {{ __('Sortie') }}
                            </button>
                        </div>
                    @endif
                @endcan
            </article>
        @empty
            <div class="border border-dashed border-gray-300 bg-white p-8 text-sm text-gray-500 md:col-span-2 xl:col-span-3">
                {{ __('Aucune caisse configurée.') }}
            </div>
        @endforelse
    </div>

    @if ($showTransactionForm)
        <section class="border border-gray-200 bg-white p-5 sm:p-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h4 class="font-semibold text-gray-900">
                        {{ $transactionType === CashTransaction::TYPE_ENTRY
                            ? __('Nouvelle entrée')
                            : __('Nouvelle sortie') }}
                    </h4>

                    <p class="mt-1 text-sm text-gray-500">
                        {{ __('Le mouvement sera ajouté définitivement à l’historique.') }}
                    </p>
                </div>

                <button
                    type="button"
                    wire:click="cancelTransaction"
                    class="text-sm font-medium text-gray-500 hover:text-gray-900"
                >
                    {{ __('Fermer') }}
                </button>
            </div>

            <form
                wire:submit="saveTransaction"
                class="mt-5 grid gap-4 md:grid-cols-2"
            >
                <div>
                    <x-input-label
                        for="cash-transaction-date"
                        :value="__('Date *')"
                    />

                    <x-text-input
                        id="cash-transaction-date"
                        type="date"
                        wire:model="transactionDate"
                        class="mt-1 block w-full"
                    />

                    <x-input-error
                        :messages="$errors->get('transactionDate')"
                        class="mt-2"
                    />
                </div>

                <div>
                    <x-input-label
                        for="cash-transaction-amount"
                        :value="__('Montant *')"
                    />

                    <x-text-input
                        id="cash-transaction-amount"
                        wire:model="amount"
                        class="mt-1 block w-full"
                        inputmode="decimal"
                        placeholder="0.00"
                    />

                    <x-input-error
                        :messages="$errors->get('amount')"
                        class="mt-2"
                    />
                </div>

                <div>
                    <x-input-label
                        for="cash-transaction-reference"
                        :value="__('Référence')"
                    />

                    <x-text-input
                        id="cash-transaction-reference"
                        wire:model="reference"
                        class="mt-1 block w-full"
                    />

                    <x-input-error
                        :messages="$errors->get('reference')"
                        class="mt-2"
                    />
                </div>

                <div>
                    <x-input-label
                        for="cash-transaction-description"
                        :value="__('Description')"
                    />

                    <x-text-input
                        id="cash-transaction-description"
                        wire:model="description"
                        class="mt-1 block w-full"
                    />

                    <x-input-error
                        :messages="$errors->get('description')"
                        class="mt-2"
                    />
                </div>

                <div class="flex justify-end md:col-span-2">
                    <button
                        type="submit"
                        class="inline-flex min-h-10 items-center justify-center bg-gray-900 px-4 text-sm font-semibold text-white transition hover:bg-gray-700"
                    >
                        {{ __('Enregistrer le mouvement') }}
                    </button>
                </div>
            </form>
        </section>
    @endif

    <section class="border border-gray-200 bg-white p-5 sm:p-6">
        <div>
            <h4 class="font-semibold text-gray-900">
                {{ __('Catégories de dépenses') }}
            </h4>

            <p class="mt-1 text-sm text-gray-500">
                {{ __('Catégories disponibles dans les dépenses.') }}
            </p>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($categories as $category)
                <article class="border border-gray-200 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-medium text-gray-900">
                                {{ $category->name }}
                            </p>

                            @if ($category->description)
                                <p class="mt-1 text-sm text-gray-500">
                                    {{ $category->description }}
                                </p>
                            @endif
                        </div>

                        <span
                            @class([
                                'px-2 py-1 text-xs font-semibold',
                                'bg-emerald-50 text-emerald-700' => $category->is_active,
                                'bg-gray-100 text-gray-500' => ! $category->is_active,
                            ])
                        >
                            {{ $category->is_active
                                ? __('Active')
                                : __('Inactive') }}
                        </span>
                    </div>

                    @can('payments.create')
                        <button
                            type="button"
                            wire:click="toggleCategory({{ $category->id }})"
                            class="mt-4 text-sm font-medium text-gray-600 underline hover:text-gray-900"
                        >
                            {{ $category->is_active
                                ? __('Désactiver')
                                : __('Activer') }}
                        </button>
                    @endcan
                </article>
            @empty
                <p class="text-sm text-gray-500">
                    {{ __('Aucune catégorie disponible.') }}
                </p>
            @endforelse
        </div>
    </section>

    <section class="overflow-hidden border border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-4">
            <h4 class="font-semibold text-gray-900">
                {{ __('Historique des mouvements') }}
            </h4>
        </div>

        <div class="divide-y divide-gray-100">
            @forelse ($transactions as $transaction)
                <article class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                @class([
                                    'px-2 py-1 text-xs font-semibold',
                                    'bg-emerald-50 text-emerald-700' => $transaction->type === CashTransaction::TYPE_ENTRY,
                                    'bg-red-50 text-red-700' => $transaction->type === CashTransaction::TYPE_EXIT,
                                ])
                            >
                                {{ $transaction->type === CashTransaction::TYPE_ENTRY
                                    ? __('Entrée')
                                    : __('Sortie') }}
                            </span>

                            <span class="text-sm font-semibold text-gray-900">
                                {{ $transaction->cashRegister->name }}
                            </span>
                        </div>

                        <div class="mt-2 text-sm text-gray-500">
                            {{ $transaction->transaction_date->format('d/m/Y') }}

                            @if ($transaction->expense)
                                · {{ $transaction->expense->category->name }}
                            @elseif ($transaction->description)
                                · {{ $transaction->description }}
                            @endif

                            @if ($transaction->reference)
                                · {{ $transaction->reference }}
                            @endif
                        </div>

                        @if ($transaction->creator)
                            <p class="mt-1 text-xs text-gray-400">
                                {{ __('Saisi par :name', [
                                    'name' => $transaction->creator->name,
                                ]) }}
                            </p>
                        @endif
                    </div>

                    <p
                        @class([
                            'text-lg font-semibold',
                            'text-emerald-700' => $transaction->type === CashTransaction::TYPE_ENTRY,
                            'text-red-700' => $transaction->type === CashTransaction::TYPE_EXIT,
                        ])
                    >
                        {{ $transaction->type === CashTransaction::TYPE_ENTRY ? '+' : '-' }}

                        {{ number_format(
                            (float) $transaction->amount,
                            2,
                            ',',
                            ' '
                        ) }}

                        {{ __('DH') }}
                    </p>
                </article>
            @empty
                <div class="p-8 text-center text-sm text-gray-500">
                    {{ __('Aucun mouvement de caisse.') }}
                </div>
            @endforelse
        </div>
    </section>

    @if ($transactions->hasPages())
        <div>
            {{ $transactions->links() }}
        </div>
    @endif
</section>