<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\TaxRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class QuoteManagementService
{
    public function __construct(
        private readonly QuoteCalculator $calculator,
        private readonly DocumentSequenceManagementService $sequences,
    ) {}

    /**
     * @param  array{customer_id: int, quote_date: string, valid_until: ?string, terms: ?string, notes: ?string}  $attributes
     * @param  array<int, array{product_id: int, description?: ?string, quantity: string, unit_price: string, discount_percent: string, tax_rate_id?: ?int}>  $lines
     */
    public function create(array $attributes, array $lines): Quote
    {
        Gate::authorize('sales.create');
        [$header, $preparedLines, $totals, $customer] = $this->prepare($attributes, $lines);

        return DB::transaction(function () use ($header, $preparedLines, $totals, $customer): Quote {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->status !== 'active') {
                throw ValidationException::withMessages(['customerId' => __('Le client sélectionné n’est pas actif.')]);
            }

            $date = Carbon::parse($header['quote_date']);
            $number = $this->sequences->allocate('quote', $date->year);

            $quote = new Quote($header);
            $quote->forceFill([
                'number' => $number,
                'status' => Quote::STATUS_DRAFT,
                'subtotal_ht' => $totals['subtotal_ht'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'total_ttc' => $totals['total_ttc'],
                'created_by' => Auth::id(),
                ...$this->customerSnapshot($customer),
            ])->save();

            $quote->items()->createMany($preparedLines);

            return $quote->load(['customer', 'creator', 'items']);
        }, 3);
    }

    /**
     * @param  array{customer_id: int, quote_date: string, valid_until: ?string, terms: ?string, notes: ?string}  $attributes
     * @param  array<int, array{product_id: int, description?: ?string, quantity: string, unit_price: string, discount_percent: string, tax_rate_id?: ?int}>  $lines
     */
    public function update(Quote $quote, array $attributes, array $lines): Quote
    {
        Gate::authorize('sales.update');
        [$header, $preparedLines, $totals] = $this->prepare($attributes, $lines);

        return DB::transaction(function () use ($quote, $header, $preparedLines, $totals): Quote {
            $locked = Quote::query()->lockForUpdate()->findOrFail($quote->id);
            $this->ensureEditable($locked);

            $locked->fill($header)->forceFill([
                'subtotal_ht' => $totals['subtotal_ht'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'total_ttc' => $totals['total_ttc'],
            ])->save();

            $locked->items()->get()->each->delete();
            $locked->items()->createMany($preparedLines);

            return $locked->load(['customer', 'creator', 'items']);
        }, 3);
    }

    public function transition(Quote $quote, string $targetStatus): Quote
    {
        Gate::authorize('sales.update');

        return DB::transaction(function () use ($quote, $targetStatus): Quote {
            $locked = Quote::query()->lockForUpdate()->findOrFail($quote->id);

            if ($locked->archived_at !== null) {
                throw ValidationException::withMessages(['status' => __('Un devis archivé ne peut pas changer de statut.')]);
            }

            $allowed = [
                Quote::STATUS_DRAFT => [Quote::STATUS_SENT],
                Quote::STATUS_SENT => [Quote::STATUS_ACCEPTED, Quote::STATUS_REFUSED, Quote::STATUS_EXPIRED],
                Quote::STATUS_ACCEPTED => [Quote::STATUS_DRAFT],
                Quote::STATUS_REFUSED => [Quote::STATUS_ACCEPTED],
                Quote::STATUS_EXPIRED => [Quote::STATUS_SENT],
            ];

            if (! in_array($targetStatus, $allowed[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('Cette transition de statut est interdite.')]);
            }

            if ($targetStatus === Quote::STATUS_EXPIRED
                && ($locked->valid_until === null || ! $locked->valid_until->isBefore(today()))) {
                throw ValidationException::withMessages(['status' => __('Seul un devis dont la date de validité est dépassée peut expirer.')]);
            }

            if (! $locked->items()->exists()) {
                throw ValidationException::withMessages(['status' => __('Un devis sans ligne ne peut pas être envoyé.')]);
            }

            $timestampField = match ($targetStatus) {
                Quote::STATUS_SENT => 'sent_at',
                Quote::STATUS_ACCEPTED => 'accepted_at',
                Quote::STATUS_REFUSED => 'refused_at',
                Quote::STATUS_EXPIRED => 'expired_at',
                default => null,
            };

            $changes = ['status' => $targetStatus];
            if ($timestampField !== null) {
                $changes[$timestampField] = now();
            }

            $locked->forceFill($changes)->save();

            return $locked->fresh(['customer', 'creator', 'items']);
        }, 3);
    }

    public function archiveDraft(Quote $quote): Quote
    {
        Gate::authorize('sales.delete');

        return DB::transaction(function () use ($quote): Quote {
            $locked = Quote::query()->lockForUpdate()->findOrFail($quote->id);
            $this->ensureEditable($locked);
            $locked->forceFill(['archived_at' => now()])->save();

            return $locked->fresh();
        }, 3);
    }

    /**
     * @param  array{customer_id: int, quote_date: string, valid_until: ?string, terms: ?string, notes: ?string}  $attributes
     * @param  array<int, array{product_id: int, description?: ?string, quantity: string, unit_price: string, discount_percent: string, tax_rate_id?: ?int}>  $lines
    * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>, 2: array<string, string>, 3: Customer}
     */
    private function prepare(array $attributes, array $lines): array
    {
        $attributes = Validator::make($attributes, [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'quote_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quote_date'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ])->validate();

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Ajoutez au moins une ligne au devis.')]);
        }

        $lines = Validator::make(['lines' => $lines], [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.description' => ['nullable', 'string', 'max:5000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999999.999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999999.99'],
            'lines.*.discount_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'lines.*.tax_rate_id' => ['sometimes', 'nullable', 'string'],
        ])->validate()['lines'];

        $customer = Customer::query()->findOrFail($attributes['customer_id']);
        if ($customer->status !== 'active') {
            throw ValidationException::withMessages(['customerId' => __('Le client sélectionné n’est pas actif.')]);
        }

        $quoteDate = Carbon::parse($attributes['quote_date'])->startOfDay();
        $validUntil = filled($attributes['valid_until']) ? Carbon::parse($attributes['valid_until'])->startOfDay() : null;
        if ($validUntil !== null && $validUntil->isBefore($quoteDate)) {
            throw ValidationException::withMessages(['validUntil' => __('La date de validité doit être postérieure ou égale à la date du devis.')]);
        }

        $productIds = collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();
        $products = Product::query()->with(['unit', 'taxRate'])->whereKey($productIds)->get()->keyBy('id');
        $defaultTaxRate = TaxRate::query()->where('is_active', true)->where('is_default', true)->first();
        $taxRateIds = collect($lines)->pluck('tax_rate_id')->filter()->map(fn ($id) => (int) $id)
            ->merge($products->pluck('tax_rate_id')->filter()->map(fn ($id) => (int) $id))
            ->merge($defaultTaxRate === null ? [] : [$defaultTaxRate->id])
            ->unique()
            ->values();
        $taxRates = TaxRate::query()->whereKey($taxRateIds)->get()->keyBy('id');
        $prepared = [];

        foreach (array_values($lines) as $position => $line) {
            $product = $products->get((int) $line['product_id']);
            if ($product === null || ! $product->is_active) {
                throw ValidationException::withMessages(["lines.{$position}.product_id" => __('Le produit ou service sélectionné n’est pas actif.')]);
            }

            $taxRateId = ($line['tax_rate_id'] ?? null) === 'none'
                ? null
                : (filled($line['tax_rate_id'] ?? null)
                    ? (int) $line['tax_rate_id']
                    : ($product->tax_rate_id ?? $defaultTaxRate?->id));
            $taxRate = $taxRateId === null ? null : $taxRates->get($taxRateId);
            if ($taxRateId !== null && ($taxRate === null || ! $taxRate->is_active)) {
                throw ValidationException::withMessages(["lines.{$position}.tax_rate_id" => __('Le taux de TVA sélectionné n’est pas actif.')]);
            }

            try {
                $calculated = $this->calculator->line(
                    (string) $line['quantity'],
                    (string) $line['unit_price'],
                    (string) $line['discount_percent'],
                    $taxRate?->rate ?? '0.00',
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(["lines.{$position}" => $exception->getMessage()]);
            }
            $description = trim((string) ($line['description'] ?? ''));

            $prepared[] = [
                'product_id' => $product->id,
                'item_type' => $product->type,
                'reference' => $product->reference,
                'description' => $description !== '' ? $description : $product->name,
                'unit_label' => $product->unit?->symbol,
                'quantity' => $calculated['quantity'],
                'unit_price' => $calculated['unit_price'],
                'discount_percent' => $calculated['discount_percent'],
                'discount_amount' => $calculated['discount_amount'],
                'tax_rate_id' => $taxRate?->id,
                'tax_rate_percent' => $calculated['tax_rate_percent'],
                'subtotal_ht' => $calculated['subtotal_ht'],
                'tax_amount' => $calculated['tax_amount'],
                'total_ttc' => $calculated['total_ttc'],
                'position' => $position + 1,
                '_gross_ht' => $calculated['gross_ht'],
            ];
        }

        $totals = $this->calculator->totals(array_map(fn (array $line): array => [
            'gross_ht' => $line['_gross_ht'],
            'discount_amount' => $line['discount_amount'],
            'subtotal_ht' => $line['subtotal_ht'],
            'tax_amount' => $line['tax_amount'],
            'total_ttc' => $line['total_ttc'],
        ], $prepared));

        foreach ($prepared as &$line) {
            unset($line['_gross_ht']);
        }

        return [[
            'customer_id' => $customer->id,
            'quote_date' => $quoteDate->toDateString(),
            'valid_until' => $validUntil?->toDateString(),
            'terms' => $this->nullableString($attributes['terms']),
            'notes' => $this->nullableString($attributes['notes']),
        ], $prepared, $totals, $customer];
    }

    /** @return array<string, ?string> */
    private function customerSnapshot(Customer $customer): array
    {
        return [
            'customer_name' => $customer->name,
            'customer_trade_name' => $customer->trade_name,
            'customer_address' => $customer->address,
            'customer_city' => $customer->city,
            'customer_country' => $customer->country,
            'customer_email' => $customer->email,
            'customer_phone' => $customer->phone,
            'customer_ice' => $customer->ice,
            'customer_tax_id' => $customer->tax_id,
            'customer_commercial_register' => $customer->commercial_register,
        ];
    }

    private function ensureEditable(Quote $quote): void
    {
        if (! $quote->isEditable()) {
            throw ValidationException::withMessages(['quote' => __('Seul un devis brouillon non archivé peut être modifié.')]);
        }
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
