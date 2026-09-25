<?php

namespace App\Services;

use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TaxRateManagementService
{
    /** @param array{label: string, rate: string|float, is_active: bool, is_default: bool} $attributes */
    public function save(?int $taxRateId, array $attributes): TaxRate
    {
        Gate::authorize('company.administer');

        return DB::transaction(function () use ($taxRateId, $attributes): TaxRate {
            $taxRate = $taxRateId === null
                ? new TaxRate
                : TaxRate::query()->lockForUpdate()->findOrFail($taxRateId);

            if ($attributes['is_default'] && ! $attributes['is_active']) {
                throw ValidationException::withMessages([
                    'isDefault' => __('Un taux inactif ne peut pas être le taux par défaut.'),
                ]);
            }

            if ($attributes['is_default']) {
                $query = TaxRate::query();

                if ($taxRate->exists) {
                    $query->whereKeyNot($taxRate->getKey());
                }

                $query->update(['is_default' => false]);
            }

            $taxRate->fill($attributes)->save();

            return $taxRate->fresh();
        });
    }
}
