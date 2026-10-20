<?php

use App\Models\SupplierInvoice;
use App\Livewire\Concerns\PreparesSupplierInvoiceAmounts;
use App\Services\AttachmentManagementService;
use App\Services\SupplierInvoiceManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

new class extends \Livewire\Volt\Component {
    use WithFileUploads, PreparesSupplierInvoiceAmounts;

    #[Locked] public int $invoiceId;
    #[Locked] public int $version;
    public $correctedFile = null;

    public function mount(int $invoiceId): void
    {
        Gate::authorize('purchases.view');
        $invoice = SupplierInvoice::with(['items', 'reconciliation'])->findOrFail($invoiceId);
        $this->invoiceId = $invoiceId;
        $this->version = $invoice->lock_version;
        $this->initializeAmounts($invoice);
    }

    private function invoice(): SupplierInvoice
    {
        Gate::authorize('purchases.view');
        $invoice = SupplierInvoice::findOrFail($this->invoiceId);
        // Pass the version actually displayed to the transaction, not a fresh replacement.
        return $invoice->forceFill(['lock_version' => $this->version]);
    }

    public function uploadCorrection(AttachmentManagementService $service): void
    {
        Gate::authorize('purchases.update');
        $invoice = $this->invoice();
        abort_unless($invoice->isEditable(), 403);
        $this->validate(['correctedFile' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png']]);
        $attachment = $service->upload($invoice, $this->correctedFile, ['category' => SupplierInvoice::ORIGINAL_DOCUMENT_CATEGORY]);
        $this->attachmentId = (string) $attachment->id;
        $this->reset('correctedFile');
    }

    public function saveAmounts(SupplierInvoiceManagementService $service): void
    {
        $invoice = $service->saveReconciliationDraft($this->invoice(), $this->supplierData());
        $this->version = $invoice->lock_version;
        session()->flash('reconciliationStatus', __('Montants fournisseur enregistrés.'));
        $this->redirectRoute('purchases.invoices.show', ['supplierInvoice' => $this->invoiceId], navigate: true);
    }

    public function submit(SupplierInvoiceManagementService $service): void
    {
        $service->submitForReview($this->invoice(), $this->supplierData());
        $this->redirectRoute('purchases.invoices.show', ['supplierInvoice' => $this->invoiceId], navigate: true);
    }

    public function accept(SupplierInvoiceManagementService $service): void
    {
        $service->validate($this->invoice());
        $this->redirectRoute('purchases.invoices.show', ['supplierInvoice' => $this->invoiceId], navigate: true);
    }

    public function dispute(SupplierInvoiceManagementService $service): void
    {
        $service->dispute($this->invoice(), $this->disputeReason);
        $this->redirectRoute('purchases.invoices.show', ['supplierInvoice' => $this->invoiceId], navigate: true);
    }

    public function with(): array
    {
        Gate::authorize('purchases.view');
        $invoice = SupplierInvoice::with('reconciliation')->findOrFail($this->invoiceId);
        $previewTotals = ['subtotal_ht' => $invoice->subtotal_ht, 'discount_total' => $invoice->discount_total, 'base_ht' => $invoice->baseHt(), 'tax_total' => $invoice->tax_total, 'total_ttc' => $invoice->total_ttc];
        $canPrepareAmounts = $invoice->isEditable() && Gate::allows('purchases.update');
        return compact('invoice', 'previewTotals', 'canPrepareAmounts') + $this->amountPresentation($previewTotals);
    }
}; ?>

<div class="si-workspace">
    <section class="si-panel">
        <header class="si-section-heading"><h3>{{ __('Vérification des montants') }}</h3></header>
        @if ($errors->any())<div role="alert" class="si-message si-error">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @include('livewire.admin.partials.supplier-invoice-amounts')
        @if($canPrepareAmounts)<div class="si-actions"><x-secondary-button type="button" wire:click="saveAmounts" wire:loading.attr="disabled" wire:target="saveAmounts">{{ __('Enregistrer le brouillon') }}</x-secondary-button><x-primary-button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit">{{ __('Soumettre à vérification') }}</x-primary-button></div>@endif
        @if($invoice->status === SupplierInvoice::STATUS_UNDER_REVIEW)
            @can('purchases.update')<div class="si-actions"><x-primary-button type="button" wire:click="accept" wire:confirm="{{ __('Accepter explicitement les montants fournisseur et autoriser leur paiement ?') }}" wire:loading.attr="disabled">{{ __('Accepter la facture') }}</x-primary-button></div><label for="si-legacy-dispute">{{ __('Motif de contestation') }}</label><textarea id="si-legacy-dispute" wire:model="disputeReason" class="w-full"></textarea><x-danger-button type="button" wire:click="dispute" wire:loading.attr="disabled">{{ __('Confirmer la contestation') }}</x-danger-button>@endcan
        @endif
        <a href="{{ route('purchases.invoices.show', $invoice) }}" wire:navigate class="si-text-button inline-block">{{ __('Ouvrir la facture complète') }}</a>
    </section>
</div>
