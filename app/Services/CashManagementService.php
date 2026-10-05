<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\CashTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CashManagementService
{
    public function createRegister(array $attributes): CashRegister
    {
        Gate::authorize('payments.create');

        return DB::transaction(function () use ($attributes): CashRegister {
            $code = strtoupper(
                $this->requiredString(
                    $attributes['code'] ?? null,
                    'code',
                    __('Le code de la caisse est obligatoire.')
                )
            );

            $name = $this->requiredString(
                $attributes['name'] ?? null,
                'name',
                __('Le nom de la caisse est obligatoire.')
            );

            if (
                CashRegister::query()
                    ->where('code', $code)
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'code' => __('Ce code de caisse existe déjà.'),
                ]);
            }

            $initialBalanceCents = $this->toCents(
                $attributes['initial_balance'] ?? '0',
                'initial_balance'
            );

            return CashRegister::query()->create([
                'code' => $code,
                'name' => $name,
                'initial_balance' => $this->fromCents(
                    $initialBalanceCents
                ),
                'is_active' => (bool) (
                    $attributes['is_active'] ?? true
                ),
                'created_by' => Auth::id(),
            ]);
        }, 3);
    }

    public function createManualTransaction(
        int $cashRegisterId,
        array $attributes
    ): CashTransaction {
        Gate::authorize('payments.create');

        return DB::transaction(function () use (
            $cashRegisterId,
            $attributes
        ): CashTransaction {
            $register = CashRegister::query()
                ->lockForUpdate()
                ->findOrFail($cashRegisterId);

            if (! $register->is_active) {
                throw ValidationException::withMessages([
                    'cash_register_id' => __('La caisse sélectionnée est inactive.'),
                ]);
            }

            $type = (string) ($attributes['type'] ?? '');

            if (! in_array($type, CashTransaction::types(), true)) {
                throw ValidationException::withMessages([
                    'type' => __('Le type de mouvement de caisse est invalide.'),
                ]);
            }

            $amountCents = $this->toCents(
                $attributes['amount'] ?? '',
                'amount'
            );

            if ($amountCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => __('Le montant doit être supérieur à zéro.'),
                ]);
            }

            if (
                $type === CashTransaction::TYPE_EXIT
                && $amountCents > $this->currentBalanceCents($register)
            ) {
                throw ValidationException::withMessages([
                    'amount' => __('Le solde de la caisse est insuffisant.'),
                ]);
            }

            return CashTransaction::query()->create([
                'cash_register_id' => $register->id,
                'expense_id' => null,
                'transaction_date' => $attributes['transaction_date'],
                'type' => $type,
                'amount' => $this->fromCents($amountCents),
                'reference' => $this->nullableString(
                    $attributes['reference'] ?? null
                ),
                'description' => $this->nullableString(
                    $attributes['description'] ?? null
                ),
                'created_by' => Auth::id(),
            ]);
        }, 3);
    }

    public function currentBalance(CashRegister $register): string
    {
        return $this->fromCents(
            $this->currentBalanceCents($register)
        );
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

    private function requiredString(
        mixed $value,
        string $field,
        string $message
    ): string {
        $value = trim((string) $value);

        if ($value === '') {
            throw ValidationException::withMessages([
                $field => $message,
            ]);
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
