<?php

use App\Models\CreditNote;
use App\Services\CreditNoteManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public int $creditNoteId;

    public function mount(int $creditNoteId): void
    {
        Gate::authorize('invoices.view');

        $this->creditNoteId = $creditNoteId;
    }

    public function issueCreditNote(
        CreditNoteManagementService $service
    ): void {
        Gate::authorize('invoices.validate');

        $service->issue(
            CreditNote::query()->findOrFail(
                $this->creditNoteId
            )
        );

        session()->flash(
            'status',
            __('L’avoir a été émis avec succès.')
        );
    }

    public function cancelCreditNote(
        CreditNoteManagementService $service
    ): void {
        Gate::authorize('invoices.create');

        $service->cancelDraft(
            CreditNote::query()->findOrFail(
                $this->creditNoteId
            )
        );

        session()->flash(
            'status',
            __('Le brouillon d’avoir a été annulé.')
        );
    }

    public function with(): array
    {
        Gate::authorize('invoices.view');

        return [
            'creditNote' => CreditNote::query()
                ->with([
                    'invoice:id,number',
                    'creator:id,name',
                    'issuer:id,name',
                    'items',
                ])
                ->findOrFail($this->creditNoteId),
        ];
    }
}; ?>

<section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a
            href="{{ route('sales.credit-notes.index') }}"
            wire:navigate
            class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
        >
            {{ __('Retour aux avoirs') }}
        </a>

        <div class="flex flex-wrap gap-2">
            @if ($creditNote->isEditable())
                @can('invoices.create')
                    <a
                        href="{{ route('sales.credit-notes.edit', $creditNote) }}"
                        wire:navigate
                        class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        {{ __('Modifier') }}
                    </a>

                    <x-danger-button
                        type="button"
                        wire:click="cancelCreditNote"
                        wire:confirm="{{ __('Annuler ce brouillon d’avoir ?') }}"
                    >
                        {{ __('Annuler le brouillon') }}
                    </x-danger-button>
                @endcan

                @can('invoices.validate')
                    <x-primary-button
                        type="button"
                        wire:click="issueCreditNote"
                        wire:confirm="{{ __('Émettre définitivement cet avoir ? Après émission, il sera immuable.') }}"
                    >
                        {{ __('Émettre l’avoir') }}
                    </x-primary-button>
                @endcan
            @endif

            @if ($creditNote->isIssued())
                <a
                    href="{{ route('sales.credit-notes.pdf', $creditNote) }}"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    {{ __('Télécharger le PDF') }}
                </a>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            {{ session('status') }}
        </div>
    @endif

    <section class="border-y border-gray-200 bg-white p-5">
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs font-semibold uppercase text-gray-500">{{ __('Avoir') }}</p>
                <p class="mt-1 font-semibold text-gray-900">
                    {{ $creditNote->number ?? __('Brouillon #:id', ['id' => $creditNote->id]) }}
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase text-gray-500">{{ __('Facture') }}</p>
                <a
                    href="{{ route('sales.invoices.show', $creditNote->invoice) }}"
                    wire:navigate
                    class="mt-1 block font-medium text-indigo-700"
                >
                    {{ $creditNote->invoice->number }}
                </a>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase text-gray-500">{{ __('Date') }}</p>
                <p class="mt-1">{{ $creditNote->credit_date->format('d/m/Y') }}</p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase text-gray-500">{{ __('Statut') }}</p>
                <p class="mt-1 font-medium">
                    {{
                        match ($creditNote->status) {
                            'draft' => __('Brouillon'),
                            'issued' => __('Émis'),
                            'cancelled' => __('Annulé'),
                            default => $creditNote->status,
                        }
                    }}
                </p>
            </div>
        </div>
    </section>

    <section class="border-y border-gray-200 bg-white p-5">
        <h3 class="font-semibold text-gray-900">{{ __('Client') }}</h3>
        <p class="mt-2">{{ $creditNote->customer_name }}</p>

        @if ($creditNote->reason)
            <p class="mt-4 text-sm">
                <span class="font-medium">{{ __('Motif') }} :</span>
                {{ $creditNote->reason }}
            </p>
        @endif
    </section>

    <section class="overflow-x-auto border-y border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('Article') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ __('Qté') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ __('PU HT') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ __('TVA') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ __('TTC') }}</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">
                @foreach ($creditNote->items as $item)
                    <tr>
                        <td class="px-4 py-4">
                            <p class="font-medium">{{ $item->description }}</p>
                            <p class="text-xs text-gray-500">{{ $item->reference ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-4 text-right">{{ $item->quantity }}</td>
                        <td class="px-4 py-4 text-right">{{ number_format((float) $item->unit_price, 2, ',', ' ') }}</td>
                        <td class="px-4 py-4 text-right">{{ $item->tax_rate_percent }} %</td>
                        <td class="px-4 py-4 text-right font-medium">{{ number_format((float) $item->total_ttc, 2, ',', ' ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div>
            @if ($creditNote->notes)
                <section class="border-y border-gray-200 bg-white p-5">
                    <h3 class="font-semibold">{{ __('Notes') }}</h3>
                    <p class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ $creditNote->notes }}</p>
                </section>
            @endif
        </div>

        <section class="border-y border-gray-200 bg-white p-5">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-600">{{ __('Brut HT') }}</dt>
                    <dd class="font-medium">{{ $creditNote->subtotal_ht }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-600">{{ __('Remises') }}</dt>
                    <dd class="font-medium">{{ $creditNote->discount_total }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-600">{{ __('TVA') }}</dt>
                    <dd class="font-medium">{{ $creditNote->tax_total }}</dd>
                </div>
                <div class="flex justify-between border-t border-gray-200 pt-3 text-base">
                    <dt class="font-semibold">{{ __('Total TTC') }}</dt>
                    <dd class="font-semibold">{{ $creditNote->total_ttc }}</dd>
                </div>
            </dl>
        </section>
    </div>
</section>