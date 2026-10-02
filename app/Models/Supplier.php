<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'trade_name',
        'ice',
        'tax_id',
        'commercial_register',
        'address',
        'city',
        'country',
        'phone',
        'email',
        'notes',
        'payment_term_id',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }
}
