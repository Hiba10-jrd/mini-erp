@if ($originalInvoiceFile)
    <div class="si-document-row"><div><p class="font-semibold break-words"><bdi>{{ $originalInvoiceFile->getClientOriginalName() }}</bdi></p><p class="si-muted">{{ number_format($originalInvoiceFile->getSize() / 1024, 1) }} {{ __('Ko') }} · {{ __('Sera enregistré avec la facture') }}</p></div><button type="button" wire:click="clearOriginalInvoiceFile" class="erp-button erp-button-secondary erp-action erp-action-danger">{{ __('Retirer') }}</button></div>
@elseif ($activeDocument)
    <div class="si-document-row"><div><p class="font-semibold break-words"><bdi>{{ $activeDocument->original_name }}</bdi></p><p class="si-muted">{{ number_format($activeDocument->size / 1024, 1) }} {{ __('Ko') }} · {{ $activeDocument->created_at->format('d/m/Y H:i') }}</p></div><a href="{{ route('attachments.download', $activeDocument) }}" class="erp-button erp-button-secondary erp-action erp-action-view">{{ __('Télécharger la facture originale') }}</a></div>
    @if ($canAttachOriginal)<button type="button" wire:click="$toggle('showCorrection')" aria-expanded="{{ $showCorrection ? 'true' : 'false' }}" aria-controls="si-upload-area" class="si-text-button erp-action erp-action-primary">{{ __('Ajouter une version corrigée') }}</button>@endif
@else
    <p class="si-muted">{{ __('Aucune facture originale jointe.') }}</p>
@endif

@if ($canAttachOriginal && (!$activeDocument || $showCorrection || $originalInvoiceFile))
    <div id="si-upload-area" class="si-upload {{ $originalInvoiceFile ? 'hidden' : '' }}">
        <label for="si-original-file" class="si-label">{{ $activeDocument ? __('Nouvelle version corrigée') : __('Choisir la facture originale') }}</label>
        <input id="si-original-file" type="file" wire:model="originalInvoiceFile" accept=".pdf,.jpg,.jpeg,.png" aria-describedby="si-file-help si-file-error" />
        <p id="si-file-help" class="si-muted">{{ __('Format accepté : PDF, JPG ou PNG · 5 Mo maximum') }}</p>
        @if ($activeDocument)<p class="si-muted">{{ __('Le document précédent restera dans l’historique.') }}</p>@endif
    </div>
@endif
<p x-cloak x-show="uploading" role="status" class="si-muted">{{ __('Chargement du document…') }}</p>
<p x-cloak x-show="uploadFailed" role="alert" class="si-error-text">{{ __('Le transfert a échoué. Veuillez réessayer.') }}</p>
<div id="si-file-error"><x-input-error :messages="$errors->get('originalInvoiceFile')" /></div>
@if (!$canAttachOriginal && $canEdit)<p class="si-muted">{{ __('Vous pouvez enregistrer le brouillon sans fichier. Pour joindre un document, les autorisations de consultation et de mise à jour des achats sont nécessaires.') }}</p>@endif

@if ($documents->count() > 1 || ($originalInvoiceFile && $documents->isNotEmpty()))
    <details class="si-secondary"><summary>{{ __('Versions précédentes') }} ({{ $documents->count() - ($originalInvoiceFile ? 0 : 1) }})</summary>
        @if ($canEdit && !$originalInvoiceFile)<label for="si-active-document" class="si-label">{{ __('Document à vérifier') }}</label><select id="si-active-document" wire:model.live="attachmentId">@foreach($documents as $document)<option value="{{ $document->id }}">{{ $document->original_name }} · {{ $document->created_at->format('d/m/Y H:i') }}</option>@endforeach</select>@endif
        <ul class="si-version-list">@foreach ($documents->where('id', '!=', $originalInvoiceFile ? null : $activeDocument?->id) as $document)<li><span><bdi>{{ $document->original_name }}</bdi> · {{ $document->created_at->format('d/m/Y H:i') }}</span><a href="{{ route('attachments.download', $document) }}" class="text-indigo-700 erp-action erp-action-view">{{ __('Télécharger') }}</a></li>@endforeach</ul>
    </details>
@endif
@if ($invoice && Gate::allows('purchases.view'))
    <details class="si-secondary"><summary>{{ __('Autres justificatifs') }}</summary><livewire:attachments-manager parent-type="supplier-invoice" :parent-id="$invoice->id" :exclude-original-invoice="true" :key="'supporting-'.$invoice->id" /></details>
@endif
