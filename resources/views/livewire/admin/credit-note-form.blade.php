<?php

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\CreditNoteManagementService;
use App\Services\QuoteCalculator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public int $invoiceId;

    #[Locked]
    public ?int $creditNoteId = null;

    public string $creditDate = '';

    public ?string $reason = null;

    public ?string $notes = null;

    public array $lines = [];

    public function mount(
        int $invoiceId,
        ?int $creditNoteId = null,
        ?CreditNoteManagementService $service = null
    ): void {
        Gate::authorize('invoices.create');

        $this->invoiceId = $invoiceId;
        $this->creditNoteId = $creditNoteId;

        $invoice = Invoice::query()->findOrFail($invoiceId);

        $creditNote = null;

        if ($creditNoteId !== null) {
            $creditNote = CreditNote::query()
                ->with('items')
                ->findOrFail($creditNoteId);

            abort_unless(
                $creditNote->invoice_id === $invoice->id,
                404
            );

            abort_unless($creditNote->isEditable(), 404);

            $this->creditDate = $creditNote->credit_date->toDateString();
            $this->reason = $creditNote->reason;
            $this->notes = $creditNote->notes;
        } else {
            $this->creditDate = today()->toDateString();
        }

        $available = $service->availableItemsForInvoice(
            $invoice,
            $creditNote
        );

        $existing = $creditNote?->items
            ->keyBy('invoice_item_id');

        $this->lines = collect($available)
            ->map(function (array $line) use ($existing): array {
                $current = $existing?->get(
                    $line['invoice_item_id']
                );

                return [
                    ...$line,
                    'selected' => $current !== null || $existing === null,
                    'quantity' => $current?->quantity
                        ?? $line['remaining_quantity'],
                ];
            })
            ->values()
            ->all();
    }

    public function save(
        CreditNoteManagementService $service
    ): void {
        Gate::authorize('invoices.create');

        $invoice = Invoice::query()->findOrFail(
            $this->invoiceId
        );

        $items = collect($this->lines)
            ->filter(fn (array $line): bool => (bool) $line['selected'])
            ->map(fn (array $line): array => [
                'invoice_item_id' => $line['invoice_item_id'],
                'quantity' => $line['quantity'],
            ])
            ->values()
            ->all();

        if ($items === []) {
            $this->addError(
                'items',
                __('Sélectionnez au moins une ligne à créditer.')
            );

            return;
        }

        $payload = [
            'credit_date' => $this->creditDate,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'items' => $items,
        ];

        if ($this->creditNoteId === null) {
            $creditNote = $service->createForInvoice(
                $invoice,
                $payload
            );
        } else {
            $creditNote = $service->updateDraft(
                CreditNote::query()->findOrFail(
                    $this->creditNoteId
                ),
                $payload
            );
        }

        session()->flash(
            'status',
            __('Le brouillon d’avoir a été enregistré.')
        );

        $this->redirectRoute(
            'sales.credit-notes.show',
            ['creditNote' => $creditNote],
            navigate: true
        );
    }

    public function with(
        QuoteCalculator $calculator
    ): array {
        Gate::authorize('invoices.create');

        $invoice = Invoice::query()
            ->findOrFail($this->invoiceId);

        $calculated = [];

        foreach ($this->lines as $line) {
            if (! ($line['selected'] ?? false)) {
                continue;
            }

            try {
                $calculated[] = $calculator->line(
                    (string) $line['quantity'],
                    (string) $line['unit_price'],
                    (string) $line['discount_percent'],
                    (string) $line['tax_rate_percent']
                );
            } catch (\Throwable) {
                // Le service métier fera la validation définitive.
            }
        }

        return [
            'invoice' => $invoice,
            'preview' => $calculator->totals($calculated),
        ];
    }
}; ?>

<section class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <a
            href="{{ route('sales.invoices.show', $invoice) }}"
            wire:navigate
            class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
        >
            {{ __('Retour à la facture') }}
        </a>

        <span class="text-sm text-gray-500">
            {{ $invoice->number }}
        </span>
    </div>

    @if ($lines === [])
        <div class="border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
            {{ __('Cette facture ne contient plus aucune quantité disponible à créditer.') }}
        </div>
    @else
        <form wire:submit="save" class="space-y-6">
            <section class="border-y border-gray-200 bg-white p-5">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <x-input-label for="credit-date" :value="__('Date de l’avoir')" />

                        <x-text-input
                            id="credit-date"
                            type="date"
                            wire:model="creditDate"
                            class="mt-1 block w-full"
                        />

                        <x-input-error :messages="$errors->get('credit_date')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="credit-reason" :value="__('Motif')" />

                        <x-text-input
                            id="credit-reason"
                            wire:model="reason"
                            class="mt-1 block w-full"
                            placeholder="{{ __('Ex. retour client, correction de quantité') }}"
                        />

                        <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                    </div>

                    <div class="md:col-span-2">
                        <x-input-label for="credit-notes" :value="__('Notes')" />

                        <textarea
                            id="credit-notes"
                            wire:model="notes"
                            rows="3"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        ></textarea>

                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>
                </div>
            </section>

            <section class="overflow-x-auto border-y border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3"></th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">
                                {{ __('Article') }}
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">
                                {{ __('Facturé') }}
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">
                                {{ __('Déjà crédité') }}
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">
                                {{ __('Disponible') }}
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">
                                {{ __('Qté avoir') }}
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @foreach ($lines as $index => $line)
                            <tr>
                                <td class="px-4 py-4">
                                    <input
                                        type="checkbox"
                                        wire:model.live="lines.{{ $index }}.selected"
                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    >
                                </td>

                                <td class="px-4 py-4">
                                    <p class="font-medium text-gray-900">
                                        {{ $line['description'] }}
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        {{ $line['reference'] ?? '—' }}
                                        · {{ number_format((float) $line['unit_price'], 2, ',', ' ') }}
                                        · TVA {{ $line['tax_rate_percent'] }} %
                                    </p>
                                </td>

                                <td class="px-4 py-4 text-right text-sm">
                                    {{ $line['invoiced_quantity'] }}
                                </td>

                                <td class="px-4 py-4 text-right text-sm">
                                    {{ $line['already_credited_quantity'] }}
                                </td>

                                <td class="px-4 py-4 text-right text-sm font-medium">
                                    {{ $line['remaining_quantity'] }}
                                </td>

                                <td class="px-4 py-4 text-right">
                                    <input
                                        type="number"
                                        step="0.001"
                                        min="0.001"
                                        max="{{ $line['remaining_quantity'] }}"
                                        wire:model.blur="lines.{{ $index }}.quantity"
                                        class="w-28 rounded-md border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <x-input-error :messages="$errors->get('items')" />

            <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
                <div></div>

                <section class="border-y border-gray-200 bg-white p-5">
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-600">{{ __('Brut HT') }}</dt>
                            <dd class="font-medium">{{ $preview['subtotal_ht'] }}</dd>
                        </div>

                        <div class="flex justify-between">
                            <dt class="text-gray-600">{{ __('Remises') }}</dt>
                            <dd class="font-medium">{{ $preview['discount_total'] }}</dd>
                        </div>

                        <div class="flex justify-between">
                            <dt class="text-gray-600">{{ __('TVA') }}</dt>
                            <dd class="font-medium">{{ $preview['tax_total'] }}</dd>
                        </div>

                        <div class="flex justify-between border-t border-gray-200 pt-3 text-base">
                            <dt class="font-semibold">{{ __('Total TTC') }}</dt>
                            <dd class="font-semibold">{{ $preview['total_ttc'] }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <div class="flex justify-end">
                <x-primary-button>
                    {{ $creditNoteId ? __('Enregistrer les modifications') : __('Enregistrer le brouillon') }}
                </x-primary-button>
            </div>
        </form>
    @endif
</section>