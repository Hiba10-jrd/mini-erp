<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Services\PaymentManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public ?int $invoiceId = null;

    public ?int $customerId = null;

    public ?int $paymentMethodId = null;

    public string $paymentDate = '';

    public string $amount = '';

    public string $reference = '';

    public string $notes = '';

    public array $allocations = [];
    public string $chequeNumber = '';

public string $bankName = '';

public string $chequeDueDate = '';

public string $transactionReference = '';
    public function mount(?int $invoiceId = null): void
    {
        Gate::authorize('payments.create');

        $this->paymentDate = today()->toDateString();

        if ($invoiceId === null) {
            return;
        }

        $invoice = Invoice::query()
            ->whereKey($invoiceId)
            ->where('status', Invoice::STATUS_ISSUED)
            ->firstOrFail();

        $service = app(PaymentManagementService::class);
        $remaining = $service->remainingAmount($invoice);

        abort_if(
            bccomp($remaining, '0.00', 2) <= 0,
            404
        );

        $this->invoiceId = $invoice->id;
        $this->customerId = $invoice->customer_id;
        $this->amount = $remaining;

        $this->allocations = [
            [
                'invoice_id' => $invoice->id,
                'number' => $invoice->number,
                'remaining' => $remaining,
                'amount' => $remaining,
            ],
        ];
    }
public function updatedPaymentMethodId(): void
{
    $this->reset([
        'chequeNumber',
        'bankName',
        'chequeDueDate',
        'transactionReference',
    ]);

    $this->resetValidation([
        'chequeNumber',
        'bankName',
        'chequeDueDate',
        'transactionReference',
    ]);
}
    public function updatedCustomerId(): void
    {
        if ($this->invoiceId !== null) {
            return;
        }

        $this->allocations = [];
        $this->amount = '';
    }

    public function addAllocation(
        int $invoiceId,
        PaymentManagementService $service
    ): void {
        Gate::authorize('payments.create');

        if ($this->customerId === null) {
            return;
        }

        $invoice = Invoice::query()
            ->whereKey($invoiceId)
            ->where('customer_id', $this->customerId)
            ->where('status', Invoice::STATUS_ISSUED)
            ->firstOrFail();

        foreach ($this->allocations as $allocation) {
            if ((int) $allocation['invoice_id'] === $invoiceId) {
                return;
            }
        }

        $remaining = $service->remainingAmount($invoice);

        if (bccomp($remaining, '0.00', 2) <= 0) {
            return;
        }

        $this->allocations[] = [
            'invoice_id' => $invoice->id,
            'number' => $invoice->number,
            'remaining' => $remaining,
            'amount' => $remaining,
        ];

        $this->synchronizeTotalAmount();
    }

    public function removeAllocation(int $index): void
    {
        Gate::authorize('payments.create');

        if ($this->invoiceId !== null) {
            return;
        }

        if (! array_key_exists($index, $this->allocations)) {
            return;
        }

        unset($this->allocations[$index]);

        $this->allocations = array_values($this->allocations);

        $this->synchronizeTotalAmount();
    }

    public function updatedAllocations(): void
    {
        $this->synchronizeTotalAmount();
    }

    public function save(
    PaymentManagementService $service
): void {
    Gate::authorize('payments.create');

    $paymentType = $this->selectedPaymentType();

    $rules = [
        'customerId' => [
            'required',
            'integer',
            'exists:customers,id',
        ],

        'paymentMethodId' => [
            'required',
            'integer',
            'exists:payment_methods,id',
        ],

        'paymentDate' => [
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
            'max:150',
        ],

        'notes' => [
            'nullable',
            'string',
            'max:2000',
        ],

        'allocations' => [
            'required',
            'array',
            'min:1',
        ],

        'allocations.*.invoice_id' => [
            'required',
            'integer',
            'exists:invoices,id',
        ],

        'allocations.*.amount' => [
            'required',
            'regex:/^\d+(?:\.\d{1,2})?$/',
        ],
    ];

    if ($paymentType === PaymentMethod::TYPE_CHEQUE) {
        $rules['chequeNumber'] = [
            'required',
            'string',
            'max:100',
        ];

        $rules['bankName'] = [
            'required',
            'string',
            'max:150',
        ];

        $rules['chequeDueDate'] = [
            'required',
            'date',
        ];
    }

    if ($paymentType === PaymentMethod::TYPE_BANK_TRANSFER) {
        $rules['transactionReference'] = [
            'required',
            'string',
            'max:150',
        ];

        $rules['bankName'] = [
            'nullable',
            'string',
            'max:150',
        ];
    }

    if ($paymentType === PaymentMethod::TYPE_CARD) {
        $rules['transactionReference'] = [
            'required',
            'string',
            'max:150',
        ];
    }

    $validated = $this->validate($rules);

    $details = match ($paymentType) {
        PaymentMethod::TYPE_CHEQUE => [
            'cheque_number' => $validated['chequeNumber'],
            'bank_name' => $validated['bankName'],
            'due_date' => $validated['chequeDueDate'],
        ],

        PaymentMethod::TYPE_BANK_TRANSFER => [
            'transaction_reference' => $validated['transactionReference'],
            'bank_name' => filled($validated['bankName'] ?? null)
                ? $validated['bankName']
                : null,
        ],

        PaymentMethod::TYPE_CARD => [
            'transaction_reference' => $validated['transactionReference'],
        ],

        default => null,
    };

    $service->create(
        [
            'customer_id' => $validated['customerId'],
            'payment_method_id' => $validated['paymentMethodId'],
            'payment_date' => $validated['paymentDate'],
            'amount' => $validated['amount'],

            'reference' => filled($validated['reference'])
                ? $validated['reference']
                : null,

            'details' => $details,

            'notes' => filled($validated['notes'])
                ? $validated['notes']
                : null,
        ],

        collect($validated['allocations'])
            ->map(
                fn (array $allocation): array => [
                    'invoice_id' => $allocation['invoice_id'],
                    'amount' => $allocation['amount'],
                ]
            )
            ->all()
    );

    session()->flash(
        'status',
        __('Le paiement a été enregistré avec succès.')
    );

    $this->redirect(
        route('sales.payments.index'),
        navigate: true
    );
}
    public function with(): array
    {
        Gate::authorize('payments.create');

        $service = app(PaymentManagementService::class);

        $availableInvoices = $this->customerId === null
            ? collect()
            : Invoice::query()
                ->where('customer_id', $this->customerId)
                ->where('status', Invoice::STATUS_ISSUED)
                ->orderBy('due_date')
                ->orderBy('invoice_date')
                ->get()
                ->map(function (Invoice $invoice) use ($service): array {
                    return [
                        'id' => $invoice->id,
                        'number' => $invoice->number,
                        'invoice_date' => $invoice->invoice_date,
                        'due_date' => $invoice->due_date,
                        'total_ttc' => $invoice->total_ttc,
                        'remaining' => $service->remainingAmount($invoice),
                    ];
                })
                ->filter(
                    fn (array $invoice): bool => bccomp(
                        $invoice['remaining'],
                        '0.00',
                        2
                    ) === 1
                )
                ->values();

        return [
            'customers' => Customer::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'trade_name',
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

'selectedPaymentType' => $this->selectedPaymentType(),

            'availableInvoices' => $availableInvoices,
        ];
    }
private function selectedPaymentType(): ?string
{
    if ($this->paymentMethodId === null) {
        return null;
    }

    return PaymentMethod::query()
        ->whereKey($this->paymentMethodId)
        ->where('is_active', true)
        ->value('payment_type');
}
    private function synchronizeTotalAmount(): void
    {
        $totalCents = 0;

        foreach ($this->allocations as $allocation) {
            $value = $allocation['amount'] ?? '0';

            if (! is_numeric($value)) {
                continue;
            }

            $totalCents += (int) round(
                ((float) $value) * 100
            );
        }

        $this->amount = number_format(
            $totalCents / 100,
            2,
            '.',
            ''
        );
    }
};
?>

<section class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">
                {{ __('Nouveau paiement') }}
            </h3>

            <p class="mt-1 text-sm text-gray-500">
                @if ($invoiceId)
                    {{ __('Enregistrez un règlement pour la facture sélectionnée.') }}
                @else
                    {{ __('Enregistrez un règlement et affectez-le à une ou plusieurs factures du même client.') }}
                @endif
            </p>
        </div>

        <a
            href="{{ route('sales.payments.index') }}"
            wire:navigate
            class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
        >
            {{ __('Retour aux paiements') }}
        </a>
    </div>

    <x-input-error
        :messages="$errors->get('payment')"
    />

    <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <x-input-label
                    for="payment-customer"
                    :value="__('Client')"
                />

                @if ($invoiceId)
                    @php
                        $selectedCustomer = $customers->firstWhere(
                            'id',
                            $customerId
                        );
                    @endphp

                    <div class="mt-1 border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                        {{ $selectedCustomer?->name ?? __('Client') }}

                        @if ($selectedCustomer?->trade_name)
                            · {{ $selectedCustomer->trade_name }}
                        @endif
                    </div>
                @else
                    <select
                        id="payment-customer"
                        wire:model.live="customerId"
                        class="mt-1 block w-full border-gray-300 shadow-sm"
                    >
                        <option value="">
                            {{ __('Sélectionner un client') }}
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
                @endif

                <x-input-error
                    :messages="$errors->get('customerId')"
                    class="mt-2"
                />
            </div>

            <div>
                <x-input-label
                    for="payment-method"
                    :value="__('Mode de paiement')"
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
@if ($selectedPaymentType === PaymentMethod::TYPE_CASH)
    <div class="md:col-span-2">
        <div class="border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">
            {{ __('Paiement en espèces : aucun renseignement bancaire supplémentaire n’est nécessaire.') }}
        </div>
    </div>
@endif

@if ($selectedPaymentType === PaymentMethod::TYPE_CHEQUE)
    <div>
        <x-input-label
            for="cheque-number"
            :value="__('Numéro du chèque')"
        />

        <x-text-input
            id="cheque-number"
            wire:model="chequeNumber"
            class="mt-1 block w-full"
        />

        <x-input-error
            :messages="$errors->get('chequeNumber')"
            class="mt-2"
        />
    </div>

    <div>
        <x-input-label
            for="cheque-bank"
            :value="__('Banque')"
        />

        <x-text-input
            id="cheque-bank"
            wire:model="bankName"
            class="mt-1 block w-full"
        />

        <x-input-error
            :messages="$errors->get('bankName')"
            class="mt-2"
        />
    </div>

    <div>
        <x-input-label
            for="cheque-due-date"
            :value="__('Date d’échéance du chèque')"
        />

        <x-text-input
            id="cheque-due-date"
            type="date"
            wire:model="chequeDueDate"
            class="mt-1 block w-full"
        />

        <x-input-error
            :messages="$errors->get('chequeDueDate')"
            class="mt-2"
        />
    </div>
@endif

@if ($selectedPaymentType === PaymentMethod::TYPE_BANK_TRANSFER)
    <div>
        <x-input-label
            for="transfer-reference"
            :value="__('Référence du virement')"
        />

        <x-text-input
            id="transfer-reference"
            wire:model="transactionReference"
            class="mt-1 block w-full"
        />

        <x-input-error
            :messages="$errors->get('transactionReference')"
            class="mt-2"
        />
    </div>

    <div>
        <x-input-label
            for="transfer-bank"
            :value="__('Banque')"
        />

        <x-text-input
            id="transfer-bank"
            wire:model="bankName"
            class="mt-1 block w-full"
        />

        <x-input-error
            :messages="$errors->get('bankName')"
            class="mt-2"
        />
    </div>
@endif

@if ($selectedPaymentType === PaymentMethod::TYPE_CARD)
    <div class="md:col-span-2">
        <x-input-label
            for="card-transaction-reference"
            :value="__('Référence / autorisation de transaction')"
        />

        <x-text-input
            id="card-transaction-reference"
            wire:model="transactionReference"
            class="mt-1 block w-full"
        />

        <x-input-error
            :messages="$errors->get('transactionReference')"
            class="mt-2"
        />

        <p class="mt-1 text-xs text-gray-500">
            {{ __('Ne saisissez jamais le numéro complet de carte, le CVV ou le code PIN.') }}
        </p>
    </div>
@endif

@if ($selectedPaymentType === PaymentMethod::TYPE_OTHER)
    <div class="md:col-span-2">
        <div class="border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
            {{ __('Autre mode de paiement : utilisez la référence et les notes pour conserver les informations nécessaires.') }}
        </div>
    </div>
@endif
            <div>
                <x-input-label
                    for="payment-date"
                    :value="__('Date du paiement')"
                />

                <x-text-input
                    id="payment-date"
                    type="date"
                    wire:model="paymentDate"
                    class="mt-1 block w-full"
                />

                <x-input-error
                    :messages="$errors->get('paymentDate')"
                    class="mt-2"
                />
            </div>

            <div>
                <x-input-label
                    for="payment-amount"
                    :value="__('Montant total')"
                />

                <x-text-input
                    id="payment-amount"
                    type="text"
                    wire:model="amount"
                    class="mt-1 block w-full bg-gray-50"
                    readonly
                />

                <p class="mt-1 text-xs text-gray-500">
                    {{ __('Calculé automatiquement à partir des affectations.') }}
                </p>

                <x-input-error
                    :messages="$errors->get('amount')"
                    class="mt-2"
                />
            </div>

            <div>
                <x-input-label
                    for="payment-reference"
                    :value="__('Référence')"
                />

                <x-text-input
                    id="payment-reference"
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
                    for="payment-notes"
                    :value="__('Notes')"
                />

                <textarea
                    id="payment-notes"
                    wire:model="notes"
                    rows="3"
                    class="mt-1 block w-full border-gray-300 shadow-sm"
                ></textarea>

                <x-input-error
                    :messages="$errors->get('notes')"
                    class="mt-2"
                />
            </div>
        </div>
    </section>

    @if ($customerId)
        <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
            <h4 class="font-semibold text-gray-900">
                {{ __('Factures à régler') }}
            </h4>

            @if ($invoiceId)
                @foreach ($allocations as $index => $allocation)
                    <div class="mt-4 grid gap-4 border border-gray-200 p-4 md:grid-cols-[1fr_220px] md:items-end">
                        <div>
                            <p class="font-semibold text-gray-900">
                                {{ $allocation['number'] }}
                            </p>

                            <p class="mt-1 text-sm text-gray-500">
                                {{ __('Reste à payer : :amount', [
                                    'amount' => number_format(
                                        (float) $allocation['remaining'],
                                        2,
                                        ',',
                                        ' '
                                    ),
                                ]) }}
                            </p>
                        </div>

                        <div>
                            <x-input-label
                                :for="'allocation-'.$index"
                                :value="__('Montant encaissé')"
                            />

                            <x-text-input
                                :id="'allocation-'.$index"
                                wire:model.live.debounce.300ms="allocations.{{ $index }}.amount"
                                class="mt-1 block w-full"
                            />

                            <x-input-error
                                :messages="$errors->get('allocations.'.$index.'.amount')"
                                class="mt-2"
                            />
                        </div>
                    </div>
                @endforeach
            @else
                <div class="mt-4 space-y-3">
                    @forelse ($availableInvoices as $invoice)
                        <div class="flex flex-col gap-3 border border-gray-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-semibold text-gray-900">
                                    {{ $invoice['number'] }}
                                </p>

                                <div class="mt-1 space-y-1 text-sm text-gray-500">
                                    <p>
                                        {{ __('Total TTC : :amount', [
                                            'amount' => number_format(
                                                (float) $invoice['total_ttc'],
                                                2,
                                                ',',
                                                ' '
                                            ),
                                        ]) }}
                                    </p>

                                    <p>
                                        {{ __('Reste à payer : :amount', [
                                            'amount' => number_format(
                                                (float) $invoice['remaining'],
                                                2,
                                                ',',
                                                ' '
                                            ),
                                        ]) }}
                                    </p>
                                </div>
                            </div>

                            <button
                                type="button"
                                wire:click="addAllocation({{ $invoice['id'] }})"
                                class="text-sm font-semibold text-indigo-700 hover:text-indigo-900"
                            >
                                {{ __('Affecter') }}
                            </button>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">
                            {{ __('Aucune facture émise avec un reste à payer.') }}
                        </p>
                    @endforelse
                </div>

                @if ($allocations !== [])
                    <div class="mt-6 space-y-3">
                        <h5 class="font-medium text-gray-900">
                            {{ __('Affectations sélectionnées') }}
                        </h5>

                        @foreach ($allocations as $index => $allocation)
                            <div class="grid gap-4 border border-gray-200 p-4 md:grid-cols-[1fr_220px_auto] md:items-end">
                                <div>
                                    <p class="font-semibold text-gray-900">
                                        {{ $allocation['number'] }}
                                    </p>

                                    <p class="mt-1 text-sm text-gray-500">
                                        {{ __('Reste à payer : :amount', [
                                            'amount' => number_format(
                                                (float) $allocation['remaining'],
                                                2,
                                                ',',
                                                ' '
                                            ),
                                        ]) }}
                                    </p>
                                </div>

                                <div>
                                    <x-input-label
                                        :for="'allocation-'.$index"
                                        :value="__('Montant encaissé')"
                                    />

                                    <x-text-input
                                        :id="'allocation-'.$index"
                                        wire:model.live.debounce.300ms="allocations.{{ $index }}.amount"
                                        class="mt-1 block w-full"
                                    />

                                    <x-input-error
                                        :messages="$errors->get('allocations.'.$index.'.amount')"
                                        class="mt-2"
                                    />
                                </div>

                                <button
                                    type="button"
                                    wire:click="removeAllocation({{ $index }})"
                                    class="pb-2 text-sm font-semibold text-rose-700 hover:text-rose-900"
                                >
                                    {{ __('Retirer') }}
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </section>
    @endif

    <div class="flex items-center justify-end gap-3">
        <a
            href="{{ route('sales.payments.index') }}"
            wire:navigate
            class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
            {{ __('Annuler') }}
        </a>

        <x-primary-button
            type="button"
            wire:click="save"
            wire:loading.attr="disabled"
            wire:target="save"
        >
            {{ __('Enregistrer le paiement') }}
        </x-primary-button>
    </div>
</section>