<?php

use App\Services\AuditQueryService;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $source = 'operations';

    public string $user = '';

    public string $action = '';

    public string $entity = '';

    public string $from = '';

    public string $to = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        \Illuminate\Support\Facades\Gate::authorize('audit.access');
        $this->reset('source', 'user', 'action', 'entity', 'from', 'to');
        $this->resetPage();
    }

    public function with(): array
    {
        \Illuminate\Support\Facades\Gate::authorize('audit.access');
        $this->validate([
            'source' => ['required', \Illuminate\Validation\Rule::in(array_keys(AuditQueryService::SOURCES))],
            'user' => ['nullable', 'integer'], 'action' => ['nullable', 'string', 'max:100'],
            'entity' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = app(AuditQueryService::class)->query($this->source, [
            'user' => $this->user, 'action' => $this->action, 'entity' => $this->entity,
            'from' => $this->from, 'to' => $this->to,
        ]);
        $actionColumn = AuditQueryService::SOURCES[$this->source][2];
        $stats = (clone $query)->reorder()->toBase()->selectRaw("COUNT(*) AS total, SUM(CASE WHEN $actionColumn = 'created' THEN 1 ELSE 0 END) AS created, SUM(CASE WHEN $actionColumn IN ('updated', 'draft_updated') THEN 1 ELSE 0 END) AS updated, SUM(CASE WHEN $actionColumn = 'deleted' THEN 1 ELSE 0 END) AS deleted")->first();

        return ['entries' => $query->paginate(20), 'stats' => $stats];
    }
}; ?>
<section class="space-y-6">
    <div><h2 class="text-xl font-semibold">{{ __('Audit des opérations') }}</h2><p class="mt-2 text-sm text-slate-500">{{ __('Consultez les actions sensibles et l’historique complémentaire du système.') }}</p></div>
    <x-section-card title="Filtrer les événements" description="Affinez la source, l’auteur et la période à consulter.">
        <form wire:submit="$refresh" class="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <label class="grid gap-2">{{ __('Source') }}<select wire:model.live="source"><option value="operations">{{ __('Opérations complémentaires') }}</option><option value="sales-orders">{{ __('Commandes clients') }}</option><option value="purchase-orders">{{ __('Commandes fournisseurs') }}</option><option value="goods-receipts">{{ __('Réceptions') }}</option><option value="supplier-invoices">{{ __('Factures fournisseurs') }}</option><option value="stock">{{ __('Mouvements de stock') }}</option><option value="reminders">{{ __('Relances') }}</option></select></label>
            <label class="grid gap-2">{{ __('Utilisateur (ID)') }}<input type="number" wire:model.live.debounce.300ms="user" placeholder="{{ __('Tous les utilisateurs') }}" /></label>
            <label class="grid gap-2">{{ __('Action') }}<select wire:model.live="action"><option value="">{{ __('Toutes les actions') }}</option><option value="created">{{ __('Création') }}</option><option value="updated">{{ __('Modification') }}</option><option value="deleted">{{ __('Suppression') }}</option><option value="permissions.changed">{{ __('Permissions modifiées') }}</option><option value="roles.changed">{{ __('Rôles modifiés') }}</option><option value="attachment.uploaded">{{ __('Pièce jointe ajoutée') }}</option><option value="attachment.deleted">{{ __('Pièce jointe retirée') }}</option><option value="validated">{{ __('Validation') }}</option><option value="confirmed">{{ __('Confirmation') }}</option><option value="cancelled">{{ __('Annulation') }}</option><option value="draft_updated">{{ __('Brouillon modifié') }}</option><option value="entry">{{ __('Entrée') }}</option><option value="exit">{{ __('Sortie') }}</option><option value="manual">{{ __('Relance manuelle') }}</option><option value="email">{{ __('Relance email') }}</option><option value="phone">{{ __('Relance téléphone') }}</option><option value="whatsapp">{{ __('Relance WhatsApp') }}</option></select></label>
            @if($source === 'operations')<label class="grid gap-2">{{ __('Entité') }}<input wire:model.live.debounce.300ms="entity" placeholder="{{ __('Ex. invoice, expense, user') }}" /></label>@endif
            <label class="grid gap-2">{{ __('Du') }}<input type="date" wire:model.live="from" /></label><label class="grid gap-2">{{ __('Au') }}<input type="date" wire:model.live="to" /></label>
            <div class="flex gap-2 sm:col-span-2 xl:col-span-3"><x-primary-button>{{ __('Appliquer') }}</x-primary-button><x-secondary-button wire:click="resetFilters">{{ __('Réinitialiser') }}</x-secondary-button></div>
        </form>
        @foreach($errors->all() as $error)<p role="alert" class="mt-2 text-sm text-red-600">{{ $error }}</p>@endforeach
    </x-section-card>
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-kpi-card title="Total événements" :value="$stats->total ?? 0" subtitle="Source et filtres sélectionnés" />
        <x-kpi-card title="Créations" :value="$stats->created ?? 0" accent="#18C978" />
        <x-kpi-card title="Modifications" :value="$stats->updated ?? 0" accent="#087FF5" />
        <x-kpi-card title="Suppressions" :value="$stats->deleted ?? 0" accent="#DC3545" />
    </div>
    <p class="text-xs text-slate-500">{{ __('Les historiques existants sont conservés dans leurs sections. Les valeurs avant/après ne sont disponibles que lorsqu’elles ont été enregistrées.') }}</p>
    <div class="erp-timeline">
        @forelse($entries as $entry)
            @php
                $relation = AuditQueryService::SOURCES[$source][3];
                $actionColumn = AuditQueryService::SOURCES[$source][2];
                $event = $entry->{$actionColumn};
                $actionLabels = ['created'=>'Création','updated'=>'Modification','deleted'=>'Suppression','permissions.changed'=>'Permissions modifiées','roles.changed'=>'Rôles modifiés','attachment.uploaded'=>'Pièce jointe ajoutée','attachment.deleted'=>'Pièce jointe retirée','draft_updated'=>'Brouillon modifié','validated'=>'Validation','confirmed'=>'Confirmation','cancelled'=>'Annulation'];
                $entityLabels = ['invoice'=>'Facture client','invoice-item'=>'Ligne de facture','supplier-invoice'=>'Facture fournisseur','payment'=>'Paiement client','payment-allocation'=>'Affectation client','supplier-payment'=>'Paiement fournisseur','supplier-payment-allocation'=>'Affectation fournisseur','expense'=>'Dépense','cash-transaction'=>'Mouvement de caisse','cash-register'=>'Caisse','attachment'=>'Pièce jointe','user'=>'Utilisateur','role'=>'Rôle','customer'=>'Client','supplier'=>'Fournisseur','product'=>'Produit','quote'=>'Devis','quote-item'=>'Ligne de devis','credit-note'=>'Avoir','credit-note-item'=>'Ligne d’avoir','company'=>'Entreprise','commercial-setting'=>'Paramètres commerciaux','stock-inventory'=>'Inventaire','stock-inventory-line'=>'Ligne d’inventaire','tax-rate'=>'Taux de TVA','payment-term'=>'Condition de paiement','payment-method'=>'Mode de paiement','expense-category'=>'Catégorie de dépense','customer-contact'=>'Contact client','supplier-contact'=>'Contact fournisseur'];
                $subjectLabel = $source === 'operations' ? ($entityLabels[$entry->subject_type] ?? str_replace('-', ' ', $entry->subject_type)) : ['sales-orders'=>'Commande client','purchase-orders'=>'Commande fournisseur','goods-receipts'=>'Réception','supplier-invoices'=>'Facture fournisseur','stock'=>'Produit','reminders'=>'Facture client'][$source];
                $subjectId = match($source) { 'operations'=>$entry->subject_id, 'sales-orders'=>$entry->sales_order_id, 'purchase-orders'=>$entry->purchase_order_id, 'goods-receipts'=>$entry->goods_receipt_id, 'supplier-invoices'=>$entry->supplier_invoice_id, 'stock'=>$entry->product_id, default=>$entry->invoice_id };
                $old = $entry->old_values ?? ($entry->metadata['old_values'] ?? null);
                $new = $entry->new_values ?? ($entry->metadata['new_values'] ?? null);
                if ($source === 'stock') { $old = ['quantity'=>$entry->quantity_before]; $new = ['quantity'=>$entry->quantity_after]; }
            @endphp
            <article wire:key="audit-{{ $source }}-{{ $entry->id }}" class="erp-timeline-card">
                <div class="flex flex-wrap items-start justify-between gap-3"><div class="space-y-3"><x-status-badge :status="$event" :label="__($actionLabels[$event] ?? ucfirst(str_replace('_',' ', $event)))" /><h3 class="text-sm font-semibold"><span dir="auto">{{ __($subjectLabel) }}</span> <span class="font-normal text-slate-500 erp-ltr">#{{ $subjectId }}</span></h3></div><time class="text-xs text-slate-500" datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->format('d/m/Y H:i:s') }}</time></div>
                <p class="mt-3 text-xs text-slate-500">{{ $entry->{$relation}?->name ?? __('Système / utilisateur supprimé') }} · {{ __('Source : :source', ['source' => $source === 'operations' ? __('Historique complémentaire') : __($subjectLabel)]) }}</p>
                @if($entry->description)<p class="mt-2 text-sm">{{ $entry->description }}</p>@endif
                <details class="mt-4 border-t border-slate-100 pt-4"><summary class="cursor-pointer text-sm font-semibold text-indigo-700">{{ __('Voir détails') }}</summary>
                    <dl class="my-4 grid gap-2 text-xs sm:grid-cols-2"><div><dt class="text-slate-500">{{ __('Événement') }}</dt><dd class="erp-ltr">#{{ $entry->id }} · {{ __($actionLabels[$event] ?? $event) }}</dd></div><div><dt class="text-slate-500">{{ __('Adresse IP') }}</dt><dd class="erp-ltr">{{ $entry->ip ?? __('Non enregistrée') }}</dd></div></dl>
                    <div class="grid gap-4 xl:grid-cols-2"><div><h4 class="mb-2 text-xs font-semibold">{{ __('Avant') }}</h4><pre class="erp-json">{{ $old === null || $old === [] ? __('Aucune valeur antérieure enregistrée.') : json_encode($old, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div><div><h4 class="mb-2 text-xs font-semibold">{{ __('Après') }}</h4><pre class="erp-json">{{ $new === null || $new === [] ? __('Aucune nouvelle valeur enregistrée.') : json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></div>
                    <h4 class="mb-2 mt-4 text-xs font-semibold">{{ __('Métadonnées') }}</h4><pre class="erp-json">{{ empty($entry->metadata) ? __('Aucune métadonnée disponible.') : json_encode($entry->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </details>
            </article>
        @empty <x-empty-state title="Aucune opération" description="Aucun événement ne correspond aux filtres sélectionnés." /> @endforelse
    </div>
    {{ $entries->links() }}
</section>
