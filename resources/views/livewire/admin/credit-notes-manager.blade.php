<?php

use App\Models\CreditNote;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        Gate::authorize('invoices.view');

        $creditNotes = CreditNote::query()
            ->with([
                'invoice:id,number',
                'creator:id,name',
                'issuer:id,name',
            ])
            ->when(
                trim($this->search) !== '',
                function ($query): void {
                    $search = '%'.trim($this->search).'%';

                    $query->where(function ($query) use ($search): void {
                        $query
                            ->where('number', 'like', $search)
                            ->orWhere('customer_name', 'like', $search)
                            ->orWhereHas(
                                'invoice',
                                fn ($invoice) => $invoice->where('number', 'like', $search)
                            );
                    });
                }
            )
            ->when(
                $this->statusFilter !== '',
                fn ($query) => $query->where('status', $this->statusFilter)
            )
            ->orderByDesc('credit_date')
            ->orderByDesc('id')
            ->paginate(12);

        return [
            'creditNotes' => $creditNotes,
        ];
    }
}; ?>

<section class="space-y-6">
    <div class="border-y border-gray-200 bg-white p-5">
        <div class="grid gap-4 md:grid-cols-[1fr_14rem]">
            <div>
                <x-input-label for="credit-search" :value="__('Recherche')" />
                <x-text-input
                    id="credit-search"
                    wire:model.live.debounce.300ms="search"
                    class="mt-1 block w-full"
                    placeholder="{{ __('Numéro, facture ou client') }}"
                />
            </div>

            <div>
                <x-input-label for="credit-status" :value="__('Statut')" />

                <select
                    id="credit-status"
                    wire:model.live="statusFilter"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option value="">{{ __('Tous') }}</option>
                    <option value="draft">{{ __('Brouillon') }}</option>
                    <option value="issued">{{ __('Émis') }}</option>
                    <option value="cancelled">{{ __('Annulé') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="overflow-x-auto border-y border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-gray-500">
                        {{ __('Avoir') }}
                    </th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-gray-500">
                        {{ __('Date') }}
                    </th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-gray-500">
                        {{ __('Client') }}
                    </th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-gray-500">
                        {{ __('Facture') }}
                    </th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-gray-500">
                        {{ __('Statut') }}
                    </th>
                    <th class="px-5 py-3 text-right text-xs font-semibold uppercase text-gray-500">
                        {{ __('TTC') }}
                    </th>
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">
                @forelse ($creditNotes as $creditNote)
                    <tr>
                        <td class="px-5 py-4">
                            <a
                                href="{{ route('sales.credit-notes.show', $creditNote) }}"
                                wire:navigate
                                class="font-semibold text-indigo-700 hover:text-indigo-900"
                            >
                                {{ $creditNote->number ?? __('Brouillon #:id', ['id' => $creditNote->id]) }}
                            </a>
                        </td>

                        <td class="px-5 py-4 text-sm text-gray-700">
                            {{ $creditNote->credit_date->format('d/m/Y') }}
                        </td>

                        <td class="px-5 py-4 text-sm text-gray-700">
                            {{ $creditNote->customer_name }}
                        </td>

                        <td class="px-5 py-4 text-sm">
                            <a
                                href="{{ route('sales.invoices.show', $creditNote->invoice) }}"
                                wire:navigate
                                class="text-indigo-700 hover:text-indigo-900"
                            >
                                {{ $creditNote->invoice->number }}
                            </a>
                        </td>

                        <td class="px-5 py-4 text-sm text-gray-700">
                            {{
                                match ($creditNote->status) {
                                    'draft' => __('Brouillon'),
                                    'issued' => __('Émis'),
                                    'cancelled' => __('Annulé'),
                                    default => $creditNote->status,
                                }
                            }}
                        </td>

                        <td class="px-5 py-4 text-right font-medium text-gray-900">
                            {{ number_format((float) $creditNote->total_ttc, 2, ',', ' ') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-500">
                            {{ __('Aucun avoir trouvé.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $creditNotes->links() }}
</section>