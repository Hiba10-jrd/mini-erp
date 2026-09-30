<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'customer_type',
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
        'credit_limit',
    ];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'archived_at' => 'datetime'];
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderBy('invoice_date')->orderBy('id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class)->orderBy('credit_date')->orderBy('id');
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }
}
