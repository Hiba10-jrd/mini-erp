<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserAccountStatus;
use App\Models\Concerns\RecordsOperations;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use RecordsOperations;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function notifications(): MorphMany
    {
        return $this->morphMany(InternalDatabaseNotification::class, 'notifiable')->latest();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_user'
        );
    }

    public function createdQuotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'created_by');
    }

    public function createdPurchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'created_by');
    }

    public function confirmedPurchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'confirmed_by');
    }

    public function createdGoodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'created_by');
    }

    public function validatedGoodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'validated_by');
    }

    public function createdSupplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class, 'created_by');
    }

    public function validatedSupplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class, 'validated_by');
    }

    public function createdSupplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'created_by');
    }

    public function createdExpenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'created_by');
    }

    public function createdCustomerReminders(): HasMany
    {
        return $this->hasMany(CustomerReminder::class, 'created_by');
    }

    public function createdCashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class, 'created_by');
    }

    public function createdCashTransactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'account_status' => UserAccountStatus::class,
        ];
    }

    public function isActive(): bool
    {
        return $this->account_status === UserAccountStatus::Active;
    }

    public function isDisabled(): bool
    {
        return $this->account_status === UserAccountStatus::Disabled;
    }

    public function isArchived(): bool
    {
        return $this->account_status === UserAccountStatus::Archived;
    }

    /**
     * Determine whether the user is a Super Administrator.
     */
    public function isSuperAdministrator(): bool
    {
        return $this->roles()
            ->where('slug', 'super-admin')
            ->exists();
    }

    /**
     * Determine whether the user has a given permission.
     */
    public function hasPermission(string $permission): bool
    {
        // Refuse permissions that are not defined in the ERP.
        if (! in_array(
            $permission,
            config('erp.permissions', []),
            true
        )) {
            return false;
        }

        // The Super Administrator has access to all
        // registered ERP permissions.
        if ($this->isSuperAdministrator()) {
            return true;
        }

        // Check permissions assigned to the user's roles.
        return $this->roles()
            ->whereHas('permissions', function ($query) use ($permission) {
                $query->where('name', $permission);
            })
            ->exists();
    }

    public function preferredLocale(): ?string
    {
        return $this->locale;
    }
}
