@if ($invoice?->status === 'validated' && !$invoice->reconciliation)
    <p class="si-message si-neutral">{{ __('Facture historique : montant payable conservé.') }}</p>
@endif
<div class="si-comparison">
    <div class="si-erp-column">
        <h4>{{ __('Calcul ERP') }} <span class="si-muted">{{ __('Lecture seule') }}</span></h4>
        <dl class="si-erp-totals"><div><dt>{{ __('Total HT') }}</dt><dd>{{ $this->money($previewTotals['base_ht']) }}</dd></div><div><dt>{{ __('TVA') }}</dt><dd>{{ $this->money($previewTotals['tax_total']) }}</dd></div><div class="si-grand-total"><dt>{{ __('Total TTC') }}</dt><dd>{{ $this->money($previewTotals['total_ttc']) }}</dd></div></dl>
    </div>
    <div class="si-supplier-column">
        <h4>{{ __('Facture reçue') }}</h4>
        @if ($canPrepareAmounts)
            <div class="si-amount-fields">
                <x-supplier-invoice-input model="netHt" error="net_ht" :label="__('Montant HT du document')" type="number" min="0" step="0.01" inputmode="decimal" />
                <x-supplier-invoice-input model="tax" error="tax_total" :label="__('TVA du document')" type="number" min="0" step="0.01" inputmode="decimal" />
            </div>
            <div class="si-computed-total"><span>{{ __('TTC fournisseur calculé') }}</span><output aria-live="polite" data-calculated-ttc>{{ $this->money($computedTtc) }}</output></div>
        @elseif (Gate::allows('purchases.view'))
            <dl class="si-erp-totals"><div><dt>{{ __('Total HT') }}</dt><dd>{{ $this->money($invoice?->reconciliation?->net_ht ?? ($netHt ?: null)) }}</dd></div><div><dt>{{ __('TVA') }}</dt><dd>{{ $this->money($invoice?->reconciliation?->tax_total ?? ($tax ?: null)) }}</dd></div><div class="si-grand-total"><dt>{{ __('TTC calculé') }}</dt><dd>{{ $this->money($computedTtc) }}</dd></div></dl>
        @else
            <p class="si-muted">{{ __('Les droits de consultation et de mise à jour des achats sont nécessaires pour préparer le rapprochement.') }}</p>
        @endif
    </div>
</div>
@if ($fiscalIssue)
    <div role="alert" class="si-message si-error"><strong>{{ __('Cohérence fiscale à corriger') }}</strong><p>{{ $fiscalIssue }}</p></div>
@elseif ($difference !== null)
    <div role="status" class="si-message {{ $hasDifference ? 'si-warning' : 'si-success' }}">
        <div class="si-comparison-status"><strong>{{ $hasDifference ? __('Écart à justifier') : __('Montants concordants') }}</strong></div>
        <dl class="si-difference-totals"><div><dt>{{ __('Écart HT') }}</dt><dd>{{ bccomp($differenceHt, '0', 2) > 0 ? '+' : '' }}{{ $this->money($differenceHt) }}</dd></div><div><dt>{{ __('Écart TVA') }}</dt><dd>{{ bccomp($differenceTax, '0', 2) > 0 ? '+' : '' }}{{ $this->money($differenceTax) }}</dd></div><div><dt>{{ __('Écart TTC') }}</dt><dd>{{ bccomp($difference, '0', 2) > 0 ? '+' : '' }}{{ $this->money($difference) }}</dd></div></dl>
    </div>
@elseif ($canPrepareAmounts)
    <p class="si-muted">{{ __('Recopiez les montants du document pour lancer la comparaison.') }}</p>
@endif

@if ($canPrepareAmounts)
    @if ($hasDifference && isset($lines))
        <div class="si-difference-fields" data-difference-fields>
            <x-input-label for="si-difference-category" :value="__('Pourquoi le montant diffère-t-il ?')" />
            <select id="si-difference-category" wire:model.live="differenceCategory" aria-describedby="si-difference-category-error">
                <option value="">{{ __('Sélectionner un motif') }}</option>
                <option value="price">{{ __('Différence de prix') }}</option>
                <option value="delivery">{{ __('Frais de livraison') }}</option>
                <option value="discount">{{ __('Remise') }}</option>
                <option value="other_fees">{{ __('Autres frais') }}</option>
                <option value="other">{{ __('Autre motif') }}</option>
            </select>
            <x-input-error id="si-difference-category-error" :messages="$errors->get('differenceCategory')" />
        </div>
    @endif
    <details class="si-secondary si-tax-details" x-on:toggle="if ($wire.taxDetailsOpen !== $el.open) $wire.$set('taxDetailsOpen', $el.open, false)" @if($advancedNeeded || $taxDetailsOpen || $errors->has('reconciliation') || in_array($differenceCategory, ['delivery', 'discount', 'other_fees'], true)) open @endif data-tax-details>
        <summary>{{ __('Détails des taxes et frais') }}@if(!$detailed && $rate !== '')<span class="si-muted"> · {{ __('TVA') }} {{ $this->money($rate) }} %</span>@endif</summary>
        @if ($hasDifference && in_array($differenceCategory, ['delivery', 'discount', 'other_fees'], true))
            <div class="si-charge-confirmation">
                <p>{{ __('Écart HT') }} <strong>{{ $this->money($differenceHt) }}</strong> · {{ __('Écart TVA') }} <strong>{{ $this->money($differenceTax) }}</strong></p>
                @if (!$detailed)
                    <div class="si-tax-grid">
                        <x-supplier-invoice-input model="differenceTaxRate" :label="__('Taux de TVA applicable aux frais (%)')" type="number" min="0" max="100" step="0.01" />
                        @if ($this->zeroTaxRate($differenceTaxRate))<x-supplier-invoice-input model="differenceTaxReason" :label="__('Traitement sans TVA')" maxlength="500" />@endif
                    </div>
                @endif
                <label class="si-confirm-treatment"><input type="checkbox" wire:model.live="confirmDifferenceTaxTreatment" aria-describedby="si-treatment-error" /> <span>{{ __('Je confirme ce montant et le traitement de TVA indiqué.') }}</span></label>
                <div id="si-treatment-error"><x-input-error :messages="$errors->get('confirmDifferenceTaxTreatment')" /></div>
                @if ($differenceTreatmentRequired)<p role="alert" class="si-error-text">{{ __('Confirmez le montant et le traitement TVA avant de soumettre.') }}</p>@endif
            </div>
        @endif
        @if (!$detailed)
            @php($simpleCharge = in_array($differenceCategory, ['delivery', 'discount', 'other_fees'], true))
            <div class="si-tax-grid">
                @if (!$simpleCharge)
                    <x-supplier-invoice-input model="rate" :label="__('Taux de TVA (%)')" type="number" min="0" max="100" step="0.01" />
                    @if ($this->zeroTaxRate($rate) || $taxReason !== '')<x-supplier-invoice-input model="taxReason" :label="__('Traitement sans TVA')" maxlength="500" />@endif
                    <x-supplier-invoice-input model="roundingReason" :label="__('Justification de l’arrondi')" maxlength="500" />
                @elseif ($this->zeroTaxRate($rate) || $taxReason !== '')
                    <x-supplier-invoice-input model="taxReason" :label="__('Traitement sans TVA des articles ERP')" maxlength="500" />
                @endif
            </div>
        @else
            <p class="si-muted">{{ __('La somme des composantes doit correspondre aux montants du document.') }}</p>
            @foreach ($breakdown as $index => $component)
                <fieldset class="si-tax-component" wire:key="si-tax-{{ $index }}"><legend>{{ __('Composante :number', ['number' => $index + 1]) }}</legend>
                    <div class="si-tax-grid"><div class="si-field"><label for="si-kind-{{ $index }}">{{ __('Nature') }}</label><select id="si-kind-{{ $index }}" wire:model.live="breakdown.{{ $index }}.kind"><option value="purchase">{{ __('Achats') }}</option><option value="discount">{{ __('Remise') }}</option><option value="transport">{{ __('Transport') }}</option><option value="fee">{{ __('Frais accessoires') }}</option></select></div>
                    <x-supplier-invoice-input :model="'breakdown.'.$index.'.label'" :label="__('Libellé')" maxlength="200" />
                    <x-supplier-invoice-input :model="'breakdown.'.$index.'.net_ht'" :label="__('Base HT')" type="number" min="0" step="0.01" />
                    <x-supplier-invoice-input :model="'breakdown.'.$index.'.rate'" :label="__('Taux de TVA (%)')" type="number" min="0" max="100" step="0.01" />
                    <x-supplier-invoice-input :model="'breakdown.'.$index.'.tax'" :label="__('TVA du document')" type="number" min="0" step="0.01" />
                    @if ($this->zeroTaxRate((string)($component['rate'] ?? '')) || !empty($component['tax_reason']))<x-supplier-invoice-input :model="'breakdown.'.$index.'.tax_reason'" :label="__('Traitement sans TVA')" maxlength="500" />@endif
                    <x-supplier-invoice-input :model="'breakdown.'.$index.'.rounding_reason'" :label="__('Justification de l’arrondi')" maxlength="500" /></div>
                    <button type="button" wire:click="removeComponent({{ $index }})" class="si-text-button si-error-text erp-action erp-action-danger">{{ __('Retirer la composante') }}</button>
                </fieldset>
            @endforeach
        @endif
        <button type="button" wire:click="addComponent" class="erp-button erp-button-secondary erp-action erp-action-primary">{{ __('Ajouter un taux, une remise ou des frais') }}</button>
    </details>
@elseif ($invoice?->reconciliation)
    <details class="si-secondary"><summary>{{ __('Détails des taxes et frais') }}</summary><ul class="si-history">@foreach($invoice->reconciliation->breakdown as $component)<li>{{ $component['label'] }} · {{ $this->money($component['net_ht']) }} HT · {{ $component['rate'] }} % · {{ $this->money($component['tax']) }} TVA @if($component['tax_reason'])· {{ $component['tax_reason'] }}@endif @if($component['rounding_reason'])· {{ $component['rounding_reason'] }}@endif</li>@endforeach</ul></details>
@endif

@if ($hasDifference || $hasRounding || $differenceReason !== '')
    <div class="si-field si-difference-reason" data-difference-reason>
        @if (!($hasDifference && $differenceCategory === 'other'))
            <label for="si-difference-reason">{{ __('Précision (facultative)') }}</label>
            @if($canPrepareAmounts)<textarea id="si-difference-reason" wire:model="differenceReason" maxlength="5000" rows="2" aria-describedby="si-difference-error"></textarea>@else<p>{{ $differenceReason ?: '—' }}</p>@endif
        @else
            <label for="si-difference-reason">{{ __('Précisez cet autre motif') }} <span class="si-muted">· {{ __('Obligatoire avant soumission') }}</span></label>
            @if($canPrepareAmounts)<textarea id="si-difference-reason" wire:model="differenceReason" maxlength="5000" rows="2" aria-describedby="si-difference-error"></textarea>@else<p>{{ $differenceReason ?: '—' }}</p>@endif
        @endif
        <div id="si-difference-error"><x-input-error :messages="$errors->get('reconciliation')" /><x-input-error :messages="$errors->get('differenceReason')" /></div>
    </div>
@endif
