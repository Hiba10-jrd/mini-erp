@if ($currentStatus === 'under_review')
    <p role="status" class="si-message si-neutral">{{ __('Cette facture attend une décision.') }} {{ __('Les montants sont verrouillés pendant la vérification.') }}</p>
    @can('purchases.update')
        <div class="si-actions"><x-primary-button type="button" wire:click="accept" wire:confirm="{{ __('Accepter explicitement les montants fournisseur et autoriser leur paiement ?') }}" wire:loading.attr="disabled" class="erp-action erp-action-success">{{ __('Accepter la facture') }}</x-primary-button><x-secondary-button type="button" wire:click="$toggle('showDispute')" aria-expanded="{{ $showDispute ? 'true' : 'false' }}" aria-controls="si-dispute-form" class="erp-action erp-action-danger">{{ __('Contester la facture') }}</x-secondary-button></div>
        @if ($showDispute || $errors->has('reason'))<div id="si-dispute-form" class="si-dispute-form"><label for="si-dispute-reason" class="si-label">{{ __('Motif de contestation') }}</label><textarea id="si-dispute-reason" wire:model="disputeReason" rows="2" maxlength="5000" aria-describedby="si-dispute-error"></textarea><div id="si-dispute-error"><x-input-error :messages="$errors->get('reason')" /></div><x-danger-button type="button" wire:click="dispute" wire:loading.attr="disabled" class="erp-action erp-action-danger">{{ __('Confirmer la contestation') }}</x-danger-button></div>@endif
    @endcan
@elseif ($currentStatus === 'validated')
    <p role="status" class="si-message si-success">{{ __('Facture acceptée et validée.') }}</p>
    @php($paymentLabels = ['unpaid' => __('Non payée'), 'partially_paid' => __('Partiellement payée'), 'paid' => __('Payée'), 'overdue' => __('En retard')])
    <div class="si-context-line"><span class="erp-badge {{ $paymentState === 'paid' ? 'erp-badge-success' : 'erp-badge-neutral' }}">{{ $paymentLabels[$paymentState] ?? '—' }}</span><span>{{ __('Déjà payé') }} <strong>{{ $this->money($paidAmount) }}</strong></span></div>
    <dl class="si-accepted-totals"><div><dt>{{ __('Montant ERP de référence') }}</dt><dd>{{ $this->money($invoice->total_ttc) }}</dd></div><div><dt>{{ __('Montant fournisseur accepté') }}</dt><dd>{{ $this->money($invoice->payableAmount()) }}</dd></div><div><dt>{{ __('Écart approuvé') }}</dt><dd>{{ $this->money($difference ?? '0.00') }}</dd></div><div><dt>{{ __('Montant restant dû') }}</dt><dd>{{ $this->money($remaining) }}</dd></div></dl>
    @if (bccomp($remaining, '0.00', 2) > 0)@can('payments.create')<a href="{{ route('purchases.invoices.payments.create', $invoice) }}" wire:navigate class="erp-button erp-button-primary erp-action erp-action-primary">{{ __('Enregistrer un paiement') }}</a>@endcan @endif
    @if ($invoice->paymentAllocations->isNotEmpty())
        <details class="si-secondary"><summary>{{ __('Historique des paiements') }} ({{ $invoice->paymentAllocations->count() }})</summary><ul class="si-history">
            @foreach($invoice->paymentAllocations as $allocation)<li class="si-payment-row"><span>{{ $allocation->payment?->payment_date?->format('d/m/Y') ?? '—' }}</span>@can('payments.view')<a href="{{ route('purchases.payments.show', $allocation->supplier_payment_id) }}" class="text-indigo-700">{{ $allocation->payment?->reference ?? '#'.$allocation->supplier_payment_id }}</a>@else<span>{{ $allocation->payment?->reference ?? '#'.$allocation->supplier_payment_id }}</span>@endcan<strong class="si-number">{{ $this->money($allocation->amount) }}</strong></li>@endforeach
        </ul></details>
    @endif
@elseif ($currentStatus === 'cancelled')
    <p role="status" class="si-message si-neutral">{{ __('Facture annulée. Aucun nouveau paiement n’est autorisé.') }}</p>
@else
    @if ($currentStatus === 'disputed')<div role="status" class="si-message si-error"><strong>{{ __('Facture contestée — paiement bloqué.') }}</strong><p>{{ $dispute?->metadata['reason'] ?? '' }}</p></div><p class="si-muted">{{ __('Ajoutez la version corrigée, vérifiez les montants puis soumettez de nouveau la facture.') }}</p>
    @elseif ($canEdit)<p class="si-muted">{{ __('Le brouillon n’autorise aucun paiement. La soumission prépare une décision explicite.') }}</p>@endif
    @if ($canEdit)
        <div class="si-readiness" role="status">
            @if(!$order)<p>{{ __('Sélectionnez une commande pour poursuivre.') }}</p>
            @elseif($lines === [])<p>{{ __('Aucune quantité reçue ne reste à facturer.') }}</p>
            @elseif($selectedReceipts->isEmpty())<p>{{ __('Sélectionnez au moins une ligne de réception à facturer.') }}</p>
            @elseif(!$canPrepareAmounts)<p>{{ __('Les droits de consultation et de mise à jour des achats sont nécessaires pour soumettre cette facture.') }}</p>
            @elseif($invalidSelectedLine)<p>{{ __('Vérifiez les quantités des lignes sélectionnées avant de soumettre.') }}</p>
            @elseif(trim($supplierInvoiceNumber) === '' || $invoiceDate === '')<p>{{ __('Renseignez le numéro et la date de la facture avant de soumettre.') }}</p>
            @elseif(!$activeDocument && !$originalInvoiceFile)<p>{{ __('Ajoutez le document fournisseur avant de soumettre.') }}</p>
            @elseif($netHt === '' || $tax === '' || $documentTtc === '')<p>{{ __('Complétez les montants du document avant de soumettre.') }}</p>
            @elseif($fiscalIssue)<p>{{ __('Corrigez la cohérence fiscale avant de soumettre.') }}</p>
            @elseif($categoryRequired)<p>{{ __('Choisissez le motif qui explique la différence.') }}</p>
            @elseif($differenceCategory === 'other' && trim($differenceReason) === '')<p>{{ __('Précisez cet autre motif avant de soumettre.') }}</p>
            @elseif($differenceTreatmentRequired)<p>{{ __('Confirmez le montant et le traitement TVA avant de soumettre.') }}</p>
            @elseif($reasonRequired)<p>{{ __('Justifiez les écarts avant de soumettre.') }}</p>
            @else<p>{{ __('Vous pouvez soumettre cette facture à vérification.') }}</p>@endif
        </div>
        <div class="si-actions">
            <x-secondary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save,submit,originalInvoiceFile" x-bind:disabled="uploading || {{ $order === null || $lines === [] ? 'true' : 'false' }}" :disabled="$order === null || $lines === []" class="erp-action erp-action-primary">{{ __('Enregistrer le brouillon') }}</x-secondary-button>
            @if ($canPrepareAmounts)<x-primary-button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit,originalInvoiceFile" x-bind:disabled="uploading || {{ $canSubmit ? 'false' : 'true' }}" :disabled="!$canSubmit">{{ __('Soumettre à vérification') }}</x-primary-button>@endif
        </div>
        @if ($invoice && $currentStatus === 'draft')@can('purchases.delete')<details class="si-secondary"><summary>{{ __('Autres actions') }}</summary><button type="button" wire:click="cancel" wire:confirm="{{ __('Annuler ce brouillon de facture fournisseur ?') }}" wire:loading.attr="disabled" class="si-text-button si-error-text erp-action erp-action-neutral">{{ __('Annuler le brouillon') }}</button></details>@endcan @endif
    @endif
@endif
<p wire:loading wire:target="save,submit,accept,dispute,cancel" role="status" class="si-muted">{{ __('Enregistrement en cours…') }}</p>
