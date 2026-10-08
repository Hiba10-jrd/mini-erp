<?php

namespace App\Services;

use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\TaxRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PurchaseOrderManagementService
{
    public function __construct(
        private readonly QuoteCalculator $calculator,
        private readonly DocumentSequenceManagementService $sequences,
    ) {}

    /**
     * @param  array{supplier_id: int, payment_term_id?: ?int, order_date: string, expected_date?: ?string, terms?: ?string, notes?: ?string}  $attributes
     * @param  array<int, array{product_id: int, description?: ?string, quantity: string, unit_price: string, discount_percent: string, tax_rate_id?: ?int}>  $lines
     */
    public function create(array $attributes, array $lines): PurchaseOrder
    {
        Gate::authorize('purchases.create');
        [$header, $preparedLines, $totals, $supplier, $paymentTerm] = $this->prepare($attributes, $lines);

        return DB::transaction(function () use ($header, $preparedLines, $totals, $supplier, $paymentTerm): PurchaseOrder {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $this->ensureSupplierActive($supplier);
            $number = $this->sequences->allocate('purchase_order', Carbon::parse($header['order_date'])->year);

            $order = new PurchaseOrder($header);
            $order->forceFill([
                'number' => $number,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'subtotal_ht' => $totals['subtotal_ht'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'total_ttc' => $totals['total_ttc'],
                'created_by' => Auth::id(),
                ...$this->supplierSnapshot($supplier),
                ...$this->paymentTermSnapshot($paymentTerm),
            ])->save();
            $order->items()->createMany($preparedLines);
            $this->recordHistory($order, 'created', null, PurchaseOrder::STATUS_DRAFT, __('Commande fournisseur créée.'), null, 'Commande fournisseur créée.');

            return $this->load($order);
        }, 3);
    }

    /**
     * @param  array{supplier_id: int, payment_term_id?: ?int, order_date: string, expected_date?: ?string, terms?: ?string, notes?: ?string}  $attributes
     * @param  array<int, array{product_id: int, description?: ?string, quantity: string, unit_price: string, discount_percent: string, tax_rate_id?: ?int}>  $lines
     */
    public function updateDraft(PurchaseOrder $order, array $attributes, array $lines): PurchaseOrder
    {
        Gate::authorize('purchases.update');
        [$header, $preparedLines, $totals, $supplier, $paymentTerm] = $this->prepare($attributes, $lines);

        return DB::transaction(function () use ($order, $header, $preparedLines, $totals, $supplier, $paymentTerm): PurchaseOrder {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->ensureEditable($locked);
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $this->ensureSupplierActive($supplier);

            $locked->fill($header)->forceFill([
                'subtotal_ht' => $totals['subtotal_ht'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'total_ttc' => $totals['total_ttc'],
                ...$this->supplierSnapshot($supplier),
                ...$this->paymentTermSnapshot($paymentTerm),
            ])->save();
            $locked->items()->get()->each->delete();
            $locked->items()->createMany($preparedLines);
            $this->recordHistory($locked, 'draft_updated', PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_DRAFT, __('Brouillon de commande fournisseur modifié.'), null, 'Brouillon de commande fournisseur modifié.');

            return $this->load($locked);
        }, 3);
    }

    public function confirm(PurchaseOrder $order): PurchaseOrder
    {
        Gate::authorize('purchases.update');

        return DB::transaction(function () use ($order): PurchaseOrder {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => __('Seul un brouillon peut être confirmé.')]);
            }
            if (! $locked->items()->exists()) {
                throw ValidationException::withMessages(['order' => __('Une commande sans ligne ne peut pas être confirmée.')]);
            }

            $locked->forceFill([
                'status' => PurchaseOrder::STATUS_CONFIRMED,
                'confirmed_by' => Auth::id(),
                'confirmed_at' => now(),
            ])->save();
            $this->recordHistory($locked, 'confirmed', PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CONFIRMED, __('Commande fournisseur confirmée.'), null, 'Commande fournisseur confirmée.');

            return $this->load($locked);
        }, 3);
    }

    public function cancelDraft(PurchaseOrder $order): PurchaseOrder
    {
        Gate::authorize('purchases.delete');

        return DB::transaction(function () use ($order): PurchaseOrder {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => __('Seul un brouillon peut être annulé.')]);
            }

            $locked->forceFill([
                'status' => PurchaseOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();
            $this->recordHistory($locked, 'cancelled', PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CANCELLED, __('Commande fournisseur annulée.'), null, 'Commande fournisseur annulée.');

            return $this->load($locked);
        }, 3);
    }

    /** @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>, 2: array<string, string>, 3: Supplier, 4: ?PaymentTerm} */
    private function prepare(array $attributes, array $lines): array
    {
        $attributes = Validator::make($attributes, [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'payment_term_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ])->validate();
        $lines = Validator::make(['lines' => $lines], [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.description' => ['nullable', 'string', 'max:5000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999999.999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999999.99'],
            'lines.*.discount_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'lines.*.tax_rate_id' => ['sometimes', 'nullable', 'string'],
        ])->validate()['lines'];

        $supplier = Supplier::query()->findOrFail($attributes['supplier_id']);
        $this->ensureSupplierActive($supplier);
        $paymentTerm = filled($attributes['payment_term_id'] ?? null)
            ? PaymentTerm::query()->where('is_active', true)->find($attributes['payment_term_id'])
            : null;
        if (filled($attributes['payment_term_id'] ?? null) && $paymentTerm === null) {
            throw ValidationException::withMessages(['payment_term_id' => __('La condition de paiement sélectionnée n’est pas active.')]);
        }

        $productIds = collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();
        $products = Product::query()->with(['unit', 'taxRate'])->where('is_active', true)->whereKey($productIds)->get()->keyBy('id');
        $defaultTaxRate = TaxRate::query()->where('is_active', true)->where('is_default', true)->first();
        $taxRateIds = collect($lines)->pluck('tax_rate_id')->filter()->reject(fn ($id) => $id === 'none')->map(fn ($id) => (int) $id)
            ->merge($products->pluck('tax_rate_id')->filter()->map(fn ($id) => (int) $id))
            ->merge($defaultTaxRate === null ? [] : [$defaultTaxRate->id])->unique()->values();
        $taxRates = TaxRate::query()->where('is_active', true)->whereKey($taxRateIds)->get()->keyBy('id');
        $prepared = [];

        foreach (array_values($lines) as $position => $line) {
            $product = $products->get((int) $line['product_id']);
            if ($product === null) {
                throw ValidationException::withMessages(["lines.{$position}.product_id" => __('Le produit ou service sélectionné n’est pas actif.')]);
            }
            $taxRateId = ($line['tax_rate_id'] ?? null) === 'none'
                ? null
                : (filled($line['tax_rate_id'] ?? null) ? (int) $line['tax_rate_id'] : ($product->tax_rate_id ?? $defaultTaxRate?->id));
            $taxRate = $taxRateId === null ? null : $taxRates->get($taxRateId);
            if ($taxRateId !== null && $taxRate === null) {
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
                'tax_rate_id' => $taxRate?->id,
                'item_type' => $product->type,
                'reference' => $product->reference,
                'description' => $description !== '' ? $description : $product->name,
                'unit_label' => $product->unit?->symbol,
                'quantity' => $calculated['quantity'],
                'unit_price' => $calculated['unit_price'],
                'discount_percent' => $calculated['discount_percent'],
                'discount_amount' => $calculated['discount_amount'],
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
            'supplier_id' => $supplier->id,
            'payment_term_id' => $paymentTerm?->id,
            'order_date' => Carbon::parse($attributes['order_date'])->toDateString(),
            'expected_date' => filled($attributes['expected_date'] ?? null) ? Carbon::parse($attributes['expected_date'])->toDateString() : null,
            'terms' => $this->nullableString($attributes['terms'] ?? null),
            'notes' => $this->nullableString($attributes['notes'] ?? null),
        ], $prepared, $totals, $supplier, $paymentTerm];
    }

    /** @return array<string, ?string> */
    private function supplierSnapshot(Supplier $supplier): array
    {
        return [
            'supplier_name' => $supplier->name,
            'supplier_trade_name' => $supplier->trade_name,
            'supplier_address' => $supplier->address,
            'supplier_city' => $supplier->city,
            'supplier_country' => $supplier->country,
            'supplier_email' => $supplier->email,
            'supplier_phone' => $supplier->phone,
            'supplier_ice' => $supplier->ice,
            'supplier_tax_id' => $supplier->tax_id,
            'supplier_commercial_register' => $supplier->commercial_register,
        ];
    }

    /** @return array{payment_term_label: ?string, payment_term_days: ?int} */
    private function paymentTermSnapshot(?PaymentTerm $paymentTerm): array
    {
        return [
            'payment_term_label' => $paymentTerm?->label,
            'payment_term_days' => $paymentTerm?->due_days,
        ];
    }

    /** @param array<string, mixed>|null $metadata */
    private function recordHistory(
        PurchaseOrder $order,
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

    private function load(PurchaseOrder $order): PurchaseOrder
    {
        return $order->fresh(['supplier', 'paymentTerm', 'creator', 'confirmedBy', 'items', 'histories.user']);
    }

    private function ensureSupplierActive(Supplier $supplier): void
    {
        if ($supplier->status !== 'active') {
            throw ValidationException::withMessages(['supplier_id' => __('Le fournisseur sélectionné n’est pas actif.')]);
        }
    }

    private function ensureEditable(PurchaseOrder $order): void
    {
        if (! $order->isEditable()) {
            throw ValidationException::withMessages(['order' => __('Seul un brouillon de commande fournisseur peut être modifié.')]);
        }
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
