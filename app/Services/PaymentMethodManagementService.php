<?php

namespace App\Services;

use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PaymentMethodManagementService
{
    /** @param array{name: string, is_active: bool, sort_order: int} $attributes */
    public function save(?int $paymentMethodId, array $attributes): PaymentMethod
    {
        Gate::authorize('company.administer');

        return DB::transaction(function () use ($paymentMethodId, $attributes): PaymentMethod {
            $method = $paymentMethodId === null
                ? new PaymentMethod
                : PaymentMethod::query()->lockForUpdate()->findOrFail($paymentMethodId);

            $method->fill($attributes)->save();

            return $method->fresh();
        });
    }
}
