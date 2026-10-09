<?php

use App\Models\GoodsReceipt;
use App\Models\SupplierInvoice;
use App\Services\GoodsReceiptManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public int $receiptId;

    public function mount(int $receiptId): void
    {
        Gate::authorize('purchases.view');

        $this->receiptId = $receiptId;
    }

    public function validateReceipt(
        GoodsReceiptManagementService $service
    ): void {
        Gate::authorize('purchases.update');

        $receipt = $service->validate(
            GoodsReceipt::query()->findOrFail($this->receiptId)
        );

        foreach (
            $receipt->items
                ->where('item_type', 'product')
                ->whereNotNull('product_id') as $item
        ) {
            $this->dispatch(
                'stock-updated',
                productId: $item->product_id
            );
        }

        session()->flash(
            'status',
            __('La réception fournisseur a été validée avec succès.')
        );
    }

    public function cancelReceipt(
        GoodsReceiptManagementService $service
    ): void {
        Gate::authorize('purchases.delete');

        $service->cancelDraft(
            GoodsReceipt::query()->findOrFail($this->receiptId)
        );

        session()->flash(
            'status',
            __('Le brouillon de réception a été annulé.')
        );
    }

    public function with(): array
    {
        Gate::authorize('purchases.view');

        $receipt = GoodsReceipt::query()
            ->with([
                'purchaseOrder',
                'warehouse',
                'creator',
                'validator',
                'items',
                'histories.user',
            ])
            ->findOrFail($this->receiptId);

        return [
            'receipt' => $receipt,
            'supplierInvoices' => SupplierInvoice::query()
                ->whereHas('items.goodsReceiptItem', fn ($query) => $query->where('goods_receipt_id', $receipt->id))
                ->latest('invoice_date')
                ->latest('id')
                ->get(),
        ];
    }
}; ?>

<section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a
            href="{{ route('purchases.receipts.index') }}"
            wire:navigate
            class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
        >
            {{ __('Retour aux réceptions') }}
        </a>

        <div class="flex flex-wrap gap-2">
            @if ($receipt->isEditable())
                @can('purchases.update')
                    <a
                        href="{{ route('purchases.receipts.edit', $receipt) }}"
                        wire:navigate
                        class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        {{ __('Modifier') }}
                    </a>

                    <x-primary-button
                        type="button"
                        wire:click="validateReceipt"
                        wire:confirm="{{ __('Valider définitivement cette réception et mettre à jour le stock ?') }}"
                        wire:loading.attr="disabled"
                        wire:target="validateReceipt"
                    >
                        {{ __('Valider la réception') }}
                    </x-primary-button>
                @endcan

                @can('purchases.delete')
                    <x-danger-button
                        type="button"
                        wire:click="cancelReceipt"
                        wire:confirm="{{ __('Annuler ce brouillon ?') }}"
                        wire:loading.attr="disabled"
                        wire:target="cancelReceipt"
                    >
                        {{ __('Annuler') }}
                    </x-danger-button>
                @endcan
            @endif
        </div>
    </div>

    @if (session('status'))
        <div
            class="border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700"
        >
            {{ session('status') }}
        </div>
    @endif

    @php
        $statusLabels = [
            'draft' => __('Brouillon'),
            'validated' => __('Validée'),
            'cancelled' => __('Annulée'),
        ];

        $statusClasses = [
            'draft' => 'bg-gray-100 text-gray-700',
            'validated' => 'bg-emerald-100 text-emerald-800',
            'cancelled' => 'bg-rose-100 text-rose-800',
        ];

        $historyLabels = [
            'created' => __('Création'),
            'draft_updated' => __('Modification'),
            'validated' => __('Validation de la réception'),
            'cancelled' => __('Annulation'),
        ];
    @endphp

    <x-input-error
        :messages="$errors->get('status')"
        class="mt-2"
    />

    <x-input-error
        :messages="$errors->get('items')"
        class="mt-2"
    />

    <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
        <div
            class="flex flex-col gap-4 border-b border-gray-200 pb-5 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <p class="text-sm font-semibold text-indigo-700">
                    {{ $receipt->number }}
                </p>

                <h3 class="mt-1 text-xl font-semibold text-gray-900">
                    {{ $receipt->purchaseOrder->supplier_name }}
                </h3>

                <a
                    href="{{ route(
                        'purchases.orders.show',
                        $receipt->purchaseOrder
                    ) }}"
                    wire:navigate
                    class="mt-1 inline-block text-sm text-indigo-700 hover:text-indigo-900"
                >
                    {{ $receipt->purchaseOrder->number }}
                </a>
            </div>

            <span
                class="inline-block self-start px-3 py-1 text-sm font-medium {{ $statusClasses[$receipt->status] ?? 'bg-gray-100 text-gray-700' }}"
            >
                {{
                    $statusLabels[$receipt->status]
                        ?? $receipt->status
                }}
            </span>
        </div>

        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-gray-500">
                    {{ __('Date de réception') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $receipt->receipt_date->format('d/m/Y') }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Dépôt') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $receipt->warehouse->code }}
                    ·
                    {{ $receipt->warehouse->name }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Créé par') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $receipt->creator?->name ?? '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Validé par') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $receipt->validator?->name ?? '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Validée le') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{
                        $receipt->validated_at?->format('d/m/Y H:i')
                            ?? '—'
                    }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Annulée le') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{
                        $receipt->cancelled_at?->format('d/m/Y H:i')
                            ?? '—'
                    }}
                </dd>
            </div>
        </dl>

        @if ($receipt->notes)
            <div class="mt-5 border-t border-gray-200 pt-4">
                <p class="text-sm font-semibold text-gray-900">
                    {{ __('Notes') }}
                </p>

                <p class="mt-1 whitespace-pre-line text-sm text-gray-600">
                    {{ $receipt->notes }}
                </p>
            </div>
        @endif
    </section>

    <section class="overflow-hidden border-y border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead
                    class="bg-gray-50 text-start text-xs uppercase text-gray-500"
                >
                    <tr>
                        <th class="px-4 py-3">
                            {{ __('Article') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Type') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Unité') }}
                        </th>

                        <th class="px-4 py-3 text-end">
                            {{ __('Quantité reçue') }}
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @foreach ($receipt->items as $item)
                        <tr>
                            <td class="px-4 py-4">
                                <p class="font-medium text-gray-900">
                                    {{ $item->description }}
                                </p>

                                <p class="text-xs text-gray-500">
                                    {{ $item->reference }}
                                </p>
                            </td>

                            <td class="px-4 py-4">
                                {{
                                    $item->item_type === 'service'
                                        ? __('Service')
                                        : __('Produit physique')
                                }}
                            </td>

                            <td class="px-4 py-4">
                                {{ $item->unit_label }}
                            </td>

                            <td class="px-4 py-4 text-end font-medium">
                                {{ $item->quantity }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    </section>

    <section class="border-y border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-4">
            <h3 class="font-semibold text-gray-900">{{ __('Factures fournisseurs liées') }}</h3>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse($supplierInvoices as $invoice)
                <a href="{{ route('purchases.invoices.show', $invoice) }}" wire:navigate class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-gray-50">
                    <div><p class="font-medium text-indigo-700">{{ $invoice->number ?? __('Brouillon #:id', ['id' => $invoice->id]) }}</p><p class="text-sm text-gray-500">{{ $invoice->supplier_invoice_number }}</p></div>
                    <span class="text-sm text-gray-600">{{ ['draft' => __('Brouillon'), 'validated' => __('Validée'), 'cancelled' => __('Annulée')][$invoice->status] ?? $invoice->status }}</span>
                </a>
            @empty
                <p class="px-5 py-4 text-sm text-gray-500">{{ __('Aucune facture fournisseur liée à cette réception.') }}</p>
            @endforelse
        </div>
    </section>

    <section class="border-y border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-4">
            <h3 class="font-semibold text-gray-900">
                {{ __('Historique') }}
            </h3>
        </div>

        @if ($receipt->histories->isEmpty())
            <div class="px-5 py-6 text-sm text-gray-500">
                {{ __('Aucun historique disponible.') }}
            </div>
        @else
            <ol class="divide-y divide-gray-100">
                @foreach ($receipt->histories as $history)
                    @php
                        $historyLabel =
                            $historyLabels[$history->event]
                            ?? $history->event;
                    @endphp

                    <li
                        class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
                    >
                        <div>
                            <p class="font-medium text-gray-900">
                                {{ $historyLabel }}
                            </p>

                            <p class="text-sm text-gray-600">
                                {{ !empty($history->metadata['description_key']) ? __($history->metadata['description_key'], $history->metadata['description_params'] ?? []) : __($history->description) }}
                            </p>

                            <p class="text-xs text-gray-500">
                                {{ $history->user?->name ?? '—' }}
                            </p>
                        </div>

                        <time
                            class="whitespace-nowrap text-sm text-gray-500"
                        >
                            {{
                                $history->created_at?->format(
                                    'd/m/Y H:i'
                                ) ?? '—'
                            }}
                        </time>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
</section>
