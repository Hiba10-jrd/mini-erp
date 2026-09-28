<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCodeSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CustomerManagementService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Customer
    {
        Gate::authorize('customers.manage');

        return DB::transaction(function () use ($attributes): Customer {
            $sequence = CustomerCodeSequence::query()
                ->where('singleton', true)
                ->lockForUpdate()
                ->firstOrFail();
            $number = $sequence->next_number;
            $sequence->increment('next_number');

            $customer = new Customer($attributes);
            $customer->code = 'CLI-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
            $customer->status = 'active';
            $customer->save();

            return $customer->load('paymentTerm');
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $customerId, array $attributes): Customer
    {
        Gate::authorize('customers.manage');

        return DB::transaction(function () use ($customerId, $attributes): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);

            if ($customer->isArchived()) {
                throw ValidationException::withMessages([
                    'customer' => __('Un client archivé ne peut pas être modifié.'),
                ]);
            }

            $customer->fill($attributes)->save();

            return $customer->load('paymentTerm');
        });
    }

    public function archive(int $customerId): Customer
    {
        Gate::authorize('customers.manage');

        return DB::transaction(function () use ($customerId): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);

            if (! $customer->isArchived()) {
                $customer->forceFill([
                    'status' => 'archived',
                    'archived_at' => now(),
                ])->save();
            }

            return $customer->fresh('paymentTerm');
        });
    }
}
