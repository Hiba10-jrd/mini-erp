<div class="si-workspace space-y-5">
    @php
        $currentStatus = $invoice?->status ?? 'draft';
        $statusLabels = ['draft' => __('Brouillon'), 'under_review' => __('En vérification'), 'disputed' => __('Contestée'), 'validated' => __('Validée'), 'cancelled' => __('Annulée')];
    @endphp
    <div class="si-page-intro">
        <div><h2>{{ __('Enregistrer et vérifier une facture fournisseur') }}</h2><p>{{ __('Préparez la facture, comparez les montants puis prenez une décision.') }}</p></div>
        <a href="{{ route('purchases.invoices.index') }}" wire:navigate class="text-indigo-700">{{ __('Retour aux factures fournisseurs') }}</a>
    </div>
    @if (session('status'))<p role="status" class="si-message si-success">{{ session('status') }}</p>@endif
    @if ($errors->any())
        <div role="alert" tabindex="-1" class="si-message si-error">
            <p class="font-semibold">{{ __('La facture n’a pas été enregistrée. Vérifiez les informations ci-dessous.') }}</p>
            <ul class="mt-2 list-disc ps-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <div class="space-y-5"
        x-data="{ uploading: false, uploadFailed: false }"
        x-on:livewire-upload-start="uploading = true; uploadFailed = false"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-error="uploading = false; uploadFailed = true"
        x-on:livewire-upload-cancel="uploading = false">
        <section class="si-panel" aria-labelledby="si-information-heading" data-invoice-section="information">
            <header class="si-section-heading"><span aria-hidden="true" class="si-step">1</span><div><h3 id="si-information-heading">{{ __('Facture fournisseur') }}</h3><p>{{ $invoice?->number ?? __('Brouillon sans numéro définitif') }}</p></div><span class="erp-badge {{ $currentStatus === 'validated' ? 'erp-badge-success' : ($currentStatus === 'disputed' ? 'erp-badge-danger' : 'erp-badge-neutral') }}">{{ $statusLabels[$currentStatus] }}</span></header>
            <div class="si-information-grid">
                <div class="si-field si-order-field">
                    <x-input-label for="si-order" :value="__('Commande fournisseur confirmée')" />
                    @if ($fixedOrderId !== null || !$canEdit)
                        <p id="si-order" class="si-readonly-value">{{ $order?->number ?? '—' }}</p>
                    @else
                        <select id="si-order" wire:model.live="purchaseOrderId" aria-describedby="si-order-error">
                            <option value="">{{ __('Sélectionner une commande') }}</option>
                            @foreach($orders as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->number }} · {{ $candidate->supplier_name }}</option>@endforeach
                        </select>
                    @endif
                    <div id="si-order-error"><x-input-error :messages="$errors->get('purchase_order_id')" /></div>
                </div>
                <div class="si-field"><span class="si-label">{{ __('Fournisseur') }}</span><p class="si-readonly-value">{{ $invoice?->supplier_name ?? $order?->supplier_name ?? '—' }}</p></div>
                <x-supplier-invoice-input model="supplierInvoiceNumber" error="supplier_invoice_number" :label="__('Numéro de facture fournisseur')" maxlength="100" :disabled="!$canEdit" />
                <x-supplier-invoice-input model="invoiceDate" error="invoice_date" :label="__('Date de facture')" type="date" :disabled="!$canEdit" />
                <x-supplier-invoice-input model="dueDate" error="due_date" :label="__('Échéance')" type="date" :disabled="!$canEdit" />
            </div>
            <div class="si-context-line"><span>{{ __('Réceptions concernées') }}</span>@forelse ($selectedReceipts as $receipt)<span class="si-receipt-tag">{{ $receipt['goods_receipt_number'] ?? '—' }}</span>@empty<span>—</span>@endforelse</div>
            <details class="si-secondary"><summary>{{ __('Notes et informations complémentaires') }}</summary>
                <label for="si-notes" class="si-label">{{ __('Notes') }}</label><textarea id="si-notes" wire:model="notes" rows="2" maxlength="5000" @disabled(!$canEdit) class="w-full"></textarea><x-input-error :messages="$errors->get('notes')" />
            </details>
        </section>

        <section class="si-panel" aria-labelledby="si-document-heading" data-invoice-section="document">
            <header class="si-section-heading"><span aria-hidden="true" class="si-step">2</span><div><h3 id="si-document-heading">{{ __('Document du fournisseur') }}</h3><p>{{ __('Un seul document actif. Les versions précédentes sont conservées.') }}</p></div></header>
            @include('livewire.admin.partials.supplier-invoice-document')
        </section>

        <section class="si-panel" aria-labelledby="si-receipts-heading" data-invoice-section="receipts">
            <header class="si-section-heading"><span aria-hidden="true" class="si-step">3</span><div><h3 id="si-receipts-heading">{{ __('Réceptions et calcul ERP') }}</h3><p>{{ __('Prix, remise et TVA proviennent de la commande fournisseur et sont en lecture seule.') }}</p></div></header>
            <x-input-error :messages="$errors->get('items')" />
            @if (!$order)<p class="si-empty">{{ __('Sélectionnez une commande pour afficher ses réceptions disponibles.') }}</p>
            @elseif ($lines === [])<p role="status" class="si-empty">{{ __('Aucune quantité reçue ne reste à facturer.') }}</p>
            @else
                <div class="si-line-heading" aria-hidden="true"><span>{{ __('Article / Réception source') }}</span><span>{{ __('Disponible') }}</span><span>{{ __('À facturer') }}</span><span>{{ __('TTC ligne') }}</span></div>
                <div class="si-lines">
                    @php($selectedPosition = 0)
                    @foreach ($lines as $sourceId => $line)
                        @php($errorPosition = ($line['selected'] ?? false) ? $selectedPosition++ : null)
                        <div class="si-line" wire:key="supplier-invoice-line-{{ $sourceId }}">
                            <div class="si-product">
                                @if($canEdit)<input id="si-include-{{ $sourceId }}" type="checkbox" wire:model.live="lines.{{ $sourceId }}.selected" aria-label="{{ __('Inclure :article', ['article' => $line['description']]) }}" />@endif
                                <div>@if($canEdit)<label for="si-include-{{ $sourceId }}" class="si-product-name">{{ $line['description'] }}</label>@else<p class="si-product-name">{{ $line['description'] }}</p>@endif<p class="si-muted">{{ $line['reference'] ?? '—' }} · {{ $line['goods_receipt_number'] ?? '—' }}</p></div>
                            </div>
                            <div><span class="si-mobile-label">{{ __('Disponible') }}</span><p class="si-number">{{ $line['available_quantity'] ?? '—' }}</p></div>
                            <div class="si-quantity"><label for="si-quantity-{{ $sourceId }}" class="si-mobile-label">{{ __('À facturer') }}</label><input id="si-quantity-{{ $sourceId }}" aria-label="{{ __('Quantité à facturer :article', ['article' => $line['description']]) }}" type="number" min="0.001" step="0.001" @if($line['available_quantity'] !== null) max="{{ $line['available_quantity'] }}" @endif wire:model.live.debounce.300ms="lines.{{ $sourceId }}.quantity" @disabled(!$canEdit || !$line['selected']) aria-invalid="{{ $errorPosition !== null && $errors->has('items.'.$errorPosition.'.quantity') ? 'true' : 'false' }}" aria-describedby="si-quantity-error-{{ $sourceId }}" /><div id="si-quantity-error-{{ $sourceId }}"><x-input-error :messages="$errorPosition === null ? [] : $errors->get('items.'.$errorPosition.'.quantity')" /></div></div>
                            <div><span class="si-mobile-label">{{ __('TTC ligne') }}</span><p class="si-number font-semibold">{{ $this->money($canEdit ? ($calculatedLines[$sourceId]['total_ttc'] ?? null) : ($line['total_ttc'] ?? null)) }}</p></div>
                            <details class="si-line-details"><summary>{{ __('Détails de la ligne') }}</summary><dl class="si-detail-grid">
                                <div><dt>{{ __('Reçue') }}</dt><dd>{{ $line['received_quantity'] ?? '—' }}</dd></div><div><dt>{{ __('Réservée (non validée)') }}</dt><dd>{{ $line['reserved_by_other_drafts'] ?? '—' }}</dd></div><div><dt>{{ __('Déjà facturée') }}</dt><dd>{{ $line['validated_invoiced_quantity'] ?? '—' }}</dd></div><div><dt>{{ __('PU HT') }}</dt><dd>{{ $this->money($line['unit_price']) }}</dd></div><div><dt>{{ __('Remise') }}</dt><dd>{{ $line['discount_percent'] }} %</dd></div><div><dt>{{ __('TVA') }}</dt><dd>{{ $line['tax_rate_percent'] }} %</dd></div>
                            </dl></details>
                        </div>
                    @endforeach
                </div>
                <div class="si-context-line si-total-line"><span>{{ __('Brut HT') }} <strong>{{ $this->money($previewTotals['subtotal_ht']) }}</strong></span><span>{{ __('Remises') }} <strong>{{ $this->money($previewTotals['discount_total']) }}</strong></span><span>{{ __('Total TTC ERP') }} <strong>{{ $this->money($previewTotals['total_ttc']) }}</strong></span></div>
            @endif
        </section>

        <section class="si-panel si-comparison-panel" aria-labelledby="si-amounts-heading" data-invoice-section="amounts">
            <header class="si-section-heading"><span aria-hidden="true" class="si-step">4</span><div><h3 id="si-amounts-heading">{{ __('Vérification des montants') }}</h3><p>{{ __('Le montant fournisseur accepté détermine la dette à payer. La référence ERP est conservée.') }}</p></div></header>
            @include('livewire.admin.partials.supplier-invoice-amounts')
        </section>

        <section class="si-panel" aria-labelledby="si-decision-heading" data-invoice-section="decision">
            <header class="si-section-heading"><span aria-hidden="true" class="si-step">5</span><div><h3 id="si-decision-heading">{{ __('Vérification et décision') }}</h3></div></header>
            @include('livewire.admin.partials.supplier-invoice-decision')
            @if ($invoice)
                <details class="si-secondary"><summary>{{ __('Historique') }}</summary>
                    <ol class="si-history">@foreach ($invoice->histories as $entry)<li><div><p class="font-medium">{{ __($entry->metadata['description_key'] ?? $entry->description, $entry->metadata['description_params'] ?? []) }}</p><p class="si-muted">{{ $entry->user?->name ?? '—' }} · {{ $entry->created_at->format('d/m/Y H:i') }}</p>@if(!empty($entry->metadata['reason']))<p>{{ $entry->metadata['reason'] }}</p>@endif
                    @if(!empty($entry->metadata['supplier']))<details><summary>{{ __('Montants et document de cette décision') }}</summary><p>{{ __('Net HT') }} {{ $this->money($entry->metadata['supplier']['net_ht']) }} · {{ __('TVA') }} {{ $this->money($entry->metadata['supplier']['tax_total']) }} · {{ __('TTC') }} {{ $this->money($entry->metadata['supplier']['total_ttc']) }}</p><p>{{ __('Document de la décision') }} #{{ $entry->metadata['supplier']['attachment_id'] }} · {{ $entry->metadata['supplier']['difference_reason'] ?? '—' }}</p></details>@endif</div></li>@endforeach</ol>
                </details>
            @endif
        </section>
    </div>
</div>
