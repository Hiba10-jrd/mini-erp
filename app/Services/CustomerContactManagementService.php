<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CustomerContactManagementService
{
    /** @param array<string, mixed> $attributes */
    public function create(int $customerId, array $attributes): CustomerContact
    {
        Gate::authorize('customers.manage');

        return DB::transaction(function () use ($customerId, $attributes): CustomerContact {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $this->ensureCustomerIsActive($customer);
            $this->clearOtherPrimaryContacts($customerId, $attributes['is_primary'] ?? false);

            return $customer->contacts()->create($attributes);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $customerId, int $contactId, array $attributes): CustomerContact
    {
        Gate::authorize('customers.manage');

        return DB::transaction(function () use ($customerId, $contactId, $attributes): CustomerContact {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $this->ensureCustomerIsActive($customer);
            $contact = CustomerContact::query()
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->findOrFail($contactId);
            $this->clearOtherPrimaryContacts($customerId, $attributes['is_primary'] ?? false, $contactId);
            $contact->fill($attributes)->save();

            return $contact->fresh();
        });
    }

    public function archive(int $customerId, int $contactId): CustomerContact
    {
        Gate::authorize('customers.manage');

        return DB::transaction(function () use ($customerId, $contactId): CustomerContact {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $this->ensureCustomerIsActive($customer);
            $contact = CustomerContact::query()
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->findOrFail($contactId);
            $contact->forceFill(['is_active' => false, 'is_primary' => false])->save();

            return $contact->fresh();
        });
    }

    private function clearOtherPrimaryContacts(int $customerId, bool $isPrimary, ?int $exceptContactId = null): void
    {
        if (! $isPrimary) {
            return;
        }

        $query = CustomerContact::query()->where('customer_id', $customerId);
        if ($exceptContactId !== null) {
            $query->whereKeyNot($exceptContactId);
        }
        $query->update(['is_primary' => false]);
    }

    private function ensureCustomerIsActive(Customer $customer): void
    {
        if ($customer->isArchived()) {
            throw ValidationException::withMessages([
                'customer' => __('Les contacts d’un client archivé ne peuvent pas être modifiés.'),
            ]);
        }
    }
}
