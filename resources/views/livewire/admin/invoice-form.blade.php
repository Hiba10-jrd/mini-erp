<?php

use App\Models\SalesOrder;
use App\Services\InvoiceManagementService;
use App\Services\QuoteCalculator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public int $orderId;

    public string $invoiceDate = '';

    public string $notes = '';

    public string $terms = '';

    /**
     * @var array<int, array{
     *     delivery_note_item_id:int,
     *     selected:bool,
     *     quantity:string,
     *     remaining_quantity:string,
     *     delivered_quantity:string,
     *     already_invoiced_quantity:string,
     *     reference:?string,
     *     description:string,
     *     unit_label:?string,
     *     unit_price:string,
     *     discount_percent:string,
     *     tax_rate_percent:string
     * }>
     */
    public array $lines = [];

    public function mount(
        int $orderId,
        InvoiceManagementService $service
    ): void {
        Gate::authorize('invoices.create');

        $this->orderId = $orderId;
        $this->invoiceDate = today()->toDateString();

        $order = SalesOrder::query()
            ->findOrFail($this->orderId);

        $this->terms = $order->terms ?? '';

        $available = $service->availableItemsForOrder($order);

        abort_if(
            $available === [],
            422,
            __('Aucune quantité livrée ne reste à facturer.')
        );

        foreach ($available as $line) {
            $sourceId = (int) $line['delivery_note_item_id'];

            $this->lines[$sourceId] = [
                'delivery_note_item_id' => $sourceId,
                'selected' => true,
                'quantity' => $line['remaining_quantity'],
                'remaining_quantity' => $line['remaining_quantity'],
                'delivered_quantity' => $line['delivered_quantity'],
                'already_invoiced_quantity' => $line['already_invoiced_quantity'],
                'reference' => $line['reference'],
                'description' => $line['description'],
                'unit_label' => $line['unit_label'],
                'unit_price' => $line['unit_price'],
                'discount_percent' => $line['discount_percent'],
                'tax_rate_percent' => $line['tax_rate_percent'],
            ];
        }
    }

    public function save(InvoiceManagementService $service): void
    {
        Gate::authorize('invoices.create');

        $items = collect($this->lines)
            ->filter(
                fn (array $line): bool => (bool) ($line['selected'] ?? false)
            )
            ->map(
                fn (array $line): array => [
                    'delivery_note_item_id' => $line['delivery_note_item_id'],
                    'quantity' => $line['quantity'],
                ]
            )
            ->values()
            ->all();

        if ($items === []) {
            $this->addError(
                'lines',
                __('Sélectionnez au moins une ligne à facturer.')
            );

            return;
        }

        $invoice = $service->createForOrder(
            SalesOrder::query()->findOrFail($this->orderId),
            [
                'invoice_date' => $this->invoiceDate,
                'notes' => $this->notes,
                'terms' => $this->terms,
                'items' => $items,
            ]
        );

        session()->flash(
            'status',
            __('La facture brouillon a été créée.')
        );

        $this->redirectRoute(
            'sales.invoices.index',
            navigate: true
        );
    }

    public function with(QuoteCalculator $calculator): array
    {
        Gate::authorize('invoices.create');

        $order = SalesOrder::query()
            ->findOrFail($this->orderId);

        $calculatedLines = [];

        foreach ($this->lines as $sourceId => $line) {
            if (! ($line['selected'] ?? false)) {
                continue;
            }

            try {
                $calculatedLines[$sourceId] = $calculator->line(
                    (string) $line['quantity'],
                    (string) $line['unit_price'],
                    (string) $line['discount_percent'],
                    (string) $line['tax_rate_percent'],
                );
            } catch (\Throwable) {
                $calculatedLines[$sourceId] = null;
            }
        }

        $validLines = collect($calculatedLines)
            ->filter()
            ->values()
            ->all();

        return [
            'order' => $order,
            'calculatedLines' => $calculatedLines,
            'previewTotals' => $calculator->totals($validLines),
        ];
    }
}; ?>

<section class="space-y-6">
    <div class="flex justify-end">
        <a
            href="{{ route('sales.orders.show', $order) }}"
            wire:navigate
            class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
        >
            {{ __('Retour à la commande') }}
        </a>
    </div>

    @if ($errors->any())
        <div class="border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
            <ul class="list-disc space-y-1 ps-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
            <h3 class="text-base font-semibold text-gray-900">
                {{ __('Informations de la facture') }}
            </h3>

            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <x-input-label
                        for="invoice-order"
                        :value="__('Commande')"
                    />

                    <x-text-input
                        id="invoice-order"
                        value="{{ $order->number }}"
                        disabled
                        class="mt-1 block w-full bg-gray-50"
                    />
                </div>

                <div>
                    <x-input-label
                        for="invoice-customer"
                        :value="__('Client')"
                    />

                    <x-text-input
                        id="invoice-customer"
                        value="{{ $order->customer_name }}"
                        disabled
                        class="mt-1 block w-full bg-gray-50"
                    />
                </div>

                <div>
                    <x-input-label
                        for="invoice-date"
                        :value="__('Date de facture')"
                    />

                    <x-text-input
                        id="invoice-date"
                        type="date"
                        wire:model="invoiceDate"
                        required
                        class="mt-1 block w-full"
                    />

                    <x-input-error
                        :messages="$errors->get('invoice_date')"
                        class="mt-2"
                    />
                </div>

                <div class="sm:col-span-2 lg:col-span-3">
                    <x-input-label
                        for="invoice-terms"
                        :value="__('Conditions')"
                    />

                    <textarea
                        id="invoice-terms"
                        wire:model="terms"
                        rows="3"
                        class="mt-1 block w-full border-gray-300 shadow-sm"
                    ></textarea>
                </div>

                <div class="sm:col-span-2 lg:col-span-3">
                    <x-input-label
                        for="invoice-notes"
                        :value="__('Notes')"
                    />

                    <textarea
                        id="invoice-notes"
                        wire:model="notes"
                        rows="3"
                        class="mt-1 block w-full border-gray-300 shadow-sm"
                    ></textarea>
                </div>
            </div>
        </section>

        <section class="border-y border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-5 py-4 sm:px-6">
                <h3 class="text-base font-semibold text-gray-900">
                    {{ __('Quantités à facturer') }}
                </h3>

                <p class="mt-1 text-sm text-gray-500">
                    {{ __('Les quantités disponibles proviennent uniquement des bons de livraison validés.') }}
                </p>
            </div>

            <div class="divide-y divide-gray-200">
                @foreach ($lines as $sourceId => $line)
                    <div
                        wire:key="invoice-source-{{ $sourceId }}"
                        class="p-5 sm:p-6"
                    >
                        <div class="grid gap-4 lg:grid-cols-12 lg:items-end">
                            <div class="lg:col-span-1">
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input
                                        type="checkbox"
                                        wire:model.live="lines.{{ $sourceId }}.selected"
                                        class="border-gray-300"
                                    >

                                    {{ __('Inclure') }}
                                </label>
                            </div>

                            <div class="lg:col-span-4">
                                <p class="font-medium text-gray-900">
                                    {{ $line['description'] }}
                                </p>

                                <p class="mt-1 text-xs text-gray-500">
                                    {{ $line['reference'] ?? '—' }}
                                    ·
                                    {{ $line['unit_label'] ?? '—' }}
                                </p>
                            </div>

                            <div class="lg:col-span-2">
                                <p class="text-xs text-gray-500">
                                    {{ __('Livré') }}
                                </p>

                                <p class="mt-1 font-medium text-gray-900">
                                    {{ $line['delivered_quantity'] }}
                                </p>
                            </div>

                            <div class="lg:col-span-2">
                                <p class="text-xs text-gray-500">
                                    {{ __('Déjà facturé/réservé') }}
                                </p>

                                <p class="mt-1 font-medium text-gray-900">
                                    {{ $line['already_invoiced_quantity'] }}
                                </p>
                            </div>

                            <div class="lg:col-span-3">
                                <x-input-label
                                    :for="'invoice-quantity-'.$sourceId"
                                    :value="__('Quantité à facturer')"
                                />

                                <x-text-input
                                    id="invoice-quantity-{{ $sourceId }}"
                                    type="number"
                                    min="0.001"
                                    max="{{ $line['remaining_quantity'] }}"
                                    step="0.001"
                                    wire:model.live="lines.{{ $sourceId }}.quantity"
                                    :disabled="! $line['selected']"
                                    class="mt-1 block w-full"
                                />

                                <p class="mt-1 text-xs text-gray-500">
                                    {{ __('Maximum') }} :
                                    {{ $line['remaining_quantity'] }}
                                </p>
                            </div>
                        </div>

                        @if ($line['selected'])
                            <div class="mt-4 grid gap-3 border-t border-gray-100 pt-4 text-sm sm:grid-cols-4">
                                <div>
                                    <span class="text-gray-500">
                                        {{ __('PU HT') }}
                                    </span>

                                    <strong class="block text-gray-900">
                                        {{ str_replace('.', ',', $line['unit_price']) }}
                                    </strong>
                                </div>

                                <div>
                                    <span class="text-gray-500">
                                        {{ __('Remise') }}
                                    </span>

                                    <strong class="block text-gray-900">
                                        {{ str_replace('.', ',', $line['discount_percent']) }} %
                                    </strong>
                                </div>

                                <div>
                                    <span class="text-gray-500">
                                        {{ __('TVA') }}
                                    </span>

                                    <strong class="block text-gray-900">
                                        {{ str_replace('.', ',', $line['tax_rate_percent']) }} %
                                    </strong>
                                </div>

                                <div>
                                    <span class="text-gray-500">
                                        {{ __('TTC ligne') }}
                                    </span>

                                    <strong class="block text-gray-900">
                                        {{ isset($calculatedLines[$sourceId]['total_ttc'])
                                            ? str_replace('.', ',', $calculatedLines[$sourceId]['total_ttc'])
                                            : '—' }}
                                    </strong>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
            <h3 class="text-base font-semibold text-gray-900">
                {{ __('Synthèse prévisionnelle') }}
            </h3>

            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-gray-500">
                        {{ __('Remises') }}
                    </dt>

                    <dd class="font-medium text-gray-900">
                        {{ str_replace('.', ',', $previewTotals['discount_total']) }}
                    </dd>
                </div>

                <div>
                    <dt class="text-gray-500">
                        {{ __('HT net') }}
                    </dt>

                    <dd class="font-medium text-gray-900">
                        {{ str_replace('.', ',', $previewTotals['base_ht']) }}
                    </dd>
                </div>

                <div>
                    <dt class="text-gray-500">
                        {{ __('TVA') }}
                    </dt>

                    <dd class="font-medium text-gray-900">
                        {{ str_replace('.', ',', $previewTotals['tax_total']) }}
                    </dd>
                </div>

                <div>
                    <dt class="text-gray-500">
                        {{ __('TTC') }}
                    </dt>

                    <dd class="text-lg font-semibold text-gray-950">
                        {{ str_replace('.', ',', $previewTotals['total_ttc']) }}
                    </dd>
                </div>
            </dl>
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <a
                href="{{ route('sales.orders.show', $order) }}"
                wire:navigate
                class="inline-flex min-h-10 items-center justify-center border border-gray-300 px-4 text-sm font-medium text-gray-700"
            >
                {{ __('Annuler') }}
            </a>

            <x-primary-button
                type="submit"
                class="justify-center"
            >
                {{ __('Enregistrer le brouillon') }}
            </x-primary-button>
        </div>
    </form>
</section>