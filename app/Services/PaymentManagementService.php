<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PaymentManagementService
{
    /**
     * @param  array{
     *     customer_id:int,
     *     payment_method_id:int,
     *     payment_date:string,
     *     amount:string|int,
     *     reference?:?string,
     *     notes?:?string
     * }  $attributes
     * @param  array<int, array{invoice_id:int, amount:string|int}>  $allocations
     */
    public function create(array $attributes, array $allocations): Payment
    {
        Gate::authorize('payments.create');

        return DB::transaction(function () use ($attributes, $allocations): Payment {
            $paymentMethod = PaymentMethod::query()
                ->lockForUpdate()
                ->findOrFail($attributes['payment_method_id']);

            if (! $paymentMethod->is_active) {
                throw ValidationException::withMessages([
                    'payment_method_id' => __('Le mode de paiement sélectionné est inactif.'),
                ]);
            }

            $paymentCents = $this->toCents($attributes['amount']);

            if ($paymentCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => __('Le montant du paiement doit être supérieur à zéro.'),
                ]);
            }

            if ($allocations === []) {
                throw ValidationException::withMessages([
                    'allocations' => __('Au moins une facture doit être affectée au paiement.'),
                ]);
            }

            $seenInvoiceIds = [];
            $preparedAllocations = [];
            $allocatedCents = 0;

            foreach ($allocations as $index => $allocation) {
                $invoiceId = (int) $allocation['invoice_id'];

                if (isset($seenInvoiceIds[$invoiceId])) {
                    throw ValidationException::withMessages([
                        "allocations.$index.invoice_id" => __('Une facture ne peut apparaître qu’une seule fois dans le même paiement.'),
                    ]);
                }

                $seenInvoiceIds[$invoiceId] = true;

                $invoice = Invoice::query()
                    ->lockForUpdate()
                    ->findOrFail($invoiceId);

                if (! $invoice->isIssued()) {
                    throw ValidationException::withMessages([
                        "allocations.$index.invoice_id" => __('Seule une facture émise peut recevoir un paiement.'),
                    ]);
                }

                if ((int) $invoice->customer_id !== (int) $attributes['customer_id']) {
                    throw ValidationException::withMessages([
                        "allocations.$index.invoice_id" => __('La facture sélectionnée appartient à un autre client.'),
                    ]);
                }

                $allocationCents = $this->toCents($allocation['amount']);

                if ($allocationCents <= 0) {
                    throw ValidationException::withMessages([
                        "allocations.$index.amount" => __('Le montant affecté doit être supérieur à zéro.'),
                    ]);
                }

                $remainingCents = $this->remainingAmountCents($invoice);

                if ($allocationCents > $remainingCents) {
                    throw ValidationException::withMessages([
                        "allocations.$index.amount" => __('Le montant affecté dépasse le reste à payer de la facture.'),
                    ]);
                }

                $allocatedCents += $allocationCents;

                if ($allocatedCents > $paymentCents) {
                    throw ValidationException::withMessages([
                        'allocations' => __('Le total des affectations dépasse le montant du paiement.'),
                    ]);
                }

                $preparedAllocations[] = [
                    'invoice_id' => $invoice->id,
                    'amount' => $this->fromCents($allocationCents),
                ];
            }

            if ($allocatedCents !== $paymentCents) {
                throw ValidationException::withMessages([
                    'allocations' => __('Le total des affectations doit correspondre exactement au montant du paiement.'),
                ]);
            }

            $payment = Payment::query()->create([
                'customer_id' => (int) $attributes['customer_id'],
                'payment_method_id' => $paymentMethod->id,
                'payment_date' => $attributes['payment_date'],
                'amount' => $this->fromCents($paymentCents),
                'reference' => $this->nullableString($attributes['reference'] ?? null),
                'details' => $data['details'] ?? null,
                'notes' => $this->nullableString($attributes['notes'] ?? null),
                'created_by' => Auth::id(),
            ]);

            $payment->allocations()->createMany($preparedAllocations);

            return $payment->fresh([
                'customer',
                'paymentMethod',
                'creator',
                'allocations.invoice',
            ]);
        });
    }

    public function paidAmount(Invoice $invoice): string
    {
        return $this->fromCents($this->paidAmountCents($invoice));
    }

    public function creditedAmount(Invoice $invoice): string
    {
        return $this->fromCents($this->creditedAmountCents($invoice));
    }

    public function payableAmount(Invoice $invoice): string
    {
        return $this->fromCents($this->payableAmountCents($invoice));
    }

    public function remainingAmount(Invoice $invoice): string
    {
        return $this->fromCents($this->remainingAmountCents($invoice));
    }

    public function paymentState(Invoice $invoice): string
    {
        if (! $invoice->isIssued()) {
            return 'not_applicable';
        }

        $payableCents = $this->payableAmountCents($invoice);
        $paidCents = $this->paidAmountCents($invoice);
        $remainingCents = max(0, $payableCents - $paidCents);

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

    private function paidAmountCents(Invoice $invoice): int
    {
        return $invoice->paymentAllocations()
            ->pluck('amount')
            ->reduce(
                fn (int $total, mixed $amount): int => $total + $this->toCents((string) $amount),
                0
            );
    }

    private function creditedAmountCents(Invoice $invoice): int
    {
        return $invoice->creditNotes()
            ->where('status', CreditNote::STATUS_ISSUED)
            ->pluck('total_ttc')
            ->reduce(
                fn (int $total, mixed $amount): int => $total + $this->toCents((string) $amount),
                0
            );
    }

    private function payableAmountCents(Invoice $invoice): int
    {
        return max(
            0,
            $this->toCents((string) $invoice->total_ttc)
                - $this->creditedAmountCents($invoice)
        );
    }

    private function remainingAmountCents(Invoice $invoice): int
    {
        return max(
            0,
            $this->payableAmountCents($invoice)
                - $this->paidAmountCents($invoice)
        );
    }

    private function toCents(string|int $value): int
    {
        $value = trim((string) $value);

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages([
                'amount' => __('Le montant doit contenir au maximum deux décimales.'),
            ]);
        }

        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        $decimal = str_pad($decimal, 2, '0');

        return ((int) $whole * 100) + (int) $decimal;
    }

    private function fromCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
