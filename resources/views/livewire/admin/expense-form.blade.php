<?php

use App\Models\CashRegister;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\CashManagementService;
use App\Services\ExpenseManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\WithFileUploads;

new class extends \Livewire\Volt\Component
{
    use WithFileUploads;

    public string $expenseCategoryId = '';

    public string $paymentMethodId = '';

    public string $cashRegisterId = '';

    public string $expenseDate = '';

    public string $amount = '';

    public string $taxAmount = '0.00';

    public string $reference = '';

    public string $description = '';

    public $receipt = null;

    public function mount(): void
    {
        Gate::authorize('payments.create');

        $this->expenseDate = today()->toDateString();
    }

    public function updatedPaymentMethodId(): void
    {
        if (! $this->isCashPayment()) {
            $this->cashRegisterId = '';
        }

        $this->resetValidation('cashRegisterId');
    }

    public function save(
        ExpenseManagementService $service
    ): void {
        Gate::authorize('payments.create');

        $validated = $this->validate([
            'expenseCategoryId' => [
                'required',
                'integer',
                Rule::exists('expense_categories', 'id'),
            ],
            'paymentMethodId' => [
                'required',
                'integer',
                Rule::exists('payment_methods', 'id'),
            ],
            'cashRegisterId' => [
                Rule::requiredIf(
                    fn (): bool => $this->isCashPayment()
                ),
                'nullable',
                'integer',
                Rule::exists('cash_registers', 'id'),
            ],
            'expenseDate' => [
                'required',
                'date',
            ],
            'amount' => [
                'required',
                'regex:/^\d+(?:\.\d{1,2})?$/',
            ],
            'taxAmount' => [
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
            'receipt' => [
                'nullable',
                'file',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
                'max:5120',
            ],
        ], [
            'expenseCategoryId.required' => __('La catégorie est obligatoire.'),
            'expenseCategoryId.exists' => __('La catégorie sélectionnée est invalide.'),
            'paymentMethodId.required' => __('Le mode de paiement est obligatoire.'),
            'paymentMethodId.exists' => __('Le mode de paiement sélectionné est invalide.'),
            'cashRegisterId.required' => __('La caisse est obligatoire pour un paiement en espèces.'),
            'cashRegisterId.exists' => __('La caisse sélectionnée est invalide.'),
            'expenseDate.required' => __('La date est obligatoire.'),
            'expenseDate.date' => __('La date est invalide.'),
            'amount.required' => __('Le montant est obligatoire.'),
            'amount.regex' => __('Le montant doit contenir au maximum deux décimales.'),
            'taxAmount.required' => __('La TVA est obligatoire.'),
            'taxAmount.regex' => __('La TVA doit contenir au maximum deux décimales.'),
            'reference.max' => __('La référence ne peut pas dépasser 120 caractères.'),
            'receipt.mimes' => __('Le justificatif doit être un PDF, JPG, JPEG ou PNG.'),
            'receipt.max' => __('Le justificatif ne peut pas dépasser 5 Mo.'),
        ]);

        $attachment = null;
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($service, $validated, &$attachment): void {
                $expense = $service->create([
                    'expense_category_id' => (int) $validated[
                        'expenseCategoryId'
                    ],
                    'payment_method_id' => (int) $validated[
                        'paymentMethodId'
                    ],
                    'cash_register_id' => $this->isCashPayment()
                        ? (int) $validated['cashRegisterId']
                        : null,
                    'expense_date' => $validated['expenseDate'],
                    'amount' => $validated['amount'],
                    'tax_amount' => $validated['taxAmount'],
                    'reference' => $validated['reference'] ?? null,
                    'description' => $validated['description'] ?? null,

                ]);

                if ($this->receipt !== null) {
                    $attachment = app(\App\Services\AttachmentManagementService::class)->upload($expense, $this->receipt);
                }
            });
        } catch (\Throwable $exception) {
            if ($attachment !== null) {
                Storage::disk('attachments')->delete($attachment->path);
            }
            throw $exception;
        }

        session()->flash(
            'status',
            __('La dépense a été enregistrée.')
        );

        $this->redirect(
            route('finance.expenses.index'),
            navigate: true
        );
    }

    public function isCashPayment(): bool
    {
        if ($this->paymentMethodId === '') {
            return false;
        }

        return PaymentMethod::query()
            ->whereKey((int) $this->paymentMethodId)
            ->where(
                'payment_type',
                PaymentMethod::TYPE_CASH
            )
            ->exists();
    }

    public function selectedCashBalance(): ?string
    {
        if ($this->cashRegisterId === '') {
            return null;
        }

        $register = CashRegister::query()
            ->find((int) $this->cashRegisterId);

        if (! $register) {
            return null;
        }

        return app(
            CashManagementService::class
        )->currentBalance($register);
    }

    public function with(): array
    {
        Gate::authorize('payments.create');

        return [
            'categories' => ExpenseCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                ]),

            'paymentMethods' => PaymentMethod::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'payment_type',
                ]),

            'cashRegisters' => CashRegister::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get([
                    'id',
                    'code',
                    'name',
                    'initial_balance',
                ]),

            'cashPayment' => $this->isCashPayment(),

            'selectedCashBalance' => $this->selectedCashBalance(),
        ];
    }
};
?>

<section class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">
                {{ __('Nouvelle dépense') }}
            </h3>

            <p class="mt-1 text-sm text-gray-500">
                {{ __('Enregistrez une charge et son mode de règlement.') }}
            </p>
        </div>

        <a
            href="{{ route('finance.expenses.index') }}"
            wire:navigate
            class="text-sm font-medium text-gray-600 hover:text-gray-900"
        >
            {{ __('Retour aux dépenses') }}
        </a>
    </div>

    <form
        wire:submit="save"
        class="space-y-6"
    >
        <section class="border border-gray-200 bg-white p-5 sm:p-6">
            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <x-input-label
                        for="expense-category"
                        :value="__('Catégorie *')"
                    />

                    <select
                        id="expense-category"
                        wire:model="expenseCategoryId"
                        class="mt-1 block w-full border-gray-300 shadow-sm"
                    >
                        <option value="">
                            {{ __('Sélectionner une catégorie') }}
                        </option>

                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>

                    <x-input-error
                        :messages="$errors->get('expenseCategoryId')"
                        class="mt-2"
                    />
                </div>

                <div>
                    <x-input-label
                        for="expense-date"
                        :value="__('Date *')"
                    />

                    <x-text-input
                        id="expense-date"
                        type="date"
                        wire:model="expenseDate"
                        class="mt-1 block w-full"
                    />

                    <x-input-error
                        :messages="$errors->get('expenseDate')"
                        class="mt-2"
                    />
                </div>

                <div>
                    <x-input-label
                        for="payment-method"
                        :value="__('Mode de paiement *')"
                    />

                    <select
                        id="payment-method"
                        wire:model.live="paymentMethodId"
                        class="mt-1 block w-full border-gray-300 shadow-sm"
                    >
                        <option value="">
                            {{ __('Sélectionner un mode') }}
                        </option>

                        @foreach ($paymentMethods as $method)
                            <option value="{{ $method->id }}">
                                {{ $method->name }}
                            </option>
                        @endforeach
                    </select>

                    <x-input-error
                        :messages="$errors->get('paymentMethodId')"
                        class="mt-2"
                    />
                </div>

                @if ($cashPayment)
                    <div>
                        <x-input-label
                            for="cash-register"
                            :value="__('Caisse *')"
                        />

                        <select
                            id="cash-register"
                            wire:model.live="cashRegisterId"
                            class="mt-1 block w-full border-gray-300 shadow-sm"
                        >
                            <option value="">
                                {{ __('Sélectionner une caisse') }}
                            </option>

                            @foreach ($cashRegisters as $register)
                                <option value="{{ $register->id }}">
                                    {{ $register->code }}
                                    ·
                                    {{ $register->name }}
                                </option>
                            @endforeach
                        </select>

                        <x-input-error
                            :messages="$errors->get('cashRegisterId')"
                            class="mt-2"
                        />

                        @if ($selectedCashBalance !== null)
                            <p class="mt-2 text-sm text-gray-500">
                                {{ __('Solde disponible :') }}

                                <span class="font-semibold text-gray-900">
                                    {{ number_format(
                                        (float) $selectedCashBalance,
                                        2,
                                        ',',
                                        ' '
                                    ) }}

                                    {{ __('DH') }}
                                </span>
                            </p>
                        @endif
                    </div>
                @endif

                <div>
                    <x-input-label
                        for="expense-amount"
                        :value="__('Montant TTC *')"
                    />

                    <div class="relative mt-1">
                        <x-text-input
                            id="expense-amount"
                            type="text"
                            inputmode="decimal"
                            wire:model="amount"
                            class="block w-full pr-14"
                            placeholder="0.00"
                        />

                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-500">
                            {{ __('DH') }}
                        </span>
                    </div>

                    <x-input-error
                        :messages="$errors->get('amount')"
                        class="mt-2"
                    />
                </div>

                <div>
                    <x-input-label
                        for="expense-tax"
                        :value="__('TVA comprise')"
                    />

                    <div class="relative mt-1">
                        <x-text-input
                            id="expense-tax"
                            type="text"
                            inputmode="decimal"
                            wire:model="taxAmount"
                            class="block w-full pr-14"
                            placeholder="0.00"
                        />

                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-sm text-gray-500">
                            {{ __('DH') }}
                        </span>
                    </div>

                    <x-input-error
                        :messages="$errors->get('taxAmount')"
                        class="mt-2"
                    />
                </div>
            </div>
        </section>

        <section class="border border-gray-200 bg-white p-5 sm:p-6">
            <h4 class="font-semibold text-gray-900">
                {{ __('Informations complémentaires') }}
            </h4>

            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <div>
                    <x-input-label
                        for="expense-reference"
                        :value="__('Référence')"
                    />

                    <x-text-input
                        id="expense-reference"
                        wire:model="reference"
                        class="mt-1 block w-full"
                        placeholder="Ex. FACT-2026-001"
                    />

                    <x-input-error
                        :messages="$errors->get('reference')"
                        class="mt-2"
                    />
                </div>

                <div>
                    <x-input-label
                        for="expense-receipt"
                        :value="__('Justificatif')"
                    />

                    <input
                        id="expense-receipt"
                        type="file"
                        wire:model="receipt"
                        accept=".pdf,.jpg,.jpeg,.png"
                        class="mt-1 block w-full text-sm text-gray-600 file:mr-4 file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-gray-700 hover:file:bg-gray-200"
                    >

                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('PDF, JPG ou PNG · 5 Mo maximum') }}
                    </p>

                    <p
                        wire:loading
                        wire:target="receipt"
                        class="mt-2 text-xs text-gray-500"
                    >
                        {{ __('Téléversement en cours...') }}
                    </p>

                    <x-input-error
                        :messages="$errors->get('receipt')"
                        class="mt-2"
                    />
                </div>

                <div class="md:col-span-2">
                    <x-input-label
                        for="expense-description"
                        :value="__('Description')"
                    />

                    <textarea
                        id="expense-description"
                        wire:model="description"
                        rows="4"
                        class="mt-1 block w-full border-gray-300 shadow-sm"
                        :placeholder="__('Objet de la dépense...')"
                    ></textarea>

                    <x-input-error
                        :messages="$errors->get('description')"
                        class="mt-2"
                    />
                </div>
            </div>
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <a
                href="{{ route('finance.expenses.index') }}"
                wire:navigate
                class="inline-flex min-h-11 items-center justify-center border border-gray-300 bg-white px-5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
            >
                {{ __('Annuler') }}
            </a>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="save"
                class="inline-flex min-h-11 items-center justify-center bg-gray-900 px-5 text-sm font-semibold text-white transition hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span
                    wire:loading.remove
                    wire:target="save"
                >
                    {{ __('Enregistrer la dépense') }}
                </span>

                <span
                    wire:loading
                    wire:target="save"
                >
                    {{ __('Enregistrement...') }}
                </span>
            </button>
        </div>
    </form>
</section>