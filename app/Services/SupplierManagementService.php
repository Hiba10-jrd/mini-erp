<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierCodeSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SupplierManagementService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Supplier
    {
        Gate::authorize('suppliers.manage');

        return DB::transaction(function () use ($attributes): Supplier {
            $sequence = SupplierCodeSequence::query()
                ->where('singleton', true)
                ->lockForUpdate()
                ->firstOrFail();
            $number = $sequence->next_number;
            $sequence->increment('next_number');

            $supplier = new Supplier($attributes);
            $supplier->code = 'FOU-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
            $supplier->status = 'active';
            $supplier->save();

            return $supplier->load('paymentTerm');
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $supplierId, array $attributes): Supplier
    {
        Gate::authorize('suppliers.manage');

        return DB::transaction(function () use ($supplierId, $attributes): Supplier {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplierId);

            if ($supplier->isArchived()) {
                throw ValidationException::withMessages([
                    'supplier' => __('Un fournisseur archivé ne peut pas être modifié.'),
                ]);
            }

            $supplier->fill($attributes)->save();

            return $supplier->load('paymentTerm');
        });
    }

    public function archive(int $supplierId): Supplier
    {
        Gate::authorize('suppliers.manage');

        return DB::transaction(function () use ($supplierId): Supplier {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplierId);

            if (! $supplier->isArchived()) {
                $supplier->forceFill([
                    'status' => 'archived',
                    'archived_at' => now(),
                ])->save();
            }

            return $supplier->fresh('paymentTerm');
        });
    }
}
