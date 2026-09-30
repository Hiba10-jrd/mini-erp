<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreditNoteManagementService
{
    public function __construct(
        private readonly QuoteCalculator $calculator,
        private readonly DocumentSequenceManagementService $sequences,
    ) {}

    /**
     * @param array{
     *     credit_date?: string,
     *     reason?: ?string,
     *     notes?: ?string,
     *     items?: array<int, array{
     *         invoice_item_id: int|string,
     *         quantity: int|float|string
     *     }>
     * } $attributes
     */
    public function createForInvoice(
        Invoice $invoice,
        array $attributes = []
    ): CreditNote {
        Gate::authorize('invoices.create');

        $attributes = $this->validateHeader($attributes);

        return DB::transaction(
            function () use ($invoice, $attributes): CreditNote {
                $lockedInvoice = Invoice::query()
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureInvoiceEligible($lockedInvoice);

                $sources = $this->lockInvoiceItems(
                    $lockedInvoice
                );

                $requested = $attributes['items']
                    ?? $this->allAvailableItems(
                        $sources,
                        null
                    );

                $prepared = $this->prepareItems(
                    $sources,
                    $requested,
                    null
                );

                $totals = $this->totals($prepared);

                $creditNote = new CreditNote;

                $creditNote->forceFill([
                    'invoice_id' => $lockedInvoice->id,
                    'customer_id' => $lockedInvoice->customer_id,
                    'number' => null,
                    'status' => CreditNote::STATUS_DRAFT,
                    'credit_date' => $attributes['credit_date'],
                    'reason' => $attributes['reason'] ?? null,
                    'notes' => $attributes['notes'] ?? null,

                    'customer_name' => $lockedInvoice->customer_name,
                    'customer_trade_name' => $lockedInvoice->customer_trade_name,
                    'customer_address' => $lockedInvoice->customer_address,
                    'customer_city' => $lockedInvoice->customer_city,
                    'customer_country' => $lockedInvoice->customer_country,
                    'customer_email' => $lockedInvoice->customer_email,
                    'customer_phone' => $lockedInvoice->customer_phone,
                    'customer_ice' => $lockedInvoice->customer_ice,
                    'customer_tax_id' => $lockedInvoice->customer_tax_id,
                    'customer_commercial_register' => $lockedInvoice->customer_commercial_register,

                    ...$totals,

                    'created_by' => Auth::id(),
                ])->save();

                $this->persistItems(
                    $creditNote,
                    $prepared
                );

                return $creditNote->fresh([
                    'invoice',
                    'customer',
                    'items',
                ]);
            },
            3
        );
    }

    /**
     * @param array{
     *     credit_date?: string,
     *     reason?: ?string,
     *     notes?: ?string,
     *     items?: array<int, array{
     *         invoice_item_id: int|string,
     *         quantity: int|float|string
     *     }>
     * } $attributes
     */
    public function updateDraft(
        CreditNote $creditNote,
        array $attributes = []
    ): CreditNote {
        Gate::authorize('invoices.create');

        $attributes = $this->validateHeader(
            $attributes,
            true
        );

        return DB::transaction(
            function () use (
                $creditNote,
                $attributes
            ): CreditNote {
                $locked = CreditNote::query()
                    ->whereKey($creditNote->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureDraft($locked);

                $invoice = Invoice::query()
                    ->whereKey($locked->invoice_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureInvoiceEligible($invoice);

                $sources = $this->lockInvoiceItems(
                    $invoice
                );

                $requested = $attributes['items']
                    ?? $locked->items()
                        ->get([
                            'invoice_item_id',
                            'quantity',
                        ])
                        ->map(
                            fn (
                                CreditNoteItem $item
                            ): array => [
                                'invoice_item_id' => $item->invoice_item_id,

                                'quantity' => (string) $item->quantity,
                            ]
                        )
                        ->all();

                $prepared = $this->prepareItems(
                    $sources,
                    $requested,
                    $locked->id
                );

                $totals = $this->totals($prepared);

                $locked->fill([
                    'credit_date' => $attributes['credit_date']
                            ?? $locked->credit_date
                                ->toDateString(),

                    'reason' => array_key_exists(
                        'reason',
                        $attributes
                    )
                        ? $this->nullableString(
                            $attributes['reason']
                        )
                        : $locked->reason,

                    'notes' => array_key_exists(
                        'notes',
                        $attributes
                    )
                        ? $this->nullableString(
                            $attributes['notes']
                        )
                        : $locked->notes,
                ])->forceFill([
                    ...$totals,
                ])->save();

                $locked->items()
                    ->get()
                    ->each
                    ->delete();

                $this->persistItems(
                    $locked,
                    $prepared
                );

                return $locked->fresh([
                    'invoice',
                    'customer',
                    'items',
                ]);
            },
            3
        );
    }

    public function cancelDraft(
        CreditNote $creditNote
    ): CreditNote {
        Gate::authorize('invoices.create');

        return DB::transaction(
            function () use ($creditNote): CreditNote {
                $locked = CreditNote::query()
                    ->whereKey($creditNote->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureDraft($locked);

                Invoice::query()
                    ->whereKey($locked->invoice_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $locked->forceFill([
                    'status' => CreditNote::STATUS_CANCELLED,

                    'cancelled_at' => now(),
                ])->save();

                return $locked->fresh([
                    'invoice',
                    'customer',
                    'items',
                ]);
            },
            3
        );
    }

    public function issue(
        CreditNote $creditNote
    ): CreditNote {
        Gate::authorize('invoices.validate');

        return DB::transaction(
            function () use ($creditNote): CreditNote {
                $locked = CreditNote::query()
                    ->whereKey($creditNote->id)
                    ->with('items')
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureDraft($locked);

                $invoice = Invoice::query()
                    ->whereKey($locked->invoice_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureInvoiceEligible($invoice);

                $sources = $this->lockInvoiceItems(
                    $invoice
                );

                $requested = $locked->items
                    ->map(
                        fn (
                            CreditNoteItem $item
                        ): array => [
                            'invoice_item_id' => $item->invoice_item_id,

                            'quantity' => (string) $item->quantity,
                        ]
                    )
                    ->all();

                $prepared = $this->prepareItems(
                    $sources,
                    $requested,
                    $locked->id
                );

                $totals = $this->totals($prepared);

                $locked->items()
                    ->get()
                    ->each
                    ->delete();

                $this->persistItems(
                    $locked,
                    $prepared
                );

                $number = $this->sequences->allocate(
                    'credit_note',
                    $locked->credit_date->year
                );

                $locked->forceFill([
                    'number' => $number,
                    'status' => CreditNote::STATUS_ISSUED,

                    ...$totals,

                    'issued_by' => Auth::id(),
                    'issued_at' => now(),
                ])->save();

                return $locked->fresh([
                    'invoice',
                    'customer',
                    'items',
                ]);
            },
            3
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableItemsForInvoice(
        Invoice $invoice,
        ?CreditNote $creditNote = null
    ): array {
        Gate::authorize('invoices.create');

        return DB::transaction(
            function () use (
                $invoice,
                $creditNote
            ): array {
                $lockedInvoice = Invoice::query()
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureInvoiceEligible(
                    $lockedInvoice
                );

                if (
                    $creditNote !== null
                    && $creditNote->invoice_id
                        !== $lockedInvoice->id
                ) {
                    throw ValidationException::withMessages([
                        'credit_note' => __('L’avoir ne correspond pas à la facture.'),
                    ]);
                }

                $excludeId = null;

                if ($creditNote !== null) {
                    $lockedCreditNote =
                        CreditNote::query()
                            ->whereKey(
                                $creditNote->id
                            )
                            ->lockForUpdate()
                            ->firstOrFail();

                    $this->ensureDraft(
                        $lockedCreditNote
                    );

                    $excludeId =
                        $lockedCreditNote->id;
                }

                $sources = $this->lockInvoiceItems(
                    $lockedInvoice
                );

                $remaining =
                    $this->remainingQuantities(
                        $sources,
                        $excludeId
                    );

                return collect($sources)
                    ->map(
                        function (
                            InvoiceItem $item
                        ) use ($remaining): array {
                            $quantity =
                                BigDecimal::of(
                                    (string) $item->quantity
                                )->toScale(3);

                            $available =
                                $remaining[$item->id]
                                    ?? BigDecimal::zero()
                                        ->toScale(3);

                            $credited =
                                $quantity
                                    ->minus($available)
                                    ->toScale(3);

                            return [
                                'invoice_item_id' => $item->id,

                                'reference' => $item->reference,

                                'description' => $item->description,

                                'unit_label' => $item->unit_label,

                                'invoiced_quantity' => (string) $quantity,

                                'already_credited_quantity' => (string) $credited,

                                'remaining_quantity' => (string) $available,

                                'unit_price' => (string) $item->unit_price,

                                'discount_percent' => (string) $item->discount_percent,

                                'tax_rate_percent' => (string) $item->tax_rate_percent,
                            ];
                        }
                    )
                    ->filter(
                        fn (array $line): bool => BigDecimal::of(
                            $line['remaining_quantity']
                        )->isGreaterThan(0)
                    )
                    ->values()
                    ->all();
            },
            3
        );
    }

    /**
     * @return array<int, InvoiceItem>
     */
    private function lockInvoiceItems(
        Invoice $invoice
    ): array {
        return InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->orderBy('position')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  array<int, InvoiceItem>  $sources
     * @return array<int, array{
     *     invoice_item_id:int,
     *     quantity:string
     * }>
     */
    private function allAvailableItems(
        array $sources,
        ?int $excludeCreditNoteId
    ): array {
        $remaining = $this->remainingQuantities(
            $sources,
            $excludeCreditNoteId
        );

        return collect($sources)
            ->map(
                fn (InvoiceItem $item): array => [
                    'invoice_item_id' => $item->id,

                    'quantity' => (string) (
                        $remaining[$item->id]
                        ?? BigDecimal::zero()
                            ->toScale(3)
                    ),
                ]
            )
            ->filter(
                fn (array $line): bool => BigDecimal::of(
                    $line['quantity']
                )->isGreaterThan(0)
            )
            ->values()
            ->all();
    }

    /**
     * @param  array<int, InvoiceItem>  $sources
     * @param array<int, array{
     *     invoice_item_id:int|string,
     *     quantity:int|float|string
     * }> $requested
     * @return array<int, array<string, mixed>>
     */
    private function prepareItems(
        array $sources,
        array $requested,
        ?int $excludeCreditNoteId
    ): array {
        if ($requested === []) {
            throw ValidationException::withMessages([
                'items' => __('Aucune quantité ne reste à créditer.'),
            ]);
        }

        $remaining = $this->remainingQuantities(
            $sources,
            $excludeCreditNoteId
        );

        $seen = [];
        $prepared = [];

        foreach (
            array_values($requested) as $position => $line
        ) {
            $sourceId =
                (int) $line['invoice_item_id'];

            if (isset($seen[$sourceId])) {
                throw ValidationException::withMessages([
                    "items.{$position}.invoice_item_id" => __('Une ligne de facture ne peut apparaître qu’une fois.'),
                ]);
            }

            $seen[$sourceId] = true;

            $source = $sources[$sourceId] ?? null;

            if ($source === null) {
                throw ValidationException::withMessages([
                    "items.{$position}.invoice_item_id" => __('La ligne doit appartenir à cette facture.'),
                ]);
            }

            try {
                $quantity = BigDecimal::of(
                    (string) $line['quantity']
                )->toScale(
                    3,
                    RoundingMode::UNNECESSARY
                );
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    "items.{$position}.quantity" => __('La quantité créditée est invalide.'),
                ]);
            }

            if ($quantity->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages([
                    "items.{$position}.quantity" => __('La quantité doit être supérieure à zéro.'),
                ]);
            }

            $available =
                $remaining[$sourceId]
                    ?? BigDecimal::zero()
                        ->toScale(3);

            if ($quantity->isGreaterThan($available)) {
                throw ValidationException::withMessages([
                    "items.{$position}.quantity" => __('La quantité dépasse le reste créditable.'),
                ]);
            }

            try {
                $calculated = $this->calculator->line(
                    (string) $quantity,
                    (string) $source->unit_price,
                    (string) $source->discount_percent,
                    (string) $source->tax_rate_percent,
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    "items.{$position}" => $exception->getMessage(),
                ]);
            }

            $prepared[] = [
                'invoice_item_id' => $source->id,
                'product_id' => $source->product_id,
                'tax_rate_id' => $source->tax_rate_id,
                'item_type' => $source->item_type,
                'reference' => $source->reference,
                'description' => $source->description,
                'unit_label' => $source->unit_label,

                'quantity' => $calculated['quantity'],

                'unit_price' => $calculated['unit_price'],

                'discount_percent' => $calculated[
                        'discount_percent'
                    ],

                'discount_amount' => $calculated[
                        'discount_amount'
                    ],

                'tax_rate_percent' => $calculated[
                        'tax_rate_percent'
                    ],

                'subtotal_ht' => $calculated['subtotal_ht'],

                'tax_amount' => $calculated['tax_amount'],

                'total_ttc' => $calculated['total_ttc'],

                'position' => $position + 1,

                '_gross_ht' => $calculated['gross_ht'],
            ];
        }

        return $prepared;
    }

    /**
     * @param  array<int, InvoiceItem>  $sources
     * @return array<int, BigDecimal>
     */
    private function remainingQuantities(
        array $sources,
        ?int $excludeCreditNoteId
    ): array {
        $ids = array_keys($sources);

        if ($ids === []) {
            return [];
        }

        $query = DB::table('credit_note_items')
            ->join(
                'credit_notes',
                'credit_notes.id',
                '=',
                'credit_note_items.credit_note_id'
            )
            ->whereIn(
                'credit_note_items.invoice_item_id',
                $ids
            )
            ->where(
                'credit_notes.status',
                '!=',
                CreditNote::STATUS_CANCELLED
            );

        if ($excludeCreditNoteId !== null) {
            $query->where(
                'credit_notes.id',
                '!=',
                $excludeCreditNoteId
            );
        }

        $credited = $query
            ->select(
                'credit_note_items.invoice_item_id',
                DB::raw(
                    'SUM(credit_note_items.quantity) as credited_quantity'
                )
            )
            ->groupBy(
                'credit_note_items.invoice_item_id'
            )
            ->get()
            ->keyBy('invoice_item_id');

        $remaining = [];

        foreach ($sources as $id => $source) {
            $alreadyCredited = (string) (
                $credited->get($id)
                    ?->credited_quantity
                    ?? '0.000'
            );

            $available = BigDecimal::of(
                (string) $source->quantity
            )
                ->minus($alreadyCredited)
                ->toScale(3);

            $remaining[$id] =
                $available->isLessThan(0)
                    ? BigDecimal::zero()
                        ->toScale(3)
                    : $available;
        }

        return $remaining;
    }

    /**
     * @param  array<int, array<string, mixed>>  $prepared
     * @return array{
     *     subtotal_ht:string,
     *     discount_total:string,
     *     tax_total:string,
     *     total_ttc:string
     * }
     */
    private function totals(
        array $prepared
    ): array {
        $totals = $this->calculator->totals(
            array_map(
                fn (array $line): array => [
                    'gross_ht' => $line['_gross_ht'],

                    'discount_amount' => $line['discount_amount'],

                    'subtotal_ht' => $line['subtotal_ht'],

                    'tax_amount' => $line['tax_amount'],

                    'total_ttc' => $line['total_ttc'],
                ],
                $prepared
            )
        );

        return [
            'subtotal_ht' => $totals['subtotal_ht'],

            'discount_total' => $totals['discount_total'],

            'tax_total' => $totals['tax_total'],

            'total_ttc' => $totals['total_ttc'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $prepared
     */
    private function persistItems(
        CreditNote $creditNote,
        array $prepared
    ): void {
        foreach ($prepared as &$line) {
            unset($line['_gross_ht']);
        }

        unset($line);

        $creditNote->items()
            ->createMany($prepared);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     credit_date:string,
     *     reason:?string,
     *     notes:?string,
     *     items?:array
     * }
     */
    private function validateHeader(
        array $attributes,
        bool $allowMissingDate = false
    ): array {
        if (
            ! $allowMissingDate
            && ! array_key_exists('credit_date', $attributes)
        ) {
            $attributes['credit_date'] =
                today()->toDateString();
        }

        $rules = [
            'credit_date' => [
                $allowMissingDate
                    ? 'sometimes'
                    : 'required',
                'date',
            ],

            'reason' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],

            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],

            'items' => [
                'sometimes',
                'array',
            ],

            'items.*.invoice_item_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'items.*.quantity' => [
                'required',
            ],
        ];

        $validated = Validator::make(
            $attributes,
            $rules
        )->validate();

        if (
            array_key_exists(
                'credit_date',
                $validated
            )
        ) {
            $validated['credit_date'] =
                Carbon::parse(
                    $validated['credit_date']
                )->toDateString();
        }

        if (array_key_exists('reason', $validated)) {
            $validated['reason'] =
                $this->nullableString(
                    $validated['reason']
                );
        }

        if (array_key_exists('notes', $validated)) {
            $validated['notes'] =
                $this->nullableString(
                    $validated['notes']
                );
        }

        return $validated;
    }

    private function ensureInvoiceEligible(
        Invoice $invoice
    ): void {
        if (! $invoice->isIssued()) {
            throw ValidationException::withMessages([
                'invoice' => __('Seule une facture émise peut recevoir un avoir.'),
            ]);
        }
    }

    private function ensureDraft(
        CreditNote $creditNote
    ): void {
        if (! $creditNote->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Seul un brouillon d’avoir peut être modifié ou émis.'),
            ]);
        }
    }

    private function nullableString(
        ?string $value
    ): ?string {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}
