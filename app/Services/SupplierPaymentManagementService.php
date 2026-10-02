<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SupplierPaymentManagementService
{
    /**
     * @param  array{
     *     supplier_id:int,
     *     payment_method_id:int,
     *     payment_date:string,
     *     amount:string|int,
     *     reference?:?string,
     *     details?:?array,
     *     notes?:?string
     * }  $attributes
     * @param  array<int, array{supplier_invoice_id:int, amount:string|int}>  $allocations
     */
    public function create(
        array $attributes,
        array $allocations
    ): SupplierPayment {
        Gate::authorize('payments.create');

        return DB::transaction(
            function () use (
                $attributes,
                $allocations
            ): SupplierPayment {
                /*
                 * Verrou fournisseur en premier.
                 *
                 * Cela sérialise les paiements concurrents
                 * d'un même fournisseur avant de verrouiller
                 * les moyens de paiement et les factures.
                 */
                $supplier = Supplier::query()
                    ->lockForUpdate()
                    ->findOrFail(
                        (int) $attributes['supplier_id']
                    );

                $paymentMethod = PaymentMethod::query()
                    ->lockForUpdate()
                    ->findOrFail(
                        (int) $attributes[
                            'payment_method_id'
                        ]
                    );

                if (! $paymentMethod->is_active) {
                    throw ValidationException::withMessages([
                        'payment_method_id' => __(
                            'Le mode de paiement sélectionné est inactif.'
                        ),
                    ]);
                }

                $paymentCents = $this->toCents(
                    $attributes['amount'],
                    'amount'
                );

                if ($paymentCents <= 0) {
                    throw ValidationException::withMessages([
                        'amount' => __(
                            'Le montant du paiement doit être supérieur à zéro.'
                        ),
                    ]);
                }

                if ($allocations === []) {
                    throw ValidationException::withMessages([
                        'allocations' => __(
                            'Au moins une facture fournisseur doit être affectée au paiement.'
                        ),
                    ]);
                }

                /*
                 * Validation minimale de la structure avant
                 * d'utiliser pluck() et les index du tableau.
                 */
                foreach (
                    $allocations as $index => $allocation
                ) {
                    if (
                        ! is_array($allocation)
                        || ! array_key_exists(
                            'supplier_invoice_id',
                            $allocation
                        )
                    ) {
                        throw ValidationException::withMessages([
                            "allocations.$index.supplier_invoice_id" => __(
                                'La facture fournisseur est obligatoire.'
                            ),
                        ]);
                    }

                    if (
                        ! array_key_exists(
                            'amount',
                            $allocation
                        )
                    ) {
                        throw ValidationException::withMessages([
                            "allocations.$index.amount" => __(
                                'Le montant affecté est obligatoire.'
                            ),
                        ]);
                    }
                }

                $invoiceIds = collect($allocations)
                    ->pluck('supplier_invoice_id')
                    ->map(
                        fn (mixed $id): int => (int) $id
                    )
                    ->values();

                if (
                    $invoiceIds->unique()->count()
                    !== $invoiceIds->count()
                ) {
                    throw ValidationException::withMessages([
                        'allocations' => __(
                            'Une facture fournisseur ne peut apparaître qu’une seule fois dans le même paiement.'
                        ),
                    ]);
                }

                /*
                 * Toujours verrouiller les factures
                 * dans le même ordre afin de limiter
                 * les risques de deadlock.
                 */
                $invoiceIds = $invoiceIds
                    ->unique()
                    ->sort()
                    ->values();

                $invoices = SupplierInvoice::query()
                    ->whereIn('id', $invoiceIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if (
                    $invoices->count()
                    !== $invoiceIds->count()
                ) {
                    throw ValidationException::withMessages([
                        'allocations' => __(
                            'Une ou plusieurs factures fournisseurs sont introuvables.'
                        ),
                    ]);
                }

                $preparedAllocations = [];
                $allocatedCents = 0;

                foreach (
                    $allocations as $index => $allocation
                ) {
                    $invoiceId = (int) $allocation[
                        'supplier_invoice_id'
                    ];

                    /** @var SupplierInvoice $invoice */
                    $invoice = $invoices->get(
                        $invoiceId
                    );

                    if (
                        $invoice->status
                        !== SupplierInvoice::STATUS_VALIDATED
                    ) {
                        throw ValidationException::withMessages([
                            "allocations.$index.supplier_invoice_id" => __(
                                'Seule une facture fournisseur validée peut recevoir un paiement.'
                            ),
                        ]);
                    }

                    if (
                        (int) $invoice->supplier_id
                        !== $supplier->id
                    ) {
                        throw ValidationException::withMessages([
                            "allocations.$index.supplier_invoice_id" => __(
                                'La facture sélectionnée appartient à un autre fournisseur.'
                            ),
                        ]);
                    }

                    $allocationCents = $this->toCents(
                        $allocation['amount'],
                        "allocations.$index.amount"
                    );

                    if ($allocationCents <= 0) {
                        throw ValidationException::withMessages([
                            "allocations.$index.amount" => __(
                                'Le montant affecté doit être supérieur à zéro.'
                            ),
                        ]);
                    }

                    /*
                     * La facture est verrouillée.
                     * Le reste à payer est donc recalculé
                     * sur un état stable dans la transaction.
                     */
                    $remainingCents =
                        $this->remainingAmountCents(
                            $invoice
                        );

                    if (
                        $allocationCents
                        > $remainingCents
                    ) {
                        throw ValidationException::withMessages([
                            "allocations.$index.amount" => __(
                                'Le montant affecté dépasse le reste à payer de la facture fournisseur.'
                            ),
                        ]);
                    }

                    $allocatedCents +=
                        $allocationCents;

                    if (
                        $allocatedCents
                        > $paymentCents
                    ) {
                        throw ValidationException::withMessages([
                            'allocations' => __(
                                'Le total des affectations dépasse le montant du paiement.'
                            ),
                        ]);
                    }

                    $preparedAllocations[] = [
                        'supplier_invoice_id' => $invoice->id,

                        'amount' => $this->fromCents(
                            $allocationCents
                        ),
                    ];
                }

                if (
                    $allocatedCents
                    !== $paymentCents
                ) {
                    throw ValidationException::withMessages([
                        'allocations' => __(
                            'Le total des affectations doit correspondre exactement au montant du paiement.'
                        ),
                    ]);
                }

                $payment = SupplierPayment::query()
                    ->create([
                        'supplier_id' => $supplier->id,

                        'payment_method_id' => $paymentMethod->id,

                        'payment_date' => $attributes[
                                'payment_date'
                            ],

                        'amount' => $this->fromCents(
                            $paymentCents
                        ),

                        'reference' => $this->nullableString(
                            $attributes[
                                'reference'
                            ] ?? null
                        ),

                        'details' => $attributes[
                                'details'
                            ] ?? null,

                        'notes' => $this->nullableString(
                            $attributes[
                                'notes'
                            ] ?? null
                        ),

                        'created_by' => Auth::id(),
                    ]);

                $payment
                    ->allocations()
                    ->createMany(
                        $preparedAllocations
                    );

                return $payment->fresh([
                    'supplier',
                    'paymentMethod',
                    'creator',
                    'allocations.supplierInvoice',
                ]);
            },
            3
        );
    }

    public function paidAmount(
        SupplierInvoice $invoice
    ): string {
        return $this->fromCents(
            $this->paidAmountCents($invoice)
        );
    }

    public function remainingAmount(
        SupplierInvoice $invoice
    ): string {
        return $this->fromCents(
            $this->remainingAmountCents(
                $invoice
            )
        );
    }

    public function paymentState(
        SupplierInvoice $invoice
    ): string {
        if (
            $invoice->status
            !== SupplierInvoice::STATUS_VALIDATED
        ) {
            return 'not_applicable';
        }

        $totalCents = $this->toCents(
            (string) $invoice->total_ttc
        );

        $paidCents =
            $this->paidAmountCents($invoice);

        $remainingCents = max(
            0,
            $totalCents - $paidCents
        );

        if ($remainingCents === 0) {
            return 'paid';
        }

        if (
            $invoice->due_date !== null
            && $invoice->due_date->lt(today())
        ) {
            return 'overdue';
        }

        if ($paidCents > 0) {
            return 'partially_paid';
        }

        return 'unpaid';
    }

    private function paidAmountCents(
        SupplierInvoice $invoice
    ): int {
        return $invoice
            ->paymentAllocations()
            ->pluck('amount')
            ->reduce(
                fn (
                    int $total,
                    mixed $amount
                ): int => $total
                    + $this->toCents(
                        (string) $amount
                    ),
                0
            );
    }

    private function remainingAmountCents(
        SupplierInvoice $invoice
    ): int {
        return max(
            0,
            $this->toCents(
                (string) $invoice->total_ttc
            )
            - $this->paidAmountCents(
                $invoice
            )
        );
    }

    private function toCents(
        string|int $value,
        string $field = 'amount'
    ): int {
        $value = trim((string) $value);

        if (
            ! preg_match(
                '/^\d+(?:\.\d{1,2})?$/',
                $value
            )
        ) {
            throw ValidationException::withMessages([
                $field => __(
                    'Le montant doit contenir au maximum deux décimales.'
                ),
            ]);
        }

        [$whole, $decimal] = array_pad(
            explode('.', $value, 2),
            2,
            ''
        );

        $decimal = str_pad(
            $decimal,
            2,
            '0'
        );

        return ((int) $whole * 100)
            + (int) $decimal;
    }

    private function fromCents(
        int $cents
    ): string {
        return sprintf(
            '%d.%02d',
            intdiv($cents, 100),
            $cents % 100
        );
    }

    private function nullableString(
        ?string $value
    ): ?string {
        $value = trim(
            (string) $value
        );

        return $value === ''
            ? null
            : $value;
    }
}
