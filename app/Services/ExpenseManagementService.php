<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExpenseManagementService
{
    /**
     * @param array{
     *     expense_category_id:int,
     *     payment_method_id:int,
     *     cash_register_id?:?int,
     *     expense_date:string,
     *     amount:string|int,
     *     tax_amount?:string|int,
     *     reference?:?string,
     *     description?:?string,
     *     receipt_path?:?string
     * } $attributes
     */
    public function create(array $attributes): Expense
    {
        Gate::authorize('payments.create');

        return DB::transaction(function () use ($attributes): Expense {
            $category = ExpenseCategory::query()
                ->lockForUpdate()
                ->findOrFail(
                    (int) $attributes['expense_category_id']
                );

            if (! $category->is_active) {
                throw ValidationException::withMessages([
                    'expense_category_id' => __('La catégorie sélectionnée est inactive.'),
                ]);
            }

            $paymentMethod = PaymentMethod::query()
                ->lockForUpdate()
                ->findOrFail(
                    (int) $attributes['payment_method_id']
                );

            if (! $paymentMethod->is_active) {
                throw ValidationException::withMessages([
                    'payment_method_id' => __('Le mode de paiement sélectionné est inactif.'),
                ]);
            }

            $amountCents = $this->toCents(
                $attributes['amount'],
                'amount'
            );

            if ($amountCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => __('Le montant de la dépense doit être supérieur à zéro.'),
                ]);
            }

            $taxCents = $this->toCents(
                $attributes['tax_amount'] ?? '0',
                'tax_amount'
            );

            if ($taxCents > $amountCents) {
                throw ValidationException::withMessages([
                    'tax_amount' => __('La TVA ne peut pas dépasser le montant total de la dépense.'),
                ]);
            }

            $cashRegister = null;

            if (
                $paymentMethod->payment_type
                === PaymentMethod::TYPE_CASH
            ) {
                $cashRegisterId = (int) (
                    $attributes['cash_register_id'] ?? 0
                );

                if ($cashRegisterId <= 0) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => __('Une caisse est obligatoire pour un paiement en espèces.'),
                    ]);
                }

                $cashRegister = CashRegister::query()
                    ->lockForUpdate()
                    ->findOrFail($cashRegisterId);

                if (! $cashRegister->is_active) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => __('La caisse sélectionnée est inactive.'),
                    ]);
                }

                if (
                    $amountCents
                    > $this->currentBalanceCents($cashRegister)
                ) {
                    throw ValidationException::withMessages([
                        'amount' => __('Le solde de la caisse est insuffisant pour cette dépense.'),
                    ]);
                }
            } elseif (
                ! empty($attributes['cash_register_id'])
            ) {
                throw ValidationException::withMessages([
                    'cash_register_id' => __('Une caisse ne doit être sélectionnée que pour un paiement en espèces.'),
                ]);
            }

            $expense = Expense::query()->create([
                'expense_category_id' => $category->id,
                'payment_method_id' => $paymentMethod->id,
                'cash_register_id' => $cashRegister?->id,
                'expense_date' => $attributes['expense_date'],
                'amount' => $this->fromCents($amountCents),
                'tax_amount' => $this->fromCents($taxCents),
                'reference' => $this->nullableString(
                    $attributes['reference'] ?? null
                ),
                'description' => $this->nullableString(
                    $attributes['description'] ?? null
                ),
                'receipt_path' => $this->nullableString(
                    $attributes['receipt_path'] ?? null
                ),
                'created_by' => Auth::id(),
            ]);

            if ($cashRegister !== null) {
                CashTransaction::query()->create([
                    'cash_register_id' => $cashRegister->id,
                    'expense_id' => $expense->id,
                    'transaction_date' => $attributes['expense_date'],
                    'type' => CashTransaction::TYPE_EXIT,
                    'amount' => $this->fromCents($amountCents),
                    'reference' => $this->nullableString(
                        $attributes['reference'] ?? null
                    ),
                    'description' => __('Dépense : :description', [
                        'description' => $expense->description
                            ?? $category->name,
                    ]),
                    'created_by' => Auth::id(),
                ]);
            }

            return $expense->fresh([
                'category',
                'paymentMethod',
                'cashRegister',
                'cashTransaction',
                'creator',
            ]);
        }, 3);
    }

    private function currentBalanceCents(
        CashRegister $register
    ): int {
        $initialCents = $this->toCents(
            (string) $register->initial_balance,
            'initial_balance'
        );

        $entriesCents = CashTransaction::query()
            ->where('cash_register_id', $register->id)
            ->where('type', CashTransaction::TYPE_ENTRY)
            ->pluck('amount')
            ->reduce(
                fn (int $total, mixed $amount): int => $total
                    + $this->toCents((string) $amount),
                0
            );

        $exitsCents = CashTransaction::query()
            ->where('cash_register_id', $register->id)
            ->where('type', CashTransaction::TYPE_EXIT)
            ->pluck('amount')
            ->reduce(
                fn (int $total, mixed $amount): int => $total
                    + $this->toCents((string) $amount),
                0
            );

        return $initialCents + $entriesCents - $exitsCents;
    }

    private function toCents(
        string|int $value,
        string $field = 'amount'
    ): int {
        $value = trim((string) $value);

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => __('Le montant doit contenir au maximum deux décimales.'),
            ]);
        }

        [$whole, $decimal] = array_pad(
            explode('.', $value, 2),
            2,
            ''
        );

        $decimal = str_pad($decimal, 2, '0');

        return ((int) $whole * 100) + (int) $decimal;
    }

    private function fromCents(int $cents): string
    {
        return sprintf(
            '%d.%02d',
            intdiv($cents, 100),
            $cents % 100
        );
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
