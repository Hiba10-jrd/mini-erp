<?php

use App\Models\DeliveryNote;
use App\Services\DeliveryNoteManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public int $noteId;

    public function mount(int $noteId): void
    {
        Gate::authorize('sales.view');
        $this->noteId = $noteId;
    }

    public function validateNote(DeliveryNoteManagementService $service): void
    {
        Gate::authorize('sales.update');
        $note = DeliveryNote::query()->findOrFail($this->noteId);
        $service->validate($note);
        $this->redirectRoute('sales.delivery-notes.show', ['deliveryNote' => $note->id], navigate: true);
    }

    public function cancelNote(DeliveryNoteManagementService $service): void
    {
        Gate::authorize('sales.delete');
        $note = DeliveryNote::query()->findOrFail($this->noteId);
        $service->cancelDraft($note);
        $this->redirectRoute('sales.delivery-notes.show', ['deliveryNote' => $note->id], navigate: true);
    }

    public function with(): array
    {
        Gate::authorize('sales.view');

        return ['deliveryNote' => DeliveryNote::query()->with(['salesOrder.customer', 'salesOrder.items', 'warehouse', 'creator', 'validator', 'items'])->findOrFail($this->noteId)];
    }
}; ?>

<section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('sales.delivery-notes.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:text-indigo-900">{{ __('Retour aux bons de livraison') }}</a>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('sales.delivery-notes.pdf', $deliveryNote) }}" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Télécharger le PDF') }}</a>
            @if ($deliveryNote->isEditable())
                @can('sales.update')
                    <a href="{{ route('sales.delivery-notes.edit', $deliveryNote) }}" wire:navigate class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Modifier') }}</a>
                    <x-primary-button type="button" wire:click="validateNote">{{ __('Valider la livraison') }}</x-primary-button>
                @endcan
                @can('sales.delete')
                    <x-danger-button type="button" wire:click="cancelNote" wire:confirm="{{ __('Annuler ce bon de livraison ?') }}">{{ __('Annuler') }}</x-danger-button>
                @endcan
            @endif
        </div>
    </div>

    @php
        $statusLabels = ['draft' => __('Brouillon'), 'validated' => __('Validé'), 'cancelled' => __('Annulé')];
        $statusClasses = ['draft' => 'bg-gray-100 text-gray-700', 'validated' => 'bg-emerald-100 text-emerald-800', 'cancelled' => 'bg-rose-100 text-rose-800'];
    @endphp

    <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
        <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-indigo-700">{{ $deliveryNote->number }}</p>
                <h3 class="mt-1 text-xl font-semibold text-gray-900">{{ $deliveryNote->salesOrder->customer_name }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('Commande') }} : {{ $deliveryNote->salesOrder->number }}</p>
            </div>
            <span class="inline-block self-start px-3 py-1 text-sm font-medium {{ $statusClasses[$deliveryNote->status] ?? 'bg-gray-100 text-gray-700' }}">{{ $statusLabels[$deliveryNote->status] ?? $deliveryNote->status }}</span>
        </div>

        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <dt class="text-gray-500">{{ __('Date de livraison') }}</dt>
                <dd class="mt-1 font-medium text-gray-900">{{ $deliveryNote->delivery_date->format('d/m/Y') }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('Dépôt') }}</dt>
                <dd class="mt-1 font-medium text-gray-900">{{ $deliveryNote->warehouse?->code ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('Créé par') }}</dt>
                <dd class="mt-1 font-medium text-gray-900">{{ $deliveryNote->creator?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('Validé par') }}</dt>
                <dd class="mt-1 font-medium text-gray-900">{{ $deliveryNote->validator?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('Créé le') }}</dt>
                <dd class="mt-1 font-medium text-gray-900">{{ $deliveryNote->created_at->format('d/m/Y H:i') }}</dd>
            </div>
        </dl>
    </section>

    <section class="overflow-hidden border-y border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Désignation') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Quantité') }}</th>
                        <th class="px-4 py-3">{{ __('Unité') }}</th>
                        <th class="px-4 py-3">{{ __('Référence') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($deliveryNote->items as $item)
                        <tr>
                            <td class="min-w-56 px-4 py-4">
                                <p class="font-medium text-gray-900">{{ $item->description }}</p>
                                <p class="text-xs text-gray-500">{{ $item->item_type === 'service' ? __('Service') : __('Produit') }}</p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-medium text-gray-900">{{ $item->quantity }}</td>
                            <td class="whitespace-nowrap px-4 py-4">{{ $item->unit_label ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $item->reference ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if ($deliveryNote->notes)
        <section class="border-y border-gray-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-gray-900">{{ __('Notes') }}</h3>
            <p class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ $deliveryNote->notes }}</p>
        </section>
    @endif
</section>
