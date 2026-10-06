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
        return ['entries' => $query->paginate(20)];
    }
}; ?>
<section class="space-y-4">
    <h2 class="text-xl font-semibold">Audit des opérations</h2>
    <form wire:submit="$refresh" class="grid gap-3 rounded border border-gray-200 bg-white p-4 sm:grid-cols-2">
            <label class="grid gap-2">Source<select wire:model.live="source"><option value="operations">Opérations complémentaires</option><option value="sales-orders">Commandes clients</option><option value="purchase-orders">Commandes fournisseurs</option><option value="goods-receipts">Réceptions</option><option value="supplier-invoices">Factures fournisseurs</option><option value="stock">Mouvements de stock</option><option value="reminders">Relances</option></select></label>
            <label class="grid gap-2">Utilisateur (ID)<input type="number" wire:model.live.debounce.300ms="user" placeholder="Tous les utilisateurs" /></label>
            <label class="grid gap-2">Action<select wire:model.live="action"><option value="">Toutes les actions</option><option value="created">Création</option><option value="updated">Modification</option><option value="deleted">Suppression</option><option value="permissions.changed">Permissions modifiées</option><option value="roles.changed">Rôles modifiés</option><option value="attachment.uploaded">Pièce jointe ajoutée</option><option value="attachment.deleted">Pièce jointe retirée</option><option value="validated">Validation</option><option value="confirmed">Confirmation</option><option value="cancelled">Annulation</option><option value="draft_updated">Brouillon modifié</option><option value="entry">Entrée</option><option value="exit">Sortie</option><option value="manual">Relance manuelle</option><option value="email">Relance email</option><option value="phone">Relance téléphone</option><option value="whatsapp">Relance WhatsApp</option></select></label>
            @if($source === 'operations')<label class="grid gap-2">Entité<input wire:model.live.debounce.300ms="entity" placeholder="Ex. invoice, expense, user" /></label>@endif
            <label class="grid gap-2">Du<input type="date" wire:model.live="from" /></label><label class="grid gap-2">Au<input type="date" wire:model.live="to" /></label>
        <div><button type="submit" class="rounded border border-gray-300 px-3 py-2 text-sm">Appliquer</button></div>
        @foreach($errors->all() as $error)<p role="alert" class="text-sm text-red-600">{{ $error }}</p>@endforeach
    </form>
    <ul class="divide-y rounded border border-gray-200 bg-white px-4">
        @forelse($entries as $entry)
            @php
                $relation = AuditQueryService::SOURCES[$source][3];
                $actionColumn = AuditQueryService::SOURCES[$source][2];
                $event = $entry->{$actionColumn};
                $subjectId = match($source) { 'operations'=>$entry->subject_id, 'sales-orders'=>$entry->sales_order_id, 'purchase-orders'=>$entry->purchase_order_id, 'goods-receipts'=>$entry->goods_receipt_id, 'supplier-invoices'=>$entry->supplier_invoice_id, 'stock'=>$entry->product_id, default=>$entry->invoice_id };
                $old = $entry->old_values ?? ($entry->metadata['old_values'] ?? null);
                $new = $entry->new_values ?? ($entry->metadata['new_values'] ?? null);
                if ($source === 'stock') { $old = ['quantity'=>$entry->quantity_before]; $new = ['quantity'=>$entry->quantity_after]; }
            @endphp
            <li wire:key="audit-{{ $source }}-{{ $entry->id }}" class="space-y-2 py-4 text-sm">
                <p class="font-semibold">{{ $event }} — {{ $source === 'operations' ? $entry->subject_type : $source }} #{{ $subjectId }}</p>
                <p>{{ $entry->{$relation}?->name ?? 'Système / utilisateur supprimé' }} · {{ $entry->created_at->format('d/m/Y H:i:s') }}</p>
                <p>Source : {{ $source }} · Événement #{{ $entry->id }} · IP : {{ $entry->ip ?? 'Non enregistrée' }}</p>
                @if($entry->description)<p>{{ $entry->description }}</p>@endif
                <p class="font-medium">Avant</p>
                <pre class="overflow-x-auto whitespace-pre-wrap text-xs">{{ $old === null || $old === [] ? 'Aucune valeur antérieure enregistrée.' : json_encode($old, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                <p class="font-medium">Après</p>
                <pre class="overflow-x-auto whitespace-pre-wrap text-xs">{{ $new === null || $new === [] ? 'Aucune nouvelle valeur enregistrée.' : json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                <p class="font-medium">Métadonnées</p>
                <pre class="overflow-x-auto whitespace-pre-wrap text-xs">{{ empty($entry->metadata) ? 'Aucune métadonnée disponible.' : json_encode($entry->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </li>
        @empty
            <li class="py-4 text-sm text-gray-500">Aucune opération.</li>
        @endforelse
    </ul>
    {{ $entries->links() }}
</section>
