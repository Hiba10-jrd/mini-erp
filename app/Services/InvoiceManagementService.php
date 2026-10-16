<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentTerm;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class InvoiceManagementService
{
    public function __construct(
        private readonly QuoteCalculator $calculator,
        private readonly DocumentSequenceManagementService $sequences,
    ) {}

    /**
     * @param  array{invoice_date?: string, notes?: ?string, terms?: ?string, items?: array<int, array{delivery_note_item_id?: int|string|null, sales_order_item_id?: int|string, quantity: int|float|string}>}  $attributes
     */
    public function createForOrder(SalesOrder $order, array $attributes = []): Invoice
    {
        Gate::authorize('invoices.create');
        $attributes = $this->validateHeader($attributes);

        return DB::transaction(function () use ($order, $attributes): Invoice {
            $lockedOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->ensureOrderInvoiceable($lockedOrder);
            $sources = $this->lockBillableSources($lockedOrder);
            $requestedItems = $attributes['items'] ?? $this->allAvailableItems($sources, null);
            $prepared = $this->prepareItems($sources, $requestedItems, null);
            $totals = $this->totals($prepared);
            $header = $this->headerSnapshots($lockedOrder, $attributes);

            $invoice = new Invoice;
            $invoice->forceFill([
                'sales_order_id' => $lockedOrder->id,
                'customer_id' => $lockedOrder->customer_id,
                'invoice_date' => $header['invoice_date'],
                'due_date' => $header['due_date'],
                'notes' => $header['notes'],
                'terms' => $header['terms'],
                'number' => null,
                'status' => Invoice::STATUS_DRAFT,
                ...$header['snapshots'],
                ...$totals,
                'created_by' => Auth::id(),
            ])->save();

            $this->persistItems($invoice, $prepared);

            return $invoice->fresh(['salesOrder', 'customer', 'items']);
        }, 3);
    }

    /**
     * @param  array{invoice_date?: string, notes?: ?string, terms?: ?string, items?: array<int, array{delivery_note_item_id?: int|string|null, sales_order_item_id?: int|string, quantity: int|float|string}>}  $attributes
     */
    public function updateDraft(Invoice $invoice, array $attributes = []): Invoice
    {
        Gate::authorize('invoices.create');
        $attributes = $this->validateHeader($attributes, allowMissingDate: true);
        $orderId = $invoice->sales_order_id;

        return DB::transaction(function () use ($invoice, $attributes, $orderId): Invoice {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->sales_order_id !== $order->id) {
                throw ValidationException::withMessages(['invoice' => __('La facture ne correspond pas à la commande.')]);
            }
            $this->ensureDraft($locked);
            $this->ensureOrderInvoiceable($order);

            $sources = $this->lockBillableSources($order);
            $requestedItems = $attributes['items'] ?? $locked->items()
                ->get(['delivery_note_item_id', 'sales_order_item_id', 'quantity'])
                ->map(fn (InvoiceItem $item): array => [
                    'delivery_note_item_id' => $item->delivery_note_item_id,
                    'sales_order_item_id' => $item->sales_order_item_id,
                    'quantity' => (string) $item->quantity,
                ])
                ->all();
            $prepared = $this->prepareItems($sources, $requestedItems, $locked->id);
            $totals = $this->totals($prepared);
            $header = $this->headerSnapshots($order, $attributes, $locked);

            $locked->fill([
                'invoice_date' => $header['invoice_date'],
                'due_date' => $header['due_date'],
                'notes' => $header['notes'],
                'terms' => $header['terms'],
            ])->forceFill([
                ...$header['snapshots'],
                ...$totals,
            ])->save();

            $locked->items()->get()->each->delete();
            $this->persistItems($locked, $prepared);

            return $locked->fresh(['salesOrder', 'customer', 'items']);
        }, 3);
    }

    public function cancelDraft(Invoice $invoice): Invoice
    {
        Gate::authorize('invoices.create');
        $orderId = $invoice->sales_order_id;

        return DB::transaction(function () use ($invoice, $orderId): Invoice {
            SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->ensureDraft($locked);
            $locked->forceFill([
                'status' => Invoice::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            return $locked->fresh(['salesOrder', 'customer', 'items']);
        }, 3);
    }

    public function issue(Invoice $invoice): Invoice
    {
        Gate::authorize('invoices.validate');
        $orderId = $invoice->sales_order_id;

        return DB::transaction(function () use ($invoice, $orderId): Invoice {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            $locked = Invoice::query()->whereKey($invoice->id)->with('items')->lockForUpdate()->firstOrFail();
            if ($locked->sales_order_id !== $order->id) {
                throw ValidationException::withMessages(['invoice' => __('La facture ne correspond pas à la commande.')]);
            }
            $this->ensureDraft($locked);
            $this->ensureOrderInvoiceable($order);

            $sources = $this->lockBillableSources($order);
            $requestedItems = $locked->items
                ->map(fn (InvoiceItem $item): array => [
                    'delivery_note_item_id' => $item->delivery_note_item_id,
                    'sales_order_item_id' => $item->sales_order_item_id,
                    'quantity' => (string) $item->quantity,
                ])
                ->all();
            $prepared = $this->prepareItems($sources, $requestedItems, $locked->id);
            $totals = $this->totals($prepared);
            $locked->items()->get()->each->delete();
            $this->persistItems($locked, $prepared);

            $number = $this->sequences->allocate('invoice', Carbon::parse($locked->invoice_date)->year);
            $locked->forceFill([
                'number' => $number,
                'status' => Invoice::STATUS_ISSUED,
                'issued_by' => Auth::id(),
                'issued_at' => now(),
                ...$totals,
            ])->save();

            return $locked->fresh(['salesOrder', 'customer', 'items', 'issuer']);
        }, 3);
    }

    /**
     * Retourne les lignes de commande encore facturables pour l'interface.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableItemsForOrder(
        SalesOrder $order,
        ?Invoice $invoice = null
    ): array {
        Gate::authorize('invoices.create');

        return DB::transaction(function () use ($order, $invoice): array {
            $lockedOrder = SalesOrder::query()
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->ensureOrderInvoiceable($lockedOrder);

            if (
                $invoice !== null
                && $invoice->sales_order_id !== $lockedOrder->id
            ) {
                throw ValidationException::withMessages([
                    'invoice' => __('La facture ne correspond pas à la commande.'),
                ]);
            }

            if ($invoice !== null) {
                $lockedInvoice = Invoice::query()
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureDraft($lockedInvoice);
            }

            $sources = $this->lockBillableSources($lockedOrder);

            if ($sources === []) {
                return [];
            }

            $remaining = $this->remainingQuantities(
                $sources,
                $invoice?->id,
            );

            return collect($sources)
                ->map(function (array $source, int|string $key) use ($remaining): array {
                    /** @var SalesOrderItem $orderItem */
                    $orderItem = $source['order_item'];

                    $delivered = BigDecimal::of($source['quantity'])
                        ->toScale(3);

                    $available = $remaining[$key]
                        ?? BigDecimal::zero()->toScale(3);

                    $alreadyReserved = $delivered
                        ->minus($available)
                        ->toScale(3);

                    return [
                        'source_key' => $key,
                        'delivery_note_item_id' => $source['delivery_note_item_id'],
                        'delivery_note_id' => $source['delivery_note_id'],
                        'sales_order_item_id' => $orderItem->id,
                        'item_type' => $source['item_type'],
                        'ordered_quantity' => (string) $orderItem->ordered_quantity,

                        'reference' => $orderItem->reference,
                        'description' => $orderItem->description,
                        'unit_label' => $orderItem->unit_label,

                        'delivered_quantity' => $source['item_type'] === 'product' ? (string) $delivered : '0.000',
                        'already_invoiced_quantity' => (string) $alreadyReserved,
                        'remaining_quantity' => (string) $available,

                        'unit_price' => (string) $orderItem->unit_price,
                        'discount_percent' => (string) $orderItem->discount_percent,
                        'tax_rate_percent' => (string) $orderItem->tax_rate_percent,
                    ];
                })
                ->filter(
                    fn (array $line): bool => BigDecimal::of(
                        $line['remaining_quantity']
                    )->isGreaterThan(0)
                )
                ->values()
                ->all();
        }, 3);
    }

    private function validateHeader(array $attributes, bool $allowMissingDate = false): array
    {
        $input = $attributes;
        if (! array_key_exists('invoice_date', $input)) {
            $input['invoice_date'] = today()->toDateString();
        }

        $rules = [
            'invoice_date' => ['required', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.delivery_note_item_id' => ['nullable', 'required_without:items.*.sales_order_item_id', 'integer', 'min:1'],
            'items.*.sales_order_item_id' => ['nullable', 'required_without:items.*.delivery_note_item_id', 'integer', 'min:1'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999999.999'],
        ];

        if ($allowMissingDate && ! array_key_exists('invoice_date', $attributes)) {
            unset($input['invoice_date']);
            $rules['invoice_date'] = ['sometimes', 'date'];
        }

        return Validator::make($input, $rules)->validate();
    }

    private function ensureOrderInvoiceable(SalesOrder $order): void
    {
        if (! in_array($order->status, [
            SalesOrder::STATUS_CONFIRMED,
            SalesOrder::STATUS_PARTIALLY_DELIVERED,
            SalesOrder::STATUS_DELIVERED,
        ], true)) {
            throw ValidationException::withMessages(['sales_order_id' => __('Seule une commande confirmée peut être facturée.')]);
        }
    }

    /**
     * The caller holds the order lock, serializing invoice reservations.
     * Products keep their validated delivery source; services use their order line.
     *
     * @return array<int|string, array{order_item: SalesOrderItem, delivery_note_item_id: ?int, delivery_note_id: ?int, item_type: string, quantity: string, legacy_delivery_note_item_ids?: array<int, int>}>
     */
    private function lockBillableSources(SalesOrder $order): array
    {
        $orderItems = SalesOrderItem::query()->with('product')
            ->where('sales_order_id', $order->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $deliveryNoteIds = DeliveryNote::query()
            ->where('sales_order_id', $order->id)->where('status', 'validated')
            ->orderBy('id')->lockForUpdate()->pluck('id');
        $deliveries = DeliveryNoteItem::query()->whereIn('delivery_note_id', $deliveryNoteIds)
            ->orderBy('id')->lockForUpdate()->get();

        $sources = [];
        foreach ($deliveries as $delivery) {
            $item = $orderItems->get($delivery->sales_order_item_id);
            if ($item !== null && $item->isPhysical() && $delivery->item_type === 'product' && $delivery->product_id === $item->product_id) {
                $sources[$delivery->id] = [
                    'order_item' => $item,
                    'delivery_note_item_id' => $delivery->id,
                    'delivery_note_id' => $delivery->delivery_note_id,
                    'item_type' => 'product',
                    'quantity' => (string) $delivery->quantity,
                ];
            }
        }
        foreach ($orderItems as $item) {
            $isService = $item->product_id !== null ? $item->product?->isService() === true : $item->item_type === 'service';
            if ($isService) {
                $sources['service-'.$item->id] = [
                    'order_item' => $item,
                    'delivery_note_item_id' => null,
                    'delivery_note_id' => null,
                    'item_type' => 'service',
                    'quantity' => (string) $item->ordered_quantity,
                    'legacy_delivery_note_item_ids' => $deliveries
                        ->filter(fn (DeliveryNoteItem $line): bool => $line->sales_order_item_id === $item->id && $line->item_type === 'service' && $line->product_id === $item->product_id)
                        ->pluck('id')->all(),
                ];
            }
        }

        return $sources;
    }

    /** @param array<int|string, array<string, mixed>> $sources
     * @return array<int, array{delivery_note_item_id: ?int, sales_order_item_id: int, quantity: string}>
     */
    private function allAvailableItems(array $sources, ?int $excludeInvoiceId): array
    {
        $remaining = $this->remainingQuantities($sources, $excludeInvoiceId);

        return collect($sources)
            ->map(fn (array $source, int|string $key): array => [
                'delivery_note_item_id' => $source['delivery_note_item_id'],
                'sales_order_item_id' => $source['order_item']->id,
                'quantity' => (string) ($remaining[$key] ?? BigDecimal::zero()->toScale(3)),
            ])
            ->filter(fn (array $line): bool => BigDecimal::of($line['quantity'])->isGreaterThan(0))
            ->values()
            ->all();
    }

    /** @param array<int|string, array<string, mixed>> $sources
     * @param  array<int, array{delivery_note_item_id?: int|string|null, sales_order_item_id?: int|string, quantity: int|float|string}>  $requested
     * @return array<int, array<string, mixed>>
     */
    private function prepareItems(array $sources, array $requested, ?int $excludeInvoiceId): array
    {
        if ($requested === []) {
            throw ValidationException::withMessages(['items' => __('Aucune quantité ne reste à facturer.')]);
        }

        $remaining = $this->remainingQuantities($sources, $excludeInvoiceId);
        $seen = [];
        $prepared = [];

        foreach (array_values($requested) as $position => $line) {
            $sourceId = isset($line['delivery_note_item_id'])
                ? (int) $line['delivery_note_item_id']
                : 'service-'.(int) ($line['sales_order_item_id'] ?? 0);
            if (isset($line['delivery_note_item_id']) && ! isset($sources[$sourceId])) {
                foreach ($sources as $key => $candidate) {
                    if (in_array($sourceId, $candidate['legacy_delivery_note_item_ids'] ?? [], true)) {
                        $sourceId = $key;
                        break;
                    }
                }
            }
            $sourceField = isset($line['delivery_note_item_id']) ? 'delivery_note_item_id' : 'sales_order_item_id';
            if (isset($seen[$sourceId])) {
                throw ValidationException::withMessages(["items.{$position}.{$sourceField}" => __('Une même source de facturation ne peut apparaître qu’une fois.')]);
            }
            $seen[$sourceId] = true;

            $source = $sources[$sourceId] ?? null;
            $available = $remaining[$sourceId] ?? BigDecimal::zero()->toScale(3);
            if ($source === null || (isset($line['sales_order_item_id']) && (int) $line['sales_order_item_id'] !== $source['order_item']->id)) {
                throw ValidationException::withMessages(["items.{$position}.{$sourceField}" => __('La ligne doit être un service de cette commande ou provenir d’un BL produit validé de cette commande.')]);
            }

            try {
                $quantity = BigDecimal::of((string) $line['quantity'])->toScale(3, RoundingMode::UNNECESSARY);
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages(["items.{$position}.quantity" => __('La quantité facturée est invalide.')]);
            }

            if ($quantity->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(["items.{$position}.quantity" => __('La quantité doit être supérieure à zéro.')]);
            }
            if ($quantity->isGreaterThan($available)) {
                throw ValidationException::withMessages(["items.{$position}.quantity" => __('La quantité dépasse le restant facturable.')]);
            }

            /** @var SalesOrderItem $orderItem */
            $orderItem = $source['order_item'];
            try {
                $calculated = $this->calculator->line(
                    (string) $quantity,
                    (string) $orderItem->unit_price,
                    (string) $orderItem->discount_percent,
                    (string) $orderItem->tax_rate_percent,
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(["items.{$position}" => $exception->getMessage()]);
            }

            $prepared[] = [
                'sales_order_item_id' => $orderItem->id,
                'delivery_note_item_id' => isset($line['delivery_note_item_id']) ? (int) $line['delivery_note_item_id'] : $source['delivery_note_item_id'],
                'product_id' => $orderItem->product_id,
                'tax_rate_id' => $orderItem->tax_rate_id,
                'item_type' => $source['item_type'],
                'reference' => $orderItem->reference,
                'description' => $orderItem->description,
                'unit_label' => $orderItem->unit_label,
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

        return $prepared;
    }

    /** @param array<int|string, array<string, mixed>> $sources
     * @return array<int|string, BigDecimal>
     */
    private function remainingQuantities(array $sources, ?int $excludeInvoiceId): array
    {
        if ($sources === []) {
            return [];
        }

        // Drafts reserve quantities. Legacy service invoices linked to a BL also count.
        $billedQuery = InvoiceItem::query()
            ->whereIn('sales_order_item_id', collect($sources)->pluck('order_item.id')->unique())
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', Invoice::STATUS_CANCELLED));
        if ($excludeInvoiceId !== null) {
            $billedQuery->where('invoice_id', '!=', $excludeInvoiceId);
        }
        $billed = $billedQuery->orderBy('id')->lockForUpdate()->get();

        $billedByOrder = [];
        $billedByDelivery = [];
        foreach ($billed as $line) {
            $orderItemId = $line->sales_order_item_id;
            $billedByOrder[$orderItemId] = ($billedByOrder[$orderItemId] ?? BigDecimal::zero())->plus((string) $line->quantity);
            if ($line->delivery_note_item_id !== null) {
                $deliveryItemId = $line->delivery_note_item_id;
                $billedByDelivery[$deliveryItemId] = ($billedByDelivery[$deliveryItemId] ?? BigDecimal::zero())->plus((string) $line->quantity);
            }
        }

        $productBudgets = [];
        foreach ($sources as $source) {
            if ($source['item_type'] === 'product') {
                $orderItemId = $source['order_item']->id;
                $productBudgets[$orderItemId] = ($productBudgets[$orderItemId] ?? BigDecimal::zero())->plus($source['quantity']);
            }
        }
        foreach ($productBudgets as $orderItemId => $delivered) {
            $productBudgets[$orderItemId] = $delivered->minus($billedByOrder[$orderItemId] ?? BigDecimal::zero());
        }

        $remaining = [];
        foreach ($sources as $sourceId => $source) {
            $orderItemId = $source['order_item']->id;
            $reserved = $source['item_type'] === 'service'
                ? ($billedByOrder[$orderItemId] ?? BigDecimal::zero())
                : ($billedByDelivery[$source['delivery_note_item_id']] ?? BigDecimal::zero());
            $available = BigDecimal::of($source['quantity'])->minus($reserved)->toScale(3);
            if ($source['item_type'] === 'product') {
                // Also cap by the order line, including any legacy invoice without a delivery link.
                if ($available->isGreaterThan($productBudgets[$orderItemId])) {
                    $available = $productBudgets[$orderItemId];
                }
                $available = $available->isLessThan(0) ? BigDecimal::zero()->toScale(3) : $available;
                $productBudgets[$orderItemId] = $productBudgets[$orderItemId]->minus($available);
            }
            $remaining[$sourceId] = $available->isLessThan(0) ? BigDecimal::zero()->toScale(3) : $available->toScale(3);
        }

        return $remaining;
    }

    /** @param array<int, array<string, mixed>> $prepared */
    private function totals(array $prepared): array
    {
        $totals = $this->calculator->totals(array_map(fn (array $line): array => [
            'gross_ht' => $line['_gross_ht'],
            'discount_amount' => $line['discount_amount'],
            'subtotal_ht' => $line['subtotal_ht'],
            'tax_amount' => $line['tax_amount'],
            'total_ttc' => $line['total_ttc'],
        ], $prepared));

        return [
            'subtotal_ht' => $totals['subtotal_ht'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'total_ttc' => $totals['total_ttc'],
        ];
    }

    /** @param array<int, array<string, mixed>> $prepared */
    private function persistItems(Invoice $invoice, array $prepared): void
    {
        foreach ($prepared as &$line) {
            unset($line['_gross_ht']);
        }
        unset($line);

        $invoice->items()->createMany($prepared);
    }

    /** @return array{invoice_date: string, due_date: ?string, notes: ?string, terms: ?string, snapshots: array<string, mixed>} */
    private function headerSnapshots(SalesOrder $order, array $attributes, ?Invoice $invoice = null): array
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($order->customer_id);
        $paymentTerm = $customer->payment_term_id === null
            ? null
            : PaymentTerm::query()->lockForUpdate()->find($customer->payment_term_id);
        if ($paymentTerm !== null && ! $paymentTerm->is_active) {
            $paymentTerm = null;
        }

        $company = Company::query()->where('singleton', true)->lockForUpdate()->first();
        if ($company === null || trim((string) $company->legal_name) === '') {
            throw ValidationException::withMessages(['company' => __('Les coordonnées de l’entreprise doivent être configurées avant de créer une facture.')]);
        }

        $invoiceDate = Carbon::parse($attributes['invoice_date'] ?? $invoice?->invoice_date ?? today())->startOfDay();
        $dueDate = $paymentTerm === null ? null : $invoiceDate->copy()->addDays($paymentTerm->due_days)->toDateString();
        $snapshotFields = [
            'customer_name' => $order->customer_name,
            'customer_trade_name' => $order->customer_trade_name,
            'customer_address' => $order->customer_address,
            'customer_city' => $order->customer_city,
            'customer_country' => $order->customer_country,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'customer_ice' => $order->customer_ice,
            'customer_tax_id' => $order->customer_tax_id,
            'customer_commercial_register' => $order->customer_commercial_register,
            'payment_term_label' => $paymentTerm?->label,
            'payment_term_days' => $paymentTerm?->due_days,
            'company_legal_name' => $company->legal_name,
            'company_trade_name' => $company->trade_name,
            'company_address' => $company->address,
            'company_city' => $company->getAttribute('city'),
            'company_country' => $company->getAttribute('country'),
            'company_ice' => $company->ice,
            'company_tax_id' => $company->tax_id,
            'company_commercial_register' => $company->commercial_register,
            'company_phone' => $company->phone,
            'company_email' => $company->email,
        ];

        return [
            'invoice_date' => $invoiceDate->toDateString(),
            'due_date' => $dueDate,
            'notes' => array_key_exists('notes', $attributes) ? $this->nullableString($attributes['notes']) : ($invoice?->notes),
            'terms' => array_key_exists('terms', $attributes) ? $this->nullableString($attributes['terms']) : ($invoice?->terms ?? $order->terms),
            'snapshots' => $snapshotFields,
        ];
    }

    private function ensureDraft(Invoice $invoice): void
    {
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => __('Seul un brouillon de facture peut être modifié ou émis.')]);
        }
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
