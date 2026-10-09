<?php

use App\Models\Customer;
use App\Models\Quote;
use App\Services\QuoteManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $customerFilter = 'all';

    public bool $includeArchived = false;

    public function mount(): void
    {
        Gate::authorize('sales.view');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'customerFilter', 'includeArchived'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        Gate::authorize('sales.view');
        $statuses = [Quote::STATUS_DRAFT, Quote::STATUS_SENT, Quote::STATUS_ACCEPTED, Quote::STATUS_REFUSED, Quote::STATUS_EXPIRED];
        $status = in_array($this->statusFilter, $statuses, true) ? $this->statusFilter : 'all';
        $customerId = $this->customerFilter === 'all' ? null : filter_var($this->customerFilter, FILTER_VALIDATE_INT);
        $search = trim($this->search);

        return [
            'quotes' => Quote::query()
                ->with(['customer:id,name,code', 'creator:id,name'])
                ->when(! $this->includeArchived, fn ($query) => $query->whereNull('archived_at'))
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->when($customerId !== false && $customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', "%{$search}%"));
                }))
                ->latest('quote_date')->latest('id')->paginate(10),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'code', 'status']),
        ];
    }

    public function archive(int $quoteId, QuoteManagementService $service): void
    {
        Gate::authorize('sales.delete');
        $service->archiveDraft(Quote::query()->findOrFail($quoteId));
        $this->dispatch('quote-archived');
    }
}; ?>

<section class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h3 class="text-lg font-semibold text-gray-900">{{ __('Tous les devis') }}</h3>
        @can('sales.create')
            <a href="{{ route('sales.quotes.create') }}" wire:navigate class="inline-flex min-h-10 items-center justify-center bg-gray-900 px-4 text-sm font-medium text-white hover:bg-gray-700">{{ __('Créer un devis') }}</a>
        @endcan
    </div>

    <div class="border-y border-gray-200 bg-white p-4 sm:p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div><x-input-label for="quote-search" :value="__('Numéro ou client')" /><x-text-input id="quote-search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full" placeholder="DEV-2026-00001" /></div>
            <div>
                <x-input-label for="quote-status" :value="__('Statut')" />
                <select id="quote-status" wire:model.live="statusFilter" class="mt-1 block w-full border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="all">{{ __('Tous les statuts') }}</option><option value="draft">{{ __('Brouillon') }}</option><option value="sent">{{ __('Envoyé') }}</option><option value="accepted">{{ __('Accepté') }}</option><option value="refused">{{ __('Refusé') }}</option><option value="expired">{{ __('Expiré') }}</option>
                </select>
            </div>
            <div>
                <x-input-label for="quote-customer" :value="__('Client')" />
                <select id="quote-customer" wire:model.live="customerFilter" class="mt-1 block w-full border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="all">{{ __('Tous les clients') }}</option>
                    @foreach ($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->status === 'active' ? '' : ' · '.__('archivé') }}</option>@endforeach
                </select>
            </div>
            <label class="flex items-end gap-2 pb-2 text-sm text-gray-700"><input type="checkbox" wire:model.live="includeArchived" class="border-gray-300 text-indigo-600 focus:ring-indigo-500" />{{ __('Inclure les brouillons archivés') }}</label>
        </div>
    </div>

    @php
        $statusLabels = ['draft' => __('Brouillon'), 'sent' => __('Envoyé'), 'accepted' => __('Accepté'), 'refused' => __('Refusé'), 'expired' => __('Expiré')];
        $statusClasses = ['draft' => 'bg-gray-100 text-gray-700', 'sent' => 'bg-sky-100 text-sky-800', 'accepted' => 'bg-emerald-100 text-emerald-800', 'refused' => 'bg-rose-100 text-rose-800', 'expired' => 'bg-amber-100 text-amber-800'];
    @endphp
    <div class="overflow-hidden border-y border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-start text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">{{ __('Numéro') }}</th><th class="px-4 py-3">{{ __('Date') }}</th><th class="px-4 py-3">{{ __('Client') }}</th><th class="px-4 py-3">{{ __('Validité') }}</th><th class="px-4 py-3 text-end">{{ __('TTC') }}</th><th class="px-4 py-3">{{ __('Statut') }}</th><th class="px-4 py-3">{{ __('Créé par') }}</th><th class="px-4 py-3 text-end">{{ __('Actions') }}</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($quotes as $quote)
                        <tr wire:key="quote-{{ $quote->id }}" class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-4 py-4 font-semibold text-gray-900">{{ $quote->number }} @if ($quote->archived_at)<span class="ms-1 text-xs font-normal text-gray-500">{{ __('archivé') }}</span>@endif</td>
                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $quote->quote_date->format('d/m/Y') }}</td>
                            <td class="min-w-48 px-4 py-4"><span class="font-medium text-gray-900">{{ $quote->customer_name }}</span><span class="block text-xs text-gray-500">{{ $quote->customer->code }}</span></td>
                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $quote->valid_until?->format('d/m/Y') ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-end font-medium text-gray-900">{{ str_replace('.', ',', $quote->total_ttc) }}</td>
                            <td class="whitespace-nowrap px-4 py-4"><span class="inline-block px-2 py-1 text-xs font-medium {{ $statusClasses[$quote->status] }}">{{ $statusLabels[$quote->status] }}</span></td>
                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">{{ $quote->creator?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-end">
                                <a href="{{ route('sales.quotes.show', $quote) }}" wire:navigate class="font-medium text-indigo-700 hover:text-indigo-900">{{ __('Consulter') }}</a>
                                @if ($quote->isEditable())
                                    @can('sales.update')<a href="{{ route('sales.quotes.edit', $quote) }}" wire:navigate class="ms-3 text-gray-700 hover:text-gray-950">{{ __('Modifier') }}</a>@endcan
                                    @can('sales.delete')<button type="button" wire:click="archive({{ $quote->id }})" wire:confirm="{{ __('Archiver ce brouillon ?') }}" class="ms-3 text-rose-700 hover:text-rose-900">{{ __('Archiver') }}</button>@endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-10 text-center text-gray-500">{{ __('Aucun devis ne correspond aux filtres.') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>
        @if ($quotes->hasPages())<div class="border-t border-gray-200 px-4 py-4">{{ $quotes->links() }}</div>@endif
    </div>
</section>