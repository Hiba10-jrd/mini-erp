<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SupplierInvoiceManagementService
{
    public function __construct(
        private readonly QuoteCalculator $calculator,
        private readonly DocumentSequenceManagementService $sequences,
    ) {}

    /**
     * Les écarts réels de prix, remise ou TVA entre la commande et la facture du
     * fournisseur sont hors périmètre du LOT 16. Ces valeurs sont toujours
     * reconstruites depuis les snapshots des lignes de commande fournisseur.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createDraft(PurchaseOrder $order, array $attributes): SupplierInvoice
    {
        Gate::authorize('purchases.create');

        return DB::transaction(function () use ($order, $attributes): SupplierInvoice {
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->ensureOrderEligible($lockedOrder);
            Supplier::query()->lockForUpdate()->findOrFail($lockedOrder->supplier_id);

            $header = $this->validateHeader($attributes);
            $this->ensureSupplierReferenceAvailable($lockedOrder->supplier_id, $header['supplier_invoice_number']);
            $sources = $this->lockValidatedSources($lockedOrder);
            $requested = array_key_exists('items', $attributes)
                ? $attributes['items']
                : $this->allAvailableItems($sources, null);
            $prepared = $this->prepareItems($lockedOrder, $sources, $requested, null);
            $totals = $this->totals($prepared);

            $invoice = new SupplierInvoice([
                'purchase_order_id' => $lockedOrder->id,
                'supplier_id' => $lockedOrder->supplier_id,
                'supplier_invoice_number' => $header['supplier_invoice_number'],
                'invoice_date' => $header['invoice_date'],
                'due_date' => $this->dueDate($header, $lockedOrder),
                'notes' => $header['notes'],
            ]);
            $invoice->forceFill([
                'number' => null,
                'status' => SupplierInvoice::STATUS_DRAFT,
                'created_by' => Auth::id(),
                ...$this->supplierSnapshots($lockedOrder),
                ...$totals,
            ])->save();
            $this->persistItems($invoice, $prepared);
            $this->recordHistory($invoice, 'created', null, SupplierInvoice::STATUS_DRAFT, __('Facture fournisseur créée.'));
            $this->recordOrderHistory($lockedOrder, 'supplier_invoice_created', __('Facture fournisseur :reference créée.', ['reference' => $invoice->supplier_invoice_number]), $invoice);

            return $this->load($invoice);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateDraft(SupplierInvoice $invoice, array $attributes): SupplierInvoice
    {
        Gate::authorize('purchases.update');
        $orderId = $this->invoiceOrderId($invoice);

        return DB::transaction(function () use ($invoice, $attributes, $orderId): SupplierInvoice {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);
            $locked = SupplierInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->ensureInvoiceMatchesOrder($locked, $order);
            $this->ensureEditable($locked);
            $this->ensureOrderEligible($order);
            Supplier::query()->lockForUpdate()->findOrFail($order->supplier_id);

            $header = $this->validateHeader($attributes);
            $this->ensureSupplierReferenceAvailable($order->supplier_id, $header['supplier_invoice_number'], $locked->id);
            $sources = $this->lockValidatedSources($order);
            $requested = array_key_exists('items', $attributes)
                ? $attributes['items']
                : $locked->items()->orderBy('position')->get()->map(fn (SupplierInvoiceItem $item): array => [
                    'goods_receipt_item_id' => $item->goods_receipt_item_id,
                    'quantity' => (string) $item->quantity,
                ])->all();
            $prepared = $this->prepareItems($order, $sources, $requested, $locked->id);
            $totals = $this->totals($prepared);

            $locked->fill([
                'supplier_invoice_number' => $header['supplier_invoice_number'],
                'invoice_date' => $header['invoice_date'],
                'due_date' => $this->dueDate($header, $order),
                'notes' => $header['notes'],
            ])->forceFill([
                ...$this->supplierSnapshots($order),
                ...$totals,
            ])->save();
            $locked->items()->get()->each->delete();
            $this->persistItems($locked, $prepared);
            $this->recordHistory($locked, 'draft_updated', SupplierInvoice::STATUS_DRAFT, SupplierInvoice::STATUS_DRAFT, __('Brouillon de facture fournisseur modifié.'));

            return $this->load($locked);
        }, 3);
    }

    public function validate(SupplierInvoice $invoice): SupplierInvoice
    {
        Gate::authorize('purchases.update');
        $orderId = $this->invoiceOrderId($invoice);

        return DB::transaction(function () use ($invoice, $orderId): SupplierInvoice {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);
            $locked = SupplierInvoice::query()->with('items')->lockForUpdate()->findOrFail($invoice->id);
            $this->ensureInvoiceMatchesOrder($locked, $order);
            $this->ensureEditable($locked);
            $this->ensureOrderEligible($order);
            Supplier::query()->lockForUpdate()->findOrFail($order->supplier_id);
            $this->ensureSupplierReferenceAvailable($order->supplier_id, $locked->supplier_invoice_number, $locked->id);

            $sources = $this->lockValidatedSources($order);
            $requested = $locked->items->map(fn (SupplierInvoiceItem $item): array => [
                'goods_receipt_item_id' => $item->goods_receipt_item_id,
                'quantity' => (string) $item->quantity,
            ])->all();
            $prepared = $this->prepareItems($order, $sources, $requested, $locked->id);
            $totals = $this->totals($prepared);

            $locked->items()->get()->each->delete();
            $this->persistItems($locked, $prepared);

            // Le numéro interne n'est consommé qu'après tous les contrôles métier.
            $number = $this->sequences->allocate('supplier_invoice', Carbon::parse($locked->invoice_date)->year);
            $locked->forceFill([
                'number' => $number,
                'status' => SupplierInvoice::STATUS_VALIDATED,
                'validated_by' => Auth::id(),
                'validated_at' => now(),
                ...$totals,
            ])->save();
            $this->recordHistory($locked, 'validated', SupplierInvoice::STATUS_DRAFT, SupplierInvoice::STATUS_VALIDATED, __('Validation de la facture fournisseur.'));
            $this->recordOrderHistory($order, 'supplier_invoice_validated', __('Facture fournisseur :number validée.', ['number' => $number]), $locked);

            return $this->load($locked);
        }, 3);
    }

    public function cancelDraft(SupplierInvoice $invoice): SupplierInvoice
    {
        Gate::authorize('purchases.delete');
        $orderId = $this->invoiceOrderId($invoice);

        return DB::transaction(function () use ($invoice, $orderId): SupplierInvoice {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);
            $locked = SupplierInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->ensureInvoiceMatchesOrder($locked, $order);
            $this->ensureEditable($locked);
            $locked->forceFill([
                'status' => SupplierInvoice::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();
            $this->recordHistory($locked, 'cancelled', SupplierInvoice::STATUS_DRAFT, SupplierInvoice::STATUS_CANCELLED, __('Facture fournisseur annulée.'));
            $this->recordOrderHistory($order, 'supplier_invoice_cancelled', __('Facture fournisseur :reference annulée.', ['reference' => $locked->supplier_invoice_number]), $locked);

            return $this->load($locked);
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    public function availableItemsForOrder(PurchaseOrder $order, ?SupplierInvoice $invoice = null): array
    {
        abort_unless(Gate::allows('purchases.view') || Gate::allows('purchases.create') || Gate::allows('purchases.update'), 403);

        return DB::transaction(function () use ($order, $invoice): array {
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->ensureOrderEligible($lockedOrder);
            $excludeInvoiceId = null;
            if ($invoice !== null) {
                $lockedInvoice = SupplierInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
                $this->ensureInvoiceMatchesOrder($lockedInvoice, $lockedOrder);
                $this->ensureEditable($lockedInvoice);
                $excludeInvoiceId = $lockedInvoice->id;
            }

            $sources = $this->lockValidatedSources($lockedOrder);
            $availability = $this->availability($sources, $excludeInvoiceId);

            return collect($sources)->map(function (GoodsReceiptItem $source) use ($availability): array {
                /** @var PurchaseOrderItem $orderItem */
                $orderItem = $source->getRelation('purchaseOrderItem');
                /** @var GoodsReceipt $receipt */
                $receipt = $source->getRelation('goodsReceipt');
                $values = $availability[$source->id];

                return [
                    'goods_receipt_item_id' => $source->id,
                    'goods_receipt_id' => $source->goods_receipt_id,
                    'goods_receipt_number' => $receipt->number,
                    'goods_receipt_date' => $receipt->receipt_date,
                    'purchase_order_item_id' => $source->purchase_order_item_id,
                    'reference' => $orderItem->reference,
                    'description' => $orderItem->description,
                    'unit_label' => $orderItem->unit_label,
                    'received_quantity' => (string) BigDecimal::of($source->quantity)->toScale(3),
                    'reserved_by_other_drafts' => (string) $values['draft'],
                    'validated_invoiced_quantity' => (string) $values['validated'],
                    'available_quantity' => (string) $values['remaining'],
                    'unit_price' => (string) $orderItem->unit_price,
                    'discount_percent' => (string) $orderItem->discount_percent,
                    'tax_rate_percent' => (string) $orderItem->tax_rate_percent,
                ];
            })->filter(fn (array $line): bool => BigDecimal::of($line['available_quantity'])->isGreaterThan(0))->values()->all();
        }, 3);
    }

    /** @param array<string, mixed> $attributes
     * @return array{supplier_invoice_number: string, invoice_date: string, due_date: ?string, notes: ?string}
     */
    private function validateHeader(array $attributes): array
    {
        $attributes['supplier_invoice_number'] = trim((string) ($attributes['supplier_invoice_number'] ?? ''));
        $validated = Validator::make($attributes, [
            'supplier_invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.goods_receipt_item_id' => ['required_with:items', 'integer', 'min:1'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999999.999'],
        ])->validate();

        return [
            'supplier_invoice_number' => $validated['supplier_invoice_number'],
            'invoice_date' => Carbon::parse($validated['invoice_date'])->toDateString(),
            'due_date' => isset($validated['due_date']) ? Carbon::parse($validated['due_date'])->toDateString() : null,
            'notes' => $this->nullableString($validated['notes'] ?? null),
        ];
    }

    private function dueDate(array $header, PurchaseOrder $order): ?string
    {
        if ($header['due_date'] !== null) {
            return $header['due_date'];
        }

        return $order->payment_term_days === null
            ? null
            : Carbon::parse($header['invoice_date'])->addDays($order->payment_term_days)->toDateString();
    }

    /** @return array<string, mixed> */
    private function supplierSnapshots(PurchaseOrder $order): array
    {
        return [
            'supplier_name' => $order->supplier_name,
            'supplier_trade_name' => $order->supplier_trade_name,
            'supplier_address' => $order->supplier_address,
            'supplier_city' => $order->supplier_city,
            'supplier_country' => $order->supplier_country,
            'supplier_email' => $order->supplier_email,
            'supplier_phone' => $order->supplier_phone,
            'supplier_ice' => $order->supplier_ice,
            'supplier_tax_id' => $order->supplier_tax_id,
            'supplier_commercial_register' => $order->supplier_commercial_register,
            'payment_term_label' => $order->payment_term_label,
            'payment_term_days' => $order->payment_term_days,
        ];
    }

    /** @return array<int, GoodsReceiptItem> */
    private function lockValidatedSources(PurchaseOrder $order): array
    {
        $receipts = GoodsReceipt::query()
            ->where('purchase_order_id', $order->id)
            ->where('status', GoodsReceipt::STATUS_VALIDATED)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $sources = GoodsReceiptItem::query()
            ->whereIn('goods_receipt_id', $receipts->keys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $orderItems = PurchaseOrderItem::query()
            ->where('purchase_order_id', $order->id)
            ->whereIn('id', $sources->pluck('purchase_order_item_id')->unique())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return $sources->filter(fn (GoodsReceiptItem $source): bool => $receipts->has($source->goods_receipt_id) && $orderItems->has($source->purchase_order_item_id))
            ->map(function (GoodsReceiptItem $source) use ($receipts, $orderItems): GoodsReceiptItem {
                $source->setRelation('goodsReceipt', $receipts->get($source->goods_receipt_id));
                $source->setRelation('purchaseOrderItem', $orderItems->get($source->purchase_order_item_id));

                return $source;
            })->keyBy('id')->all();
    }

    /** @param array<int, GoodsReceiptItem> $sources
     * @return array<int, array{goods_receipt_item_id: int, quantity: string}>
     */
    private function allAvailableItems(array $sources, ?int $excludeInvoiceId): array
    {
        $availability = $this->availability($sources, $excludeInvoiceId);

        return collect($sources)->map(fn (GoodsReceiptItem $source): array => [
            'goods_receipt_item_id' => $source->id,
            'quantity' => (string) $availability[$source->id]['remaining'],
        ])->filter(fn (array $line): bool => BigDecimal::of($line['quantity'])->isGreaterThan(0))->values()->all();
    }

    /** @param array<int, GoodsReceiptItem> $sources
     * @return array<int, array<string, mixed>>
     */
    private function prepareItems(PurchaseOrder $order, array $sources, mixed $requested, ?int $excludeInvoiceId): array
    {
        if (! is_array($requested) || $requested === [] || count($requested) > 100) {
            throw ValidationException::withMessages(['items' => __('Une facture fournisseur doit contenir entre 1 et 100 lignes reçues.')]);
        }

        $availability = $this->availability($sources, $excludeInvoiceId);
        $seen = [];
        $prepared = [];

        foreach (array_values($requested) as $position => $line) {
            if (! is_array($line)) {
                throw ValidationException::withMessages(["items.{$position}" => __('La ligne de facture fournisseur est invalide.')]);
            }
            $sourceId = filter_var($line['goods_receipt_item_id'] ?? null, FILTER_VALIDATE_INT);
            if ($sourceId === false || isset($seen[$sourceId])) {
                throw ValidationException::withMessages(["items.{$position}.goods_receipt_item_id" => __('Une ligne de réception valide ne peut apparaître qu’une fois.')]);
            }
            $seen[$sourceId] = true;
            $source = $sources[$sourceId] ?? null;
            if ($source === null) {
                throw ValidationException::withMessages(["items.{$position}.goods_receipt_item_id" => __('La ligne doit appartenir à une réception validée de cette commande fournisseur.')]);
            }

            try {
                $quantity = BigDecimal::of(trim((string) ($line['quantity'] ?? '')))->toScale(3, RoundingMode::UNNECESSARY);
            } catch (\Throwable) {
                throw ValidationException::withMessages(["items.{$position}.quantity" => __('La quantité facturée est invalide.')]);
            }
            if ($quantity->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(["items.{$position}.quantity" => __('La quantité doit être supérieure à zéro.')]);
            }
            if ($quantity->isGreaterThan($availability[$sourceId]['remaining'])) {
                throw ValidationException::withMessages(["items.{$position}.quantity" => __('La quantité dépasse le disponible de la ligne de réception.')]);
            }

            /** @var PurchaseOrderItem $orderItem */
            $orderItem = $source->getRelation('purchaseOrderItem');
            if ($orderItem->purchase_order_id !== $order->id) {
                throw ValidationException::withMessages(["items.{$position}.goods_receipt_item_id" => __('La ligne de réception ne correspond pas à la commande fournisseur.')]);
            }
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
                'goods_receipt_item_id' => $source->id,
                'purchase_order_item_id' => $orderItem->id,
                'product_id' => $orderItem->product_id,
                'tax_rate_id' => $orderItem->tax_rate_id,
                'item_type' => $orderItem->item_type,
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

    /** @param array<int, GoodsReceiptItem> $sources
     * @return array<int, array{draft: BigDecimal, validated: BigDecimal, remaining: BigDecimal}>
     */
    private function availability(array $sources, ?int $excludeInvoiceId): array
    {
        $sourceIds = array_keys($sources);
        if ($sourceIds === []) {
            return [];
        }

        $invoices = SupplierInvoice::query()
            ->where('purchase_order_id', $sources[array_key_first($sources)]->getRelation('purchaseOrderItem')->purchase_order_id)
            ->where('status', '!=', SupplierInvoice::STATUS_CANCELLED)
            ->when($excludeInvoiceId !== null, fn ($query) => $query->whereKeyNot($excludeInvoiceId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $reservedItems = SupplierInvoiceItem::query()
            ->whereIn('supplier_invoice_id', $invoices->keys())
            ->whereIn('goods_receipt_item_id', $sourceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $availability = [];
        foreach ($sources as $sourceId => $source) {
            $draft = BigDecimal::zero()->toScale(3);
            $validated = BigDecimal::zero()->toScale(3);
            foreach ($reservedItems->where('goods_receipt_item_id', $sourceId) as $reservedItem) {
                $status = $invoices->get($reservedItem->supplier_invoice_id)?->status;
                if ($status === SupplierInvoice::STATUS_DRAFT) {
                    $draft = $draft->plus($reservedItem->quantity);
                } elseif ($status === SupplierInvoice::STATUS_VALIDATED) {
                    $validated = $validated->plus($reservedItem->quantity);
                }
            }
            $remaining = BigDecimal::of($source->quantity)->minus($draft)->minus($validated)->toScale(3);
            $availability[$sourceId] = [
                'draft' => $draft,
                'validated' => $validated,
                'remaining' => $remaining->isLessThan(0) ? BigDecimal::zero()->toScale(3) : $remaining,
            ];
        }

        return $availability;
    }

    /** @param array<int, array<string, mixed>> $prepared
     * @return array{subtotal_ht: string, discount_total: string, tax_total: string, total_ttc: string}
     */
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
    private function persistItems(SupplierInvoice $invoice, array $prepared): void
    {
        foreach ($prepared as &$line) {
            unset($line['_gross_ht']);
        }
        unset($line);
        $invoice->items()->createMany($prepared);
    }

    private function ensureSupplierReferenceAvailable(int $supplierId, string $reference, ?int $excludeInvoiceId = null): void
    {
        $exists = SupplierInvoice::query()
            ->where('supplier_id', $supplierId)
            ->where('supplier_invoice_number', $reference)
            ->when($excludeInvoiceId !== null, fn ($query) => $query->whereKeyNot($excludeInvoiceId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'supplier_invoice_number' => __('Cette référence de facture existe déjà pour ce fournisseur.'),
            ]);
        }
    }

    private function ensureOrderEligible(PurchaseOrder $order): void
    {
        if ($order->status !== PurchaseOrder::STATUS_CONFIRMED) {
            throw ValidationException::withMessages(['purchase_order_id' => __('Seule une commande fournisseur confirmée peut être facturée.')]);
        }
    }

    private function ensureInvoiceMatchesOrder(SupplierInvoice $invoice, PurchaseOrder $order): void
    {
        if ($invoice->purchase_order_id !== $order->id || $invoice->supplier_id !== $order->supplier_id) {
            throw ValidationException::withMessages(['supplier_invoice' => __('La facture fournisseur ne correspond pas à la commande.')]);
        }
    }

    private function ensureEditable(SupplierInvoice $invoice): void
    {
        if (! $invoice->isEditable()) {
            throw ValidationException::withMessages(['status' => __('Seul un brouillon de facture fournisseur peut être modifié, validé ou annulé.')]);
        }
    }

    private function invoiceOrderId(SupplierInvoice $invoice): int
    {
        return (int) SupplierInvoice::query()->whereKey($invoice->id)->valueOrFail('purchase_order_id');
    }

    private function recordHistory(SupplierInvoice $invoice, string $event, ?string $from, ?string $to, string $description): void
    {
        $invoice->histories()->create([
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => Auth::id(),
            'description' => $description,
            'created_at' => now(),
        ]);
    }

    private function recordOrderHistory(PurchaseOrder $order, string $event, string $description, SupplierInvoice $invoice): void
    {
        $order->histories()->create([
            'event' => $event,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'user_id' => Auth::id(),
            'description' => $description,
            'metadata' => [
                'supplier_invoice_id' => $invoice->id,
                'supplier_invoice_number' => $invoice->supplier_invoice_number,
                'number' => $invoice->number,
            ],
            'created_at' => now(),
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function load(SupplierInvoice $invoice): SupplierInvoice
    {
        return $invoice->fresh(['purchaseOrder', 'supplier', 'creator', 'validator', 'items.goodsReceiptItem', 'histories']);
    }
}
