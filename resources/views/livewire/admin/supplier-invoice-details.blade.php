<?php

use App\Models\SupplierInvoice;
use App\Services\SupplierInvoiceManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public int $invoiceId;

    #[Locked]
    public int $version;

    public function mount(int $invoiceId): void
    {
        Gate::authorize('purchases.view');

        $this->invoiceId = $invoiceId;
        $this->version = SupplierInvoice::findOrFail($invoiceId)->lock_version;
    }

    public function validateSupplierInvoice(
        SupplierInvoiceManagementService $service
    ): void {
        Gate::authorize('purchases.update');

        $service->validate(
            SupplierInvoice::query()
                ->findOrFail($this->invoiceId)->forceFill(['lock_version' => $this->version])
        );

        session()->flash(
            'status',
            __('La facture fournisseur a été validée avec succès.')
        );
    }

    public function cancelSupplierInvoice(
        SupplierInvoiceManagementService $service
    ): void {
        Gate::authorize('purchases.delete');

        $service->cancelDraft(
            SupplierInvoice::query()
                ->findOrFail($this->invoiceId)->forceFill(['lock_version' => $this->version])
        );

        session()->flash(
            'status',
            __('Le brouillon de facture fournisseur a été annulé.')
        );
    }

    public function with(): array
    {
        Gate::authorize('purchases.view');
        return ['invoice' => SupplierInvoice::findOrFail($this->invoiceId)];
    }
}; ?>

<div>
    <livewire:admin.supplier-invoice-form :invoice-id="$invoiceId" :view-only="true" :key="'workspace-'.$invoiceId.'-'.$invoice->lock_version" />
</div>
