<?php

use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Services\QuoteCalculator;
use App\Services\SupplierInvoiceManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public ?int $invoiceId = null;

    #[Locked]
    public ?int $fixedOrderId = null;

    public string $purchaseOrderId = '';

    public string $supplierInvoiceNumber = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $notes = '';

    /** @var array<int, array<string, mixed>> */
    public array $lines = [];

    public function mount(?int $invoiceId = null, ?int $orderId = null): void
    {
        $this->invoiceDate = today()->toDateString();

        if ($invoiceId !== null) {
            Gate::authorize('purchases.update');
            $invoice = SupplierInvoice::query()->with(['items', 'purchaseOrder'])->findOrFail($invoiceId);
            abort_unless($invoice->isEditable(), 403);

            $this->invoiceId = $invoice->id;
            $this->fixedOrderId = $invoice->purchase_order_id;
            $this->purchaseOrderId = (string) $invoice->purchase_order_id;
            $this->supplierInvoiceNumber = $invoice->supplier_invoice_number;
            $this->invoiceDate = $invoice->invoice_date->toDateString();
            $this->dueDate = $invoice->due_date?->toDateString() ?? '';
            $this->notes = $invoice->notes ?? '';
            $this->initializeLines(
                $invoice->purchaseOrder,
                app(SupplierInvoiceManagementService::class),
                $invoice,
            );

            return;
        }

        Gate::authorize('purchases.create');
        if ($orderId !== null) {
            $order = PurchaseOrder::query()->findOrFail($orderId);
            abort_unless($order->status === PurchaseOrder::STATUS_CONFIRMED, 422);
            $this->fixedOrderId = $order->id;
            $this->purchaseOrderId = (string) $order->id;
            $this->initializeLines($order, app(SupplierInvoiceManagementService::class));
        }
    }

    public function updatedPurchaseOrderId(SupplierInvoiceManagementService $service): void
    {
        $this->authorizeMutation();
        abort_if($this->fixedOrderId !== null, 403);
        $this->lines = [];

        if ($this->purchaseOrderId !== '') {
            $order = PurchaseOrder::query()->findOrFail($this->purchaseOrderId);
            abort_unless($order->status === PurchaseOrder::STATUS_CONFIRMED, 422);
            $this->initializeLines($order, $service);
        }
    }

    public function save(SupplierInvoiceManagementService $service): void
    {
        $this->authorizeMutation();
        $order = $this->resolveOrder();
        if ($order === null) {
            $this->addError('purchase_order_id', __('Sélectionnez une commande fournisseur confirmée.'));

            return;
        }

        $items = collect($this->lines)
            ->filter(fn (array $line): bool => (bool) ($line['selected'] ?? false))
            ->map(fn (array $line): array => [
                'goods_receipt_item_id' => $line['goods_receipt_item_id'],
                'quantity' => $line['quantity'],
            ])->values()->all();

        if ($items === []) {
            $this->addError('items', __('Sélectionnez au moins une ligne de réception à facturer.'));

            return;
        }

        $attributes = [
            'supplier_invoice_number' => $this->supplierInvoiceNumber,
            'invoice_date' => $this->invoiceDate,
            'due_date' => $this->dueDate === '' ? null : $this->dueDate,
            'notes' => $this->notes,
            'items' => $items,
        ];

        $invoice = $this->invoiceId === null
            ? $service->createDraft($order, $attributes)
            : $service->updateDraft(SupplierInvoice::query()->findOrFail($this->invoiceId), $attributes);

        session()->flash('status', $this->invoiceId === null
            ? __('La facture fournisseur brouillon a été créée.')
            : __('Le brouillon de facture fournisseur a été mis à jour.'));
        $this->redirectRoute('purchases.invoices.show', ['supplierInvoice' => $invoice], navigate: true);
    }

    public function with(QuoteCalculator $calculator, SupplierInvoiceManagementService $service): array
    {
        $this->authorizeMutation();
        $order = $this->resolveOrder();
        $calculatedLines = [];

        foreach ($this->lines as $sourceId => $line) {
            if (! ($line['selected'] ?? false)) {
                continue;
            }
            try {
                $calculatedLines[$sourceId] = $calculator->line(
                    (string) $line['quantity'],
                    (string) $line['unit_price'],
                    (string) $line['discount_percent'],
                    (string) $line['tax_rate_percent'],
                );
            } catch (\Throwable) {
                $calculatedLines[$sourceId] = null;
            }
        }

        $orders = collect();
        if ($this->fixedOrderId === null) {
            $orders = PurchaseOrder::query()
                ->where('status', PurchaseOrder::STATUS_CONFIRMED)
                ->latest('order_date')
                ->get()
                ->filter(fn (PurchaseOrder $candidate): bool => $service->availableItemsForOrder($candidate) !== []);
        }

        return [
            'order' => $order,
            'orders' => $orders,
            'calculatedLines' => $calculatedLines,
            'previewTotals' => $calculator->totals(collect($calculatedLines)->filter()->values()->all()),
        ];
    }

    private function initializeLines(
        PurchaseOrder $order,
        SupplierInvoiceManagementService $service,
        ?SupplierInvoice $invoice = null,
    ): void {
        $available = $service->availableItemsForOrder($order, $invoice);
        abort_if($available === [], 422, __('Aucune quantité reçue ne reste à facturer.'));
        $current = $invoice?->items->keyBy('goods_receipt_item_id') ?? collect();

        foreach ($available as $line) {
            $sourceId = (int) $line['goods_receipt_item_id'];
            $currentItem = $current->get($sourceId);
            $this->lines[$sourceId] = [
                'goods_receipt_item_id' => $sourceId,
                'selected' => $invoice === null || $currentItem !== null,
                'quantity' => $currentItem?->quantity ?? ($invoice === null ? $line['available_quantity'] : '0.000'),
                ...$line,
            ];
        }
    }

    private function authorizeMutation(): void
    {
        Gate::authorize($this->invoiceId === null ? 'purchases.create' : 'purchases.update');
        if ($this->invoiceId !== null) {
            abort_unless(SupplierInvoice::query()->findOrFail($this->invoiceId)->isEditable(), 403);
        }
    }

    private function resolveOrder(): ?PurchaseOrder
    {
        $orderId = $this->fixedOrderId ?? filter_var($this->purchaseOrderId, FILTER_VALIDATE_INT);
        if ($orderId === false || $orderId === null) {
            return null;
        }

        return PurchaseOrder::query()->findOrFail($orderId);
    }
}; ?>

<section class="space-y-6">
    <div class="flex justify-end">
        <a href="{{ route('purchases.invoices.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:text-indigo-900">{{ __('Retour aux factures fournisseurs') }}</a>
    </div>

    @if ($errors->any())
        <div class="border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
            <ul class="list-disc space-y-1 ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
            <h3 class="text-base font-semibold text-gray-900">{{ __('Informations de la facture fournisseur') }}</h3>
            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2">
                    <x-input-label for="supplier-invoice-order" :value="__('Commande fournisseur confirmée')" />
                    @if ($fixedOrderId !== null)
                        <x-text-input id="supplier-invoice-order" value="{{ $order?->number }} · {{ $order?->supplier_name }}" disabled class="mt-1 block w-full bg-gray-50" />
                    @else
                        <select id="supplier-invoice-order" wire:model.live="purchaseOrderId" required class="mt-1 block w-full border-gray-300 shadow-sm">
                            <option value="">{{ __('Sélectionner une commande') }}</option>
                            @foreach($orders as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->number }} · {{ $candidate->supplier_name }}</option>@endforeach
                        </select>
                    @endif
                    <x-input-error :messages="$errors->get('purchase_order_id')" class="mt-2" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="supplier-invoice-reference" :value="__('Numéro de facture fournisseur')" />
                    <x-text-input id="supplier-invoice-reference" wire:model="supplierInvoiceNumber" required maxlength="100" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('supplier_invoice_number')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="supplier-invoice-date" :value="__('Date de facture')" />
                    <x-text-input id="supplier-invoice-date" type="date" wire:model="invoiceDate" required class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('invoice_date')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="supplier-invoice-due-date" :value="__('Échéance')" />
                    <x-text-input id="supplier-invoice-due-date" type="date" wire:model="dueDate" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('due_date')" class="mt-2" />
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <x-input-label for="supplier-invoice-notes" :value="__('Notes')" />
                    <textarea id="supplier-invoice-notes" wire:model="notes" rows="3" class="mt-1 block w-full border-gray-300 shadow-sm"></textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                </div>
            </div>
        </section>

        @if ($order)
            <section class="border-y border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-5 py-4 sm:px-6">
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Réceptions à facturer') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Prix, remise et TVA proviennent de la commande fournisseur et sont en lecture seule.') }}</p>
                </div>
                <x-input-error :messages="$errors->get('items')" class="mx-5 mt-3" />
                <div class="overflow-x-auto">
                    <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-start text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-3">{{ __('Inclure') }}</th>
                                <th class="px-4 py-3">{{ __('Réception source') }}</th>
                                <th class="px-4 py-3">{{ __('Article') }}</th>
                                <th class="px-4 py-3 text-end">{{ __('Reçue') }}</th>
                                <th class="px-4 py-3 text-end">{{ __('Réservée brouillons') }}</th>
                                <th class="px-4 py-3 text-end">{{ __('Déjà facturée') }}</th>
                                <th class="px-4 py-3 text-end">{{ __('Disponible') }}</th>
                                <th class="px-4 py-3">{{ __('À facturer') }}</th>
                                <th class="px-4 py-3 text-end">{{ __('PU HT') }}</th>
                                <th class="px-4 py-3 text-end">{{ __('Remise') }}</th>
                                <th class="px-4 py-3 text-end">{{ __('TVA') }}</th>
                                <th class="px-4 py-3 text-end">{{ __('TTC ligne') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($lines as $sourceId => $line)
                                <tr wire:key="supplier-invoice-line-{{ $sourceId }}">
                                    <td class="px-4 py-4"><input type="checkbox" wire:model.live="lines.{{ $sourceId }}.selected" class="border-gray-300"></td>
                                    <td class="whitespace-nowrap px-4 py-4"><p class="font-medium text-indigo-700">{{ $line['goods_receipt_number'] }}</p><p class="text-xs text-gray-500">{{ $line['goods_receipt_date']->format('d/m/Y') }}</p></td>
                                    <td class="min-w-52 px-4 py-4"><p class="font-medium">{{ $line['description'] }}</p><p class="text-xs text-gray-500">{{ $line['reference'] ?? '—' }} · {{ $line['unit_label'] ?? '—' }}</p></td>
                                    <td class="px-4 py-4 text-end">{{ $line['received_quantity'] }}</td>
                                    <td class="px-4 py-4 text-end">{{ $line['reserved_by_other_drafts'] }}</td>
                                    <td class="px-4 py-4 text-end">{{ $line['validated_invoiced_quantity'] }}</td>
                                    <td class="px-4 py-4 text-end font-medium">{{ $line['available_quantity'] }}</td>
                                    <td class="w-40 px-4 py-4">
                                        <x-text-input type="number" min="0.001" max="{{ $line['available_quantity'] }}" step="0.001" wire:model.live="lines.{{ $sourceId }}.quantity" :disabled="! $line['selected']" class="block w-full" />
                                    </td>
                                    <td class="px-4 py-4 text-end">{{ str_replace('.', ',', $line['unit_price']) }}</td>
                                    <td class="px-4 py-4 text-end">{{ str_replace('.', ',', $line['discount_percent']) }} %</td>
                                    <td class="px-4 py-4 text-end">{{ str_replace('.', ',', $line['tax_rate_percent']) }} %</td>
                                    <td class="px-4 py-4 text-end font-medium">{{ isset($calculatedLines[$sourceId]['total_ttc']) ? str_replace('.', ',', $calculatedLines[$sourceId]['total_ttc']) : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="12" class="px-6 py-8 text-center text-gray-500">{{ __('Aucune quantité reçue ne reste à facturer.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table></div>
                </div>
            </section>

            <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
                <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="text-gray-500">{{ __('Brut HT') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $previewTotals['subtotal_ht']) }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('Remises') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $previewTotals['discount_total']) }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('TVA') }}</dt><dd class="font-medium">{{ str_replace('.', ',', $previewTotals['tax_total']) }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('Total TTC') }}</dt><dd class="font-semibold">{{ str_replace('.', ',', $previewTotals['total_ttc']) }}</dd></div>
                </dl>
            </section>
        @endif

        <div class="flex justify-end">
            <x-primary-button :disabled="$order === null">{{ $invoiceId === null ? __('Enregistrer le brouillon') : __('Enregistrer le brouillon') }}</x-primary-button>
        </div>
    </form>
</section>
