<?php

use App\Livewire\Concerns\PreparesSupplierInvoiceAmounts;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Services\AttachmentManagementService;
use App\Services\QuoteCalculator;
use App\Services\SupplierInvoiceManagementService;
use App\Services\SupplierPaymentManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

new class extends \Livewire\Volt\Component {
    use WithFileUploads, PreparesSupplierInvoiceAmounts;

    #[Locked] public ?int $invoiceId = null;
    #[Locked] public int $version = 0;
    #[Locked] public ?int $fixedOrderId = null;
    #[Locked] public bool $viewOnly = false;
    public string $purchaseOrderId = '';
    public string $supplierInvoiceNumber = '';
    public string $invoiceDate = '';
    public string $dueDate = '';
    public string $notes = '';
    public $originalInvoiceFile = null;
    public array $lines = [];
    public bool $showCorrection = false;
    public bool $showDispute = false;

    public function mount(?int $invoiceId = null, ?int $orderId = null, bool $viewOnly = false): void
    {
        $this->viewOnly = $viewOnly;
        $this->invoiceDate = today()->toDateString();
        if ($invoiceId !== null) {
            Gate::authorize($viewOnly ? 'purchases.view' : 'purchases.update');
            $invoice = SupplierInvoice::with(['items.goodsReceiptItem.goodsReceipt', 'purchaseOrder', 'reconciliation'])->findOrFail($invoiceId);
            if (! $viewOnly) abort_unless($invoice->isEditable(), 403);
            $this->invoiceId = $invoiceId;
            $this->version = $invoice->lock_version;
            $this->fixedOrderId = $invoice->purchase_order_id;
            $this->purchaseOrderId = (string) $invoice->purchase_order_id;
            $this->supplierInvoiceNumber = $invoice->supplier_invoice_number;
            $this->invoiceDate = $invoice->invoice_date->toDateString();
            $this->dueDate = $invoice->due_date?->toDateString() ?? '';
            $this->notes = $invoice->notes ?? '';
            if ($invoice->isEditable() && Gate::allows('purchases.update')) {
                $this->initializeLines($invoice->purchaseOrder, app(SupplierInvoiceManagementService::class), $invoice);
            } else {
                foreach ($invoice->items as $item) {
                    $receipt = $item->goodsReceiptItem?->goodsReceipt;
                    $this->lines[$item->goods_receipt_item_id] = [
                        'goods_receipt_item_id' => $item->goods_receipt_item_id, 'selected' => true,
                        'quantity' => $item->quantity, 'description' => $item->description, 'reference' => $item->reference,
                        'unit_label' => $item->unit_label, 'unit_price' => $item->unit_price,
                        'discount_percent' => $item->discount_percent, 'tax_rate_percent' => $item->tax_rate_percent,
                        'goods_receipt_number' => $receipt?->number, 'goods_receipt_id' => $receipt?->id,
                        'received_quantity' => $item->goodsReceiptItem?->quantity, 'available_quantity' => null, 'total_ttc' => $item->total_ttc,
                    ];
                }
            }
            if (Gate::allows('purchases.view')) $this->initializeAmounts($invoice);
            return;
        }
        Gate::authorize('purchases.create');
        if ($orderId !== null) {
            $order = PurchaseOrder::findOrFail($orderId);
            abort_unless($order->status === PurchaseOrder::STATUS_CONFIRMED, 422);
            $this->fixedOrderId = $orderId;
            $this->purchaseOrderId = (string) $orderId;
            $this->initializeLines($order, app(SupplierInvoiceManagementService::class));
        }
    }

    public function updatedPurchaseOrderId(SupplierInvoiceManagementService $service): void
    {
        $this->authorizeMutation();
        abort_if($this->fixedOrderId !== null, 403);
        $this->lines = [];
        if ($this->purchaseOrderId !== '') {
            $order = PurchaseOrder::findOrFail($this->purchaseOrderId);
            abort_unless($order->status === PurchaseOrder::STATUS_CONFIRMED, 422);
            $this->initializeLines($order, $service);
        }
    }

    private function initializeLines(PurchaseOrder $order, SupplierInvoiceManagementService $service, ?SupplierInvoice $invoice = null): void
    {
        $available = $service->availableItemsForOrder($order, $invoice);
        $current = $invoice?->items->keyBy('goods_receipt_item_id') ?? collect();
        foreach ($available as $line) {
            $id = (int) $line['goods_receipt_item_id'];
            $this->lines[$id] = [...$line, 'selected' => $invoice === null || $current->has($id),
                'quantity' => $current->get($id)?->quantity ?? ($invoice === null ? $line['available_quantity'] : '0.000')];
        }
        $rates = collect($this->lines)->filter(fn ($line) => $line['selected'] ?? false)->pluck('tax_rate_percent')->unique();
        $this->rate = $rates->count() === 1 ? (string) $rates->first() : '';
        if ($rates->count() > 1 && $this->breakdown === []) {
            $this->detailed = true;
            $this->breakdown = $rates->map(fn ($rate) => ['kind' => 'purchase', 'label' => __('Achats'), 'net_ht' => '', 'rate' => (string) $rate, 'tax' => '', 'tax_reason' => '', 'rounding_reason' => ''])->values()->all();
        }
    }

    private function authorizeMutation(): void
    {
        Gate::authorize($this->invoiceId === null ? 'purchases.create' : 'purchases.update');
        if ($this->invoiceId !== null) abort_unless(SupplierInvoice::findOrFail($this->invoiceId)->isEditable(), 403);
    }

    private function displayedInvoice(): SupplierInvoice
    {
        Gate::authorize('purchases.view');
        return SupplierInvoice::findOrFail($this->invoiceId)->forceFill(['lock_version' => $this->version]);
    }

    public function clearOriginalInvoiceFile(): void
    {
        $this->reset('originalInvoiceFile');
        $this->resetValidation('originalInvoiceFile');
    }

    public function save(SupplierInvoiceManagementService $service): void
    {
        $this->persistWorkspace($service, false);
    }

    public function submit(SupplierInvoiceManagementService $service): void
    {
        $this->authorizeAmountEdit();
        $this->persistWorkspace($service, true);
    }

    private function persistWorkspace(SupplierInvoiceManagementService $service, bool $submit): void
    {
        $this->authorizeMutation();
        $hasAmounts = $submit || $this->netHt !== '' || $this->tax !== '';
        if ($hasAmounts) $this->authorizeAmountEdit();
        $this->validate(['originalInvoiceFile' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png']], [
            'originalInvoiceFile.max' => __('Le fichier ne doit pas dépasser 5 Mo.'),
            'originalInvoiceFile.mimes' => __('Choisissez une facture originale au format PDF, JPG, JPEG ou PNG.'),
            'originalInvoiceFile.mimetypes' => __('Le contenu du fichier ne correspond pas à un format autorisé.'),
        ]);
        if ($this->originalInvoiceFile !== null) $this->authorizeAmountEdit();
        $order = $this->resolveOrder();
        if ($order === null) { $this->addError('purchase_order_id', __('Sélectionnez une commande fournisseur confirmée.')); return; }
        $items = collect($this->lines)->filter(fn ($line) => (bool) ($line['selected'] ?? false))
            ->map(fn ($line) => ['goods_receipt_item_id' => $line['goods_receipt_item_id'], 'quantity' => $line['quantity']])->values()->all();
        if ($items === []) { $this->addError('items', __('Sélectionnez au moins une ligne de réception à facturer.')); return; }
        if ($submit) {
            try {
                $amountState = $this->amountPresentation($this->currentErpTotals());
            } catch (\Throwable) {
                $this->addError('items', __('Vérifiez les quantités des lignes sélectionnées avant de soumettre.'));
                return;
            }
            if ($amountState['categoryRequired']) {
                $this->addError('differenceCategory', __('Choisissez le motif qui explique la différence.'));
                return;
            }
            if ($amountState['differenceTreatmentRequired']) {
                $this->addError('confirmDifferenceTaxTreatment', __('Confirmez le montant et le traitement TVA avant de soumettre.'));
                return;
            }
            if ($amountState['reasonRequired']) {
                $this->addError('reconciliation', __('Justifiez les écarts avant de soumettre.'));
                return;
            }
            if ($this->differenceCategory === 'other' && trim($this->differenceReason) === '') {
                $this->addError('differenceReason', __('Précisez cet autre motif avant de soumettre.'));
                return;
            }
        }
        $attributes = ['supplier_invoice_number' => $this->supplierInvoiceNumber, 'invoice_date' => $this->invoiceDate,
            'due_date' => $this->dueDate ?: null, 'notes' => $this->notes, 'items' => $items];
        $uploaded = null;
        try {
            // Coordinate existing services atomically; their financial rules remain unchanged.
            $invoice = DB::transaction(function () use ($service, $submit, $hasAmounts, $order, $attributes, &$uploaded) {
                $invoice = $this->invoiceId === null ? $service->createDraft($order, $attributes)
                    : $service->updateDraft(SupplierInvoice::findOrFail($this->invoiceId)->forceFill(['lock_version' => $this->version]), $attributes);
                $data = $this->supplierData();
                if ($this->originalInvoiceFile !== null) {
                    $uploaded = app(AttachmentManagementService::class)->upload($invoice, $this->originalInvoiceFile, ['category' => SupplierInvoice::ORIGINAL_DOCUMENT_CATEGORY]);
                    $data['attachment_id'] = $uploaded->id;
                }
                if ($submit) return $service->submitForReview($invoice, $data);
                if ($hasAmounts) return $service->saveReconciliationDraft($invoice, $data);
                return $invoice;
            });
        } catch (\Throwable $exception) {
            if ($uploaded !== null) Storage::disk('attachments')->delete($uploaded->path);
            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                $errors = $exception->errors();
                $labels = ['net_ht' => __('Montant HT du document'), 'tax_total' => __('TVA du document'), 'total_ttc' => __('TTC fournisseur calculé'), 'rate' => __('Taux de TVA (%)'), 'tax' => __('TVA du document'), 'label' => __('Libellé'), 'kind' => __('Nature')];
                foreach ($errors as $key => &$messages) {
                    if (preg_match('/^breakdown\.(\d+)\.(\w+)$/', $key, $matches)) {
                        $messages = [__('Vérifiez le champ « :field » de la composante :number.', ['field' => $labels[$matches[2]] ?? __('Montants'), 'number' => (int)$matches[1] + 1])];
                    } elseif (isset($labels[$key])) {
                        $messages = [__('Vérifiez le champ « :field » : saisissez un montant valide avec deux décimales au maximum.', ['field' => $labels[$key]])];
                    } elseif ($key === 'attachment_id') {
                        $messages = [__('Ajoutez le document fournisseur avant de soumettre.')];
                    }
                }
                unset($messages);
                throw \Illuminate\Validation\ValidationException::withMessages($errors);
            }
            throw $exception;
        }
        session()->flash('status', $submit ? __('Facture soumise à vérification.') : __('Le brouillon et les informations saisies ont été enregistrés.'));
        $this->redirectRoute('purchases.invoices.show', ['supplierInvoice' => $invoice], navigate: true);
    }

    public function accept(SupplierInvoiceManagementService $service): void
    {
        $service->validate($this->displayedInvoice());
        session()->flash('status', __('La facture fournisseur a été validée avec succès.'));
        $this->redirectRoute('purchases.invoices.show', ['supplierInvoice' => $this->invoiceId], navigate: true);
    }

    public function dispute(SupplierInvoiceManagementService $service): void
    {
        $service->dispute($this->displayedInvoice(), $this->disputeReason);
        $this->redirectRoute('purchases.invoices.show', ['supplierInvoice' => $this->invoiceId], navigate: true);
    }

    public function cancel(SupplierInvoiceManagementService $service): void
    {
        $service->cancelDraft($this->displayedInvoice());
        $this->redirectRoute('purchases.invoices.show', ['supplierInvoice' => $this->invoiceId], navigate: true);
    }

    private function resolveOrder(): ?PurchaseOrder
    {
        $id = $this->fixedOrderId ?? filter_var($this->purchaseOrderId, FILTER_VALIDATE_INT);
        return $id ? PurchaseOrder::findOrFail($id) : null;
    }

    public function with(QuoteCalculator $calculator, SupplierInvoiceManagementService $service): array
    {
        Gate::authorize($this->viewOnly ? 'purchases.view' : ($this->invoiceId === null ? 'purchases.create' : 'purchases.update'));
        $invoice = $this->invoiceId ? SupplierInvoice::with(['reconciliation', 'histories.user', 'paymentAllocations.payment'])->findOrFail($this->invoiceId) : null;
        $canEdit = ($invoice === null || $invoice->isEditable()) && Gate::allows($invoice === null ? 'purchases.create' : 'purchases.update');
        $order = $this->resolveOrder();
        $calculatedLines = [];
        foreach ($this->lines as $id => $line) {
            if (! ($line['selected'] ?? false)) continue;
            try { $calculatedLines[$id] = $calculator->line((string) $line['quantity'], (string) $line['unit_price'], (string) $line['discount_percent'], (string) $line['tax_rate_percent']); }
            catch (\Throwable) { $calculatedLines[$id] = null; }
        }
        $previewTotals = $invoice !== null && ! $canEdit
            ? ['subtotal_ht' => $invoice->subtotal_ht, 'discount_total' => $invoice->discount_total, 'base_ht' => $invoice->baseHt(), 'tax_total' => $invoice->tax_total, 'total_ttc' => $invoice->total_ttc]
            : $calculator->totals(collect($calculatedLines)->filter()->values()->all());
        $orders = collect();
        if ($this->fixedOrderId === null) $orders = PurchaseOrder::where('status', PurchaseOrder::STATUS_CONFIRMED)->latest('order_date')->get()->filter(fn ($candidate) => $service->availableItemsForOrder($candidate) !== []);
        $canAttachOriginal = $canEdit && Gate::allows('purchases.view') && Gate::allows('purchases.update');
        $canPrepareAmounts = $canAttachOriginal;
        $documents = $invoice && Gate::allows('purchases.view') ? $invoice->attachments()->where('category', SupplierInvoice::ORIGINAL_DOCUMENT_CATEGORY)->latest('id')->get() : collect();
        $activeDocument = $documents->firstWhere('id', (int) $this->attachmentId) ?? $documents->first();
        $payments = app(SupplierPaymentManagementService::class);
        $remaining = $invoice ? $payments->remainingAmount($invoice) : '0.00';
        $paidAmount = $invoice ? $payments->paidAmount($invoice) : '0.00';
        $paymentState = $invoice ? $payments->paymentState($invoice) : 'not_applicable';
        $dispute = $invoice?->histories->where('event', 'disputed')->last();
        $selectedReceipts = collect($this->lines)->filter(fn ($line) => $line['selected'] ?? false)->unique('goods_receipt_id');

        $invalidSelectedLine = collect($this->lines)->contains(fn ($line, $id) => ($line['selected'] ?? false) && ! isset($calculatedLines[$id]));
        $amountState = $this->amountPresentation($previewTotals);
        $canSubmit = $canPrepareAmounts
            && $order !== null
            && $this->lines !== []
            && $selectedReceipts->isNotEmpty()
            && ! $invalidSelectedLine
            && trim($this->supplierInvoiceNumber) !== ''
            && $this->invoiceDate !== ''
            && ($activeDocument !== null || $this->originalInvoiceFile !== null)
            && $this->netHt !== ''
            && $this->tax !== ''
            && $this->documentTtc !== ''
            && $amountState['fiscalIssue'] === null
            && ! $amountState['categoryRequired']
            && ! $amountState['differenceTreatmentRequired']
            && ! $amountState['reasonRequired']
            && ! ($this->differenceCategory === 'other' && trim($this->differenceReason) === '');

        return compact('invoice', 'order', 'orders', 'canEdit', 'canAttachOriginal', 'canPrepareAmounts', 'canSubmit', 'invalidSelectedLine', 'calculatedLines', 'previewTotals', 'documents', 'activeDocument', 'remaining', 'paidAmount', 'paymentState', 'dispute', 'selectedReceipts') + $amountState;
    }
}; ?>

@include('livewire.admin.partials.supplier-invoice-workspace')
