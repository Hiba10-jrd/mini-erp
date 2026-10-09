<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\TaxRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SalesOrderManagementService
{
    public function __construct(
        private readonly QuoteCalculator $calculator,
        private readonly DocumentSequenceManagementService $sequences,
    ) {}

    /**
     * @param  array{customer_id: int, order_date: string, terms: ?string, notes: ?string}  $attributes
     * @param  array<int, array{product_id: int, description?: ?string, ordered_quantity: string, unit_price: string, discount_percent: string, tax_rate_id?: ?int}>  $lines
     */
    public function create(array $attributes, array $lines): SalesOrder
    {
        Gate::authorize('sales.create');
        [$header, $preparedLines, $totals, $customer] = $this->prepare($attributes, $lines);

        return DB::transaction(function () use ($header, $preparedLines, $totals, $customer): SalesOrder {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $this->ensureCustomerActive($customer);
            $number = $this->sequences->allocate('order', Carbon::parse($header['order_date'])->year);

            $order = new SalesOrder($header);
            $order->forceFill([
                'number' => $number,
                'status' => SalesOrder::STATUS_DRAFT,
                'subtotal_ht' => $totals['subtotal_ht'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'total_ttc' => $totals['total_ttc'],
                'created_by' => Auth::id(),
                ...$this->customerSnapshot($customer),
            ])->save();
            $order->items()->createMany($preparedLines);
            $this->recordHistory($order, 'created', null, SalesOrder::STATUS_DRAFT, __('Commande créée directement.'), null, 'Commande créée directement.');

            return $order->load(['customer', 'sourceQuote', 'creator', 'items', 'histories']);
        }, 3);
    }

    public function createFromAcceptedQuote(Quote $quote): SalesOrder
    {
        Gate::authorize('sales.create');

        return DB::transaction(function () use ($quote): SalesOrder {
            $lockedQuote = Quote::query()->with('items')->lockForUpdate()->findOrFail($quote->id);
            $existingOrder = SalesOrder::query()->where('source_quote_id', $lockedQuote->id)->first();
            if ($existingOrder !== null) {
                return $existingOrder->load(['customer', 'sourceQuote', 'creator', 'items', 'histories']);
            }

            if ($lockedQuote->status !== Quote::STATUS_ACCEPTED || $lockedQuote->archived_at !== null) {
                throw ValidationException::withMessages(['quote' => __('Seul un devis accepté et non archivé peut être converti en commande.')]);
            }

            if ($lockedQuote->items->isEmpty()) {
                throw ValidationException::withMessages(['quote' => __('Un devis sans ligne ne peut pas être converti en commande.')]);
            }

            $orderDate = today()->toDateString();
            $number = $this->sequences->allocate('order', today()->year);
            $order = new SalesOrder([
                'customer_id' => $lockedQuote->customer_id,
                'order_date' => $orderDate,
                'terms' => $lockedQuote->terms,
                'notes' => $lockedQuote->notes,
            ]);
            $order->forceFill([
                'number' => $number,
                'source_quote_id' => $lockedQuote->id,
                'status' => SalesOrder::STATUS_DRAFT,
                'subtotal_ht' => $lockedQuote->subtotal_ht,
                'discount_total' => $lockedQuote->discount_total,
                'tax_total' => $lockedQuote->tax_total,
                'total_ttc' => $lockedQuote->total_ttc,
                'created_by' => Auth::id(),
                ...$this->quoteCustomerSnapshot($lockedQuote),
            ])->save();

            foreach ($lockedQuote->items as $quoteItem) {
                $order->items()->create([
                    'product_id' => $quoteItem->product_id,
                    'item_type' => $quoteItem->item_type,
                    'reference' => $quoteItem->reference,
                    'description' => $quoteItem->description,
                    'unit_label' => $quoteItem->unit_label,
                    'ordered_quantity' => $quoteItem->quantity,
                    'delivered_quantity' => '0.000',
                    'unit_price' => $quoteItem->unit_price,
                    'discount_percent' => $quoteItem->discount_percent,
                    'discount_amount' => $quoteItem->discount_amount,
                    'tax_rate_id' => $quoteItem->tax_rate_id,
                    'tax_rate_percent' => $quoteItem->tax_rate_percent,
                    'subtotal_ht' => $quoteItem->subtotal_ht,
                    'tax_amount' => $quoteItem->tax_amount,
                    'total_ttc' => $quoteItem->total_ttc,
                    'position' => $quoteItem->position,
                ]);
            }

            $this->recordHistory($order, 'created', null, SalesOrder::STATUS_DRAFT, __('Commande créée depuis le devis :number.', ['number' => $lockedQuote->number]), null, 'Commande créée depuis le devis :number.', ['number' => $lockedQuote->number]);
            $this->recordHistory($order, 'created_from_quote', null, SalesOrder::STATUS_DRAFT, __('Conversion du devis :number.', ['number' => $lockedQuote->number]), ['source_quote_id' => $lockedQuote->id], 'Conversion du devis :number.', ['number' => $lockedQuote->number]);

            return $order->load(['customer', 'sourceQuote', 'creator', 'items', 'histories']);
        }, 3);
    }

    /**
     * @param  array{customer_id: int, order_date: string, terms: ?string, notes: ?string}  $attributes
     * @param  array<int, array{product_id: int, description?: ?string, ordered_quantity: string, unit_price: string, discount_percent: string, tax_rate_id?: ?int}>  $lines
     */
    public function updateDraft(SalesOrder $order, array $attributes, array $lines): SalesOrder
    {
        Gate::authorize('sales.update');
        [$header, $preparedLines, $totals, $customer] = $this->prepare($attributes, $lines);

        return DB::transaction(function () use ($order, $header, $preparedLines, $totals, $customer): SalesOrder {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->ensureEditable($locked);
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $this->ensureCustomerActive($customer);

            $locked->fill($header)->forceFill([
                'subtotal_ht' => $totals['subtotal_ht'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'total_ttc' => $totals['total_ttc'],
                ...$this->customerSnapshot($customer),
            ])->save();
            $locked->items()->get()->each->delete();
            $locked->items()->createMany($preparedLines);
            $this->recordHistory($locked, 'draft_updated', SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_DRAFT, __('Brouillon de commande modifié.'), null, 'Brouillon de commande modifié.');

            return $locked->load(['customer', 'sourceQuote', 'creator', 'items', 'histories']);
        }, 3);
    }

    public function transition(SalesOrder $order, string $targetStatus): SalesOrder
    {
        Gate::authorize($targetStatus === SalesOrder::STATUS_CANCELLED ? 'sales.delete' : 'sales.update');

        return DB::transaction(function () use ($order, $targetStatus): SalesOrder {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $fromStatus = $locked->status;
            $allowed = [
                SalesOrder::STATUS_DRAFT => [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_CANCELLED],
                SalesOrder::STATUS_CONFIRMED => [SalesOrder::STATUS_CANCELLED],
            ];

            if (! in_array($targetStatus, $allowed[$fromStatus] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('Cette transition de commande est interdite.')]);
            }

            if ($targetStatus === SalesOrder::STATUS_CANCELLED
                && $locked->items()->where('delivered_quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['status' => __('Une commande ayant déjà une livraison ne peut pas être annulée.')]);
            }

            $timestampField = $targetStatus === SalesOrder::STATUS_CONFIRMED ? 'confirmed_at' : 'cancelled_at';
            $locked->forceFill(['status' => $targetStatus, $timestampField => now()])->save();
            $transitionKey = $targetStatus === SalesOrder::STATUS_CONFIRMED ? 'Commande confirmée.' : 'Commande annulée.';
            $this->recordHistory($locked, $targetStatus, $fromStatus, $targetStatus, __($transitionKey), null, $transitionKey);

            return $locked->fresh(['customer', 'sourceQuote', 'creator', 'items', 'histories']);
        }, 3);
    }

    /**
     * @param  array{customer_id: int, order_date: string, terms: ?string, notes: ?string}  $attributes
     * @param  array<int, array{product_id: int, description?: ?string, ordered_quantity: string, unit_price: string, discount_percent: string, tax_rate_id?: ?int}>  $lines
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>, 2: array<string, string>, 3: Customer}
     */
    private function prepare(array $attributes, array $lines): array
    {
        $attributes = Validator::make($attributes, [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'order_date' => ['required', 'date'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ])->validate();

        $lines = Validator::make(['lines' => $lines], [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.description' => ['nullable', 'string', 'max:5000'],
            'lines.*.ordered_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999999.999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999999.99'],
            'lines.*.discount_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'lines.*.tax_rate_id' => ['sometimes', 'nullable', 'string'],
        ])->validate()['lines'];

        $customer = Customer::query()->findOrFail($attributes['customer_id']);
        $this->ensureCustomerActive($customer);
        $productIds = collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();
        $products = Product::query()->with(['unit', 'taxRate'])->where('is_active', true)->whereKey($productIds)->get()->keyBy('id');
        $defaultTaxRate = TaxRate::query()->where('is_active', true)->where('is_default', true)->first();
        $taxRateIds = collect($lines)->pluck('tax_rate_id')->filter()->reject(fn ($id) => $id === 'none')->map(fn ($id) => (int) $id)
            ->merge($products->pluck('tax_rate_id')->filter()->map(fn ($id) => (int) $id))
            ->merge($defaultTaxRate === null ? [] : [$defaultTaxRate->id])
            ->unique()
            ->values();
        $taxRates = TaxRate::query()->where('is_active', true)->whereKey($taxRateIds)->get()->keyBy('id');
        $prepared = [];

        foreach (array_values($lines) as $position => $line) {
            $product = $products->get((int) $line['product_id']);
            if ($product === null) {
                throw ValidationException::withMessages(["lines.{$position}.product_id" => __('Le produit ou service sélectionné n’est pas actif.')]);
            }

            $taxRateId = ($line['tax_rate_id'] ?? null) === 'none'
                ? null
                : (filled($line['tax_rate_id'] ?? null)
                    ? (int) $line['tax_rate_id']
                    : ($product->tax_rate_id ?? $defaultTaxRate?->id));
            $taxRate = $taxRateId === null ? null : $taxRates->get($taxRateId);
            if ($taxRateId !== null && $taxRate === null) {
                throw ValidationException::withMessages(["lines.{$position}.tax_rate_id" => __('Le taux de TVA sélectionné n’est pas actif.')]);
            }

            try {
                $calculated = $this->calculator->line(
                    (string) $line['ordered_quantity'],
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
                'ordered_quantity' => $calculated['quantity'],
                'delivered_quantity' => '0.000',
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
            'order_date' => Carbon::parse($attributes['order_date'])->toDateString(),
            'terms' => $this->nullableString($attributes['terms'] ?? null),
            'notes' => $this->nullableString($attributes['notes'] ?? null),
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

    /** @return array<string, ?string> */
    private function quoteCustomerSnapshot(Quote $quote): array
    {
        return [
            'customer_name' => $quote->customer_name,
            'customer_trade_name' => $quote->customer_trade_name,
            'customer_address' => $quote->customer_address,
            'customer_city' => $quote->customer_city,
            'customer_country' => $quote->customer_country,
            'customer_email' => $quote->customer_email,
            'customer_phone' => $quote->customer_phone,
            'customer_ice' => $quote->customer_ice,
            'customer_tax_id' => $quote->customer_tax_id,
            'customer_commercial_register' => $quote->customer_commercial_register,
        ];
    }

    /** @param array<string, mixed>|null $metadata */
    private function recordHistory(
        SalesOrder $order,
        string $event,
        ?string $from,
        ?string $to,
        string $description,
        ?array $metadata = null,
        ?string $descriptionKey = null,
        array $descriptionParams = []
    ): void {
        $meta = $metadata ?? [];
        $meta['description_key'] = $descriptionKey ?? $description;
        $meta['description_params'] = $descriptionParams;

        $order->histories()->create([
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => Auth::id(),
            'description' => $description,
            'metadata' => $meta,
            'created_at' => now(),
        ]);
    }

    private function ensureCustomerActive(Customer $customer): void
    {
        if ($customer->status !== 'active') {
            throw ValidationException::withMessages(['customer_id' => __('Le client sélectionné n’est pas actif.')]);
        }
    }

    private function ensureEditable(SalesOrder $order): void
    {
        if (! $order->isEditable()) {
            throw ValidationException::withMessages(['order' => __('Seul un brouillon de commande peut être modifié.')]);
        }
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
