<?php

namespace App\Services;

use App\Models\PaymentTerm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PaymentTermManagementService
{
    /** @param array{label: string, due_days: int, description: string|null, is_active: bool, is_default: bool} $attributes */
    public function save(?int $paymentTermId, array $attributes): PaymentTerm
    {
        Gate::authorize('company.administer');

        return DB::transaction(function () use ($paymentTermId, $attributes): PaymentTerm {
            $term = $paymentTermId === null
                ? new PaymentTerm
                : PaymentTerm::query()->lockForUpdate()->findOrFail($paymentTermId);

            if ($attributes['is_default']) {
                $query = PaymentTerm::query();

                if ($term->exists) {
                    $query->whereKeyNot($term->getKey());
                }

                $query->update(['is_default' => false]);
            }

            $term->fill($attributes)->save();

            return $term->fresh();
        });
    }
}
