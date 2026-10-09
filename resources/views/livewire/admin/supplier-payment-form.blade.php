<?php

use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\SupplierPaymentManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public ?int $supplierInvoiceId = null;

    public ?int $supplierId = null;

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

    public function mount(?int $supplierInvoiceId = null): void
    {
        Gate::authorize('payments.create');

        $this->paymentDate = today()->toDateString();

        if ($supplierInvoiceId === null) {
            return;
        }

        $invoice = SupplierInvoice::query()
            ->whereKey($supplierInvoiceId)
            ->where('status', SupplierInvoice::STATUS_VALIDATED)
            ->firstOrFail();

        $service = app(SupplierPaymentManagementService::class);

        $remaining = $service->remainingAmount($invoice);

        abort_if(
            bccomp($remaining, '0.00', 2) <= 0,
            404
        );

        $this->supplierInvoiceId = $invoice->id;
        $this->supplierId = $invoice->supplier_id;
        $this->amount = $remaining;

        $this->allocations = [
            [
                'supplier_invoice_id' => $invoice->id,
                'number' => $invoice->number,
                'supplier_invoice_number' => $invoice->supplier_invoice_number,
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

    public function updatedSupplierId(): void
    {
        if ($this->supplierInvoiceId !== null) {
            return;
        }

        $this->allocations = [];
        $this->amount = '';
    }

    public function addAllocation(
        int $supplierInvoiceId,
        SupplierPaymentManagementService $service
    ): void {
        Gate::authorize('payments.create');

        if ($this->supplierId === null) {
            return;
        }

        $invoice = SupplierInvoice::query()
            ->whereKey($supplierInvoiceId)
            ->where('supplier_id', $this->supplierId)
            ->where('status', SupplierInvoice::STATUS_VALIDATED)
            ->firstOrFail();

        foreach ($this->allocations as $allocation) {
            if (
                (int) $allocation['supplier_invoice_id']
                === $supplierInvoiceId
            ) {
                return;
            }
        }

        $remaining = $service->remainingAmount($invoice);

        if (bccomp($remaining, '0.00', 2) <= 0) {
            return;
        }

        $this->allocations[] = [
            'supplier_invoice_id' => $invoice->id,
            'number' => $invoice->number,
            'supplier_invoice_number' => $invoice->supplier_invoice_number,
            'remaining' => $remaining,
            'amount' => $remaining,
        ];

        $this->synchronizeTotalAmount();
    }

    public function removeAllocation(int $index): void
    {
        Gate::authorize('payments.create');

        if ($this->supplierInvoiceId !== null) {
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
        SupplierPaymentManagementService $service
    ): void {
        Gate::authorize('payments.create');

        $paymentType = $this->selectedPaymentType();

        $rules = [
            'supplierId' => [
                'required',
                'integer',
                'exists:suppliers,id',
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

            'allocations.*.supplier_invoice_id' => [
                'required',
                'integer',
                'exists:supplier_invoices,id',
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

        $payment = $service->create(
            [
                'supplier_id' => $validated['supplierId'],
                'payment_method_id' => $validated['paymentMethodId'],
                'payment_date' => $validated['paymentDate'],
                'amount' => $validated['amount'],

                'reference' => filled($validated['reference'] ?? null)
                        ? $validated['reference']
                        : null,

                'details' => $details,

                'notes' => filled($validated['notes'] ?? null)
                        ? $validated['notes']
                        : null,
            ],

            collect($validated['allocations'])
                ->map(
                    fn (array $allocation): array => [
                        'supplier_invoice_id' => $allocation['supplier_invoice_id'],

                        'amount' => $allocation['amount'],
                    ]
                )
                ->all()
        );

        session()->flash(
            'status',
            __('Le paiement fournisseur a été enregistré avec succès.')
        );

        $this->redirect(
            route('purchases.payments.show', $payment),
            navigate: true
        );
    }

    public function with(): array
    {
        Gate::authorize('payments.create');

        $service = app(SupplierPaymentManagementService::class);

        $availableInvoices = $this->supplierId === null
            ? collect()
            : SupplierInvoice::query()
                ->where('supplier_id', $this->supplierId)
                ->where('status', SupplierInvoice::STATUS_VALIDATED)
                ->orderByRaw('due_date is null')
                ->orderBy('due_date')
                ->orderBy('invoice_date')
                ->get()
                ->map(
                    function (
                        SupplierInvoice $invoice
                    ) use ($service): array {
                        return [
                            'id' => $invoice->id,
                            'number' => $invoice->number,
                            'supplier_invoice_number' => $invoice->supplier_invoice_number,
                            'invoice_date' => $invoice->invoice_date,
                            'due_date' => $invoice->due_date,
                            'total_ttc' => $invoice->total_ttc,
                            'remaining' => $service->remainingAmount($invoice),
                            'state' => $service->paymentState($invoice),
                        ];
                    }
                )
                ->filter(
                    fn (array $invoice): bool => bccomp(
                        $invoice['remaining'],
                        '0.00',
                        2
                    ) === 1
                )
                ->values();

        $supplierIdsWithPayableInvoices =
            SupplierInvoice::query()
                ->where(
                    'status',
                    SupplierInvoice::STATUS_VALIDATED
                )
                ->distinct()
                ->pluck('supplier_id');

        return [
            'suppliers' => Supplier::query()
                ->whereIn('id', $supplierIdsWithPayableInvoices)
                ->orderBy('name')
                ->get([
                    'id',
                    'code',
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
            $cents = $this->moneyToCents(
                $allocation['amount'] ?? ''
            );

            if ($cents === null) {
                continue;
            }

            $totalCents += $cents;
        }

        $this->amount = $this->centsToMoney($totalCents);
    }

    private function moneyToCents(mixed $value): ?int
    {
        $value = trim((string) $value);

        if (! preg_match(
            '/^\d+(?:\.\d{0,2})?$/',
            $value
        )) {
            return null;
        }

        [$whole, $decimal] = array_pad(
            explode('.', $value, 2),
            2,
            ''
        );

        $decimal = str_pad($decimal, 2, '0');

        return ((int) $whole * 100)
            + (int) $decimal;
    }

    private function centsToMoney(int $cents): string
    {
        return sprintf(
            '%d.%02d',
            intdiv($cents, 100),
            $cents % 100
        );
    }
};
?>

<section class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">
                {{ __('Nouveau paiement fournisseur') }}
            </h3>

            <p class="mt-1 text-sm text-gray-500">
                @if ($supplierInvoiceId)
                    {{ __('Enregistrez un règlement pour la facture fournisseur sélectionnée.') }}
                @else
                    {{ __('Enregistrez un règlement et affectez-le à une ou plusieurs factures validées du même fournisseur.') }}
                @endif
            </p>
        </div>

        <a
            href="{{ route('purchases.payments.index') }}"
            wire:navigate
            class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
        >
            {{ __('Retour aux paiements fournisseurs') }}
        </a>
    </div>

    <x-input-error
        :messages="$errors->get('allocations')"
    />

    <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <x-input-label
                    for="supplier-payment-supplier"
                    :value="__('Fournisseur')"
                />

                @if ($supplierInvoiceId)
                    @php
                        $selectedSupplier =
                            $suppliers->firstWhere(
                                'id',
                                $supplierId
                            );
                    @endphp

                    <div class="mt-1 border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                        {{ $selectedSupplier?->name ?? __('Fournisseur') }}

                        @if ($selectedSupplier?->trade_name)
                            · {{ $selectedSupplier->trade_name }}
                        @endif
                    </div>
                @else
                    <select
                        id="supplier-payment-supplier"
                        wire:model.live="supplierId"
                        class="mt-1 block w-full border-gray-300 shadow-sm"
                    >
                        <option value="">
                            {{ __('Sélectionner un fournisseur') }}
                        </option>

                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">
                                {{ $supplier->code }}
                                ·
                                {{ $supplier->name }}

                                @if ($supplier->trade_name)
                                    · {{ $supplier->trade_name }}
                                @endif
                            </option>
                        @endforeach
                    </select>
                @endif

                <x-input-error
                    :messages="$errors->get('supplierId')"
                    class="mt-2"
                />
            </div>

            <div>
                <x-input-label
                    for="supplier-payment-method"
                    :value="__('Mode de paiement')"
                />

                <select
                    id="supplier-payment-method"
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
                        {{ __('Paiement en espèces : aucune information bancaire supplémentaire n’est nécessaire.') }}
                    </div>
                </div>
            @endif

            @if ($selectedPaymentType === PaymentMethod::TYPE_CHEQUE)
                <div>
                    <x-input-label
                        for="supplier-cheque-number"
                        :value="__('Numéro du chèque')"
                    />

                    <x-text-input
                        id="supplier-cheque-number"
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
                        for="supplier-cheque-bank"
                        :value="__('Banque')"
                    />

                    <x-text-input
                        id="supplier-cheque-bank"
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
                        for="supplier-cheque-due-date"
                        :value="__('Date d’échéance du chèque')"
                    />

                    <x-text-input
                        id="supplier-cheque-due-date"
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
                        for="supplier-transfer-reference"
                        :value="__('Référence du virement')"
                    />

                    <x-text-input
                        id="supplier-transfer-reference"
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
                        for="supplier-transfer-bank"
                        :value="__('Banque')"
                    />

                    <x-text-input
                        id="supplier-transfer-bank"
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
                        for="supplier-card-reference"
                        :value="__('Référence / autorisation de transaction')"
                    />

                    <x-text-input
                        id="supplier-card-reference"
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
                        {{ __('Pour un autre mode de paiement, utilisez la référence et les notes pour conserver les informations nécessaires.') }}
                    </div>
                </div>
            @endif

            <div>
                <x-input-label
                    for="supplier-payment-date"
                    :value="__('Date du paiement')"
                />

                <x-text-input
                    id="supplier-payment-date"
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
                    for="supplier-payment-amount"
                    :value="__('Montant total')"
                />

                <x-text-input
                    id="supplier-payment-amount"
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
                    for="supplier-payment-reference"
                    :value="__('Référence du paiement')"
                />

                <x-text-input
                    id="supplier-payment-reference"
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
                    for="supplier-payment-notes"
                    :value="__('Notes')"
                />

                <textarea
                    id="supplier-payment-notes"
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

    @if ($supplierId)
        <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
            <div>
                <h4 class="font-semibold text-gray-900">
                    {{ __('Factures fournisseurs à régler') }}
                </h4>

                <p class="mt-1 text-sm text-gray-500">
                    {{ __('Seules les factures validées avec un reste à payer sont proposées.') }}
                </p>
            </div>

            @if (! $supplierInvoiceId)
                <div class="mt-4 space-y-3">
                    @forelse ($availableInvoices as $invoice)
                        <div class="flex flex-col gap-3 border border-gray-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-semibold text-gray-900">
                                    {{ $invoice['number'] }}
                                </p>

                                <p class="text-xs text-gray-500">
                                    {{ __('Réf. fournisseur : :reference', [
                                        'reference' => $invoice['supplier_invoice_number'],
                                    ]) }}
                                </p>

                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-500">
                                    <span>
                                        {{ __('Total TTC : :amount', [
                                            'amount' => str_replace(
                                                '.',
                                                ',',
                                                $invoice['total_ttc']
                                            ),
                                        ]) }}
                                    </span>

                                    <span class="font-medium text-amber-700">
                                        {{ __('Reste à payer : :amount', [
                                            'amount' => str_replace(
                                                '.',
                                                ',',
                                                $invoice['remaining']
                                            ),
                                        ]) }}
                                    </span>

                                    @if ($invoice['due_date'])
                                        <span>
                                            {{ __('Échéance : :date', [
                                                'date' => $invoice['due_date']->format('d/m/Y'),
                                            ]) }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            @if (
                                ! collect($allocations)
                                    ->pluck('supplier_invoice_id')
                                    ->contains($invoice['id'])
                            )
                                <button
                                    type="button"
                                    wire:click="addAllocation({{ $invoice['id'] }})"
                                    class="text-sm font-semibold text-indigo-700 hover:text-indigo-900"
                                >
                                    {{ __('Affecter') }}
                                </button>
                            @else
                                <span class="text-sm font-medium text-emerald-700">
                                    {{ __('Sélectionnée') }}
                                </span>
                            @endif
                        </div>
                    @empty
                        <div class="mt-4 border border-gray-200 bg-gray-50 p-5 text-sm text-gray-500">
                            {{ __('Aucune facture fournisseur validée avec un reste à payer.') }}
                        </div>
                    @endforelse
                </div>
            @endif

            @if ($allocations !== [])
                <div class="mt-6">
                    <h5 class="font-medium text-gray-900">
                        {{ __('Affectations du paiement') }}
                    </h5>

                    <div class="mt-3 space-y-3">
                        @foreach ($allocations as $index => $allocation)
                            <div class="grid gap-4 border border-gray-200 p-4 md:grid-cols-[1fr_220px_auto] md:items-end">
                                <div>
                                    <p class="font-semibold text-gray-900">
                                        {{ $allocation['number'] }}
                                    </p>

                                    <p class="text-xs text-gray-500">
                                        {{ $allocation['supplier_invoice_number'] }}
                                    </p>

                                    <p class="mt-1 text-sm text-gray-500">
                                        {{ __('Reste à payer : :amount', [
                                            'amount' => str_replace(
                                                '.',
                                                ',',
                                                $allocation['remaining']
                                            ),
                                        ]) }}
                                    </p>
                                </div>

                                <div>
                                    <x-input-label
                                        :for="'supplier-allocation-'.$index"
                                        :value="__('Montant payé')"
                                    />

                                    <x-text-input
                                        :id="'supplier-allocation-'.$index"
                                        wire:model.live.debounce.300ms="allocations.{{ $index }}.amount"
                                        class="mt-1 block w-full"
                                    />

                                    <x-input-error
                                        :messages="$errors->get('allocations.'.$index.'.amount')"
                                        class="mt-2"
                                    />
                                </div>

                                @if (! $supplierInvoiceId)
                                    <button
                                        type="button"
                                        wire:click="removeAllocation({{ $index }})"
                                        class="pb-2 text-sm font-semibold text-rose-700 hover:text-rose-900"
                                    >
                                        {{ __('Retirer') }}
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>
    @endif

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
        <a
            href="{{ route('purchases.payments.index') }}"
            wire:navigate
            class="inline-flex min-h-10 items-center justify-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
            {{ __('Annuler') }}
        </a>

        <x-primary-button
            type="button"
            wire:click="save"
            wire:loading.attr="disabled"
            wire:target="save"
        >
            <span wire:loading.remove wire:target="save">
                {{ __('Enregistrer le paiement') }}
            </span>

            <span wire:loading wire:target="save">
                {{ __('Enregistrement...') }}
            </span>
        </x-primary-button>
    </div>
</section>