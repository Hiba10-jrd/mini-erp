<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SupplierContactManagementService
{
    /** @param array<string, mixed> $attributes */
    public function create(int $supplierId, array $attributes): SupplierContact
    {
        Gate::authorize('suppliers.manage');

        return DB::transaction(function () use ($supplierId, $attributes): SupplierContact {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplierId);
            $this->ensureSupplierIsActive($supplier);
            $this->clearOtherPrimaryContacts($supplierId, $attributes['is_primary'] ?? false);

            return $supplier->contacts()->create($attributes);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $supplierId, int $contactId, array $attributes): SupplierContact
    {
        Gate::authorize('suppliers.manage');

        return DB::transaction(function () use ($supplierId, $contactId, $attributes): SupplierContact {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplierId);
            $this->ensureSupplierIsActive($supplier);
            $contact = SupplierContact::query()
                ->where('supplier_id', $supplierId)
                ->lockForUpdate()
                ->findOrFail($contactId);
            $this->clearOtherPrimaryContacts($supplierId, $attributes['is_primary'] ?? false, $contactId);
            $contact->fill($attributes)->save();

            return $contact->fresh();
        });
    }

    public function archive(int $supplierId, int $contactId): SupplierContact
    {
        Gate::authorize('suppliers.manage');

        return DB::transaction(function () use ($supplierId, $contactId): SupplierContact {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplierId);
            $this->ensureSupplierIsActive($supplier);
            $contact = SupplierContact::query()
                ->where('supplier_id', $supplierId)
                ->lockForUpdate()
                ->findOrFail($contactId);
            $contact->forceFill(['is_active' => false, 'is_primary' => false])->save();

            return $contact->fresh();
        });
    }

    private function clearOtherPrimaryContacts(int $supplierId, bool $isPrimary, ?int $exceptContactId = null): void
    {
        if (! $isPrimary) {
            return;
        }

        $query = SupplierContact::query()->where('supplier_id', $supplierId);
        if ($exceptContactId !== null) {
            $query->whereKeyNot($exceptContactId);
        }
        $query->update(['is_primary' => false]);
    }

    private function ensureSupplierIsActive(Supplier $supplier): void
    {
        if ($supplier->isArchived()) {
            throw ValidationException::withMessages([
                'supplier' => __('Les contacts d’un fournisseur archivé ne peuvent pas être modifiés.'),
            ]);
        }
    }
}
