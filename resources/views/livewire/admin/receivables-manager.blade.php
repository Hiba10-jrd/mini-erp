<?php

use App\Models\Customer;
use App\Models\CustomerReminder;
use App\Models\Invoice;
use App\Services\CustomerReminderManagementService;
use App\Services\PaymentManagementService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    #[\Livewire\Attributes\Url]
    public string $search = '';

    public string $customerFilter = 'all';

    public string $paymentFilter = 'all';

    public string $dueFilter = 'all';

    public string $dateFrom = '';

    public string $dateTo = '';

    #[Locked]
    public ?int $selectedInvoiceId = null;

    #[Locked]
    public string $selectedInvoiceNumber = '';

    public string $reminderDate = '';

    public string $channel = CustomerReminder::CHANNEL_EMAIL;

    public string $note = '';

    public function mount(): void
    {
        Gate::authorize('payments.view');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'customerFilter', 'paymentFilter', 'dueFilter', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        Gate::authorize('payments.view');
        $this->reset(['search', 'customerFilter', 'paymentFilter', 'dueFilter', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function openReminder(int $invoiceId): void
    {
        Gate::authorize('payments.view');
        Gate::authorize('payments.create');
        $invoice = Invoice::query()->where('status', Invoice::STATUS_ISSUED)->findOrFail($invoiceId);
        $this->resetValidation();
        $this->selectedInvoiceId = $invoice->id;
        $this->selectedInvoiceNumber = $invoice->number;
        $this->reminderDate = today()->toDateString();
        $this->channel = CustomerReminder::CHANNEL_EMAIL;
        $this->note = '';
    }

    public function closeReminder(): void
    {
        $this->reset(['selectedInvoiceId', 'selectedInvoiceNumber', 'reminderDate', 'channel', 'note']);
        $this->resetValidation();
    }

    public function saveReminder(CustomerReminderManagementService $service): void
    {
        Gate::authorize('payments.view');
        Gate::authorize('payments.create');
        if ($this->selectedInvoiceId === null) {
            $this->addError('invoice_id', __('Sélectionnez une facture.'));

            return;
        }

        $service->create(Invoice::query()->findOrFail($this->selectedInvoiceId), [
            'reminder_date' => $this->reminderDate,
            'channel' => $this->channel,
            'note' => $this->note,
        ]);
        $this->closeReminder();
        session()->flash('status', __('La relance a été enregistrée.'));
    }

    public function with(): array
    {
        Gate::authorize('payments.view');
        $payments = app(PaymentManagementService::class);
        $reminders = app(CustomerReminderManagementService::class);
        $search = trim($this->search);
        $query = Invoice::query()->where('status', Invoice::STATUS_ISSUED)
            ->with(['customer:id,name,trade_name,code', 'latestReminder'])
            ->when($this->customerFilter !== 'all', fn ($query) => $query->where('customer_id', (int) $this->customerFilter))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_trade_name', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%")
                        ->orWhere('trade_name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            }))
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderByDesc('id');

        // Financial methods query allocations/credits themselves; eager loading them would not help.
        $rows = $query->get()->map(function (Invoice $invoice) use ($payments, $reminders): array {
            $paid = $payments->paidAmount($invoice);
            $remaining = $payments->remainingAmount($invoice);
            $state = $payments->paymentState($invoice);
            // Preserve the service's overdue precedence while showing payment and maturity separately.
            $payment = $state === 'overdue' ? (bccomp($paid, '0.00', 2) === 1 ? 'partially_paid' : 'unpaid') : $state;
            $due = $state === 'paid' ? 'settled' : ($invoice->due_date === null ? 'no_due_date' : ($state === 'overdue' ? 'overdue' : 'upcoming'));

            return ['invoice' => $invoice, 'paid' => $paid, 'remaining' => $remaining,
                'payment' => $payment, 'due' => $due, 'days' => $reminders->overdueDays($invoice)];
        })->filter(function (array $row): bool {
            $invoice = $row['invoice'];
            if ($this->paymentFilter === 'paid' ? $row['payment'] !== 'paid' : $row['payment'] === 'paid') {
                return false;
            }
            if (in_array($this->paymentFilter, ['unpaid', 'partially_paid'], true) && $row['payment'] !== $this->paymentFilter) {
                return false;
            }
            if (in_array($this->dueFilter, ['overdue', 'upcoming', 'no_due_date'], true) && $row['due'] !== $this->dueFilter) {
                return false;
            }

            return ($this->dateFrom === '' || ($invoice->due_date && $invoice->due_date->toDateString() >= $this->dateFrom))
                && ($this->dateTo === '' || ($invoice->due_date && $invoice->due_date->toDateString() <= $this->dateTo));
        })->values();

        $summary = ['remaining' => '0.00', 'overdue' => '0.00', 'overdueCount' => 0, 'openCount' => 0];
        foreach ($rows as $row) {
            $summary['remaining'] = bcadd($summary['remaining'], $row['remaining'], 2);
            if (bccomp($row['remaining'], '0.00', 2) === 1) {
                $summary['openCount']++;
            }
            if ($row['due'] === 'overdue') {
                $summary['overdue'] = bcadd($summary['overdue'], $row['remaining'], 2);
                $summary['overdueCount']++;
            }
        }

        return [
            'receivables' => new LengthAwarePaginator($rows->forPage($this->getPage(), 12)->values(), $rows->count(), 12, $this->getPage(), ['path' => request()->url()]),
            'summary' => $summary,
            'customers' => Customer::query()->whereIn('id', Invoice::query()->where('status', Invoice::STATUS_ISSUED)->select('customer_id'))->orderBy('name')->get(['id', 'name']),
            'channels' => CustomerReminder::channels(),
        ];
    }
}; ?>

<section class="space-y-5">
    <div>
        <h3 class="text-lg font-semibold text-gray-900">{{ __('Suivi des créances clients') }}</h3>
        <p class="mt-1 text-sm text-gray-500">{{ __('Les créances ouvertes sont affichées par défaut. Les indicateurs suivent les filtres sélectionnés.') }}</p>
    </div>

    @if (session('status'))
        <div role="status" class="border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach (['remaining' => __('Total à recevoir'), 'overdue' => __('Montant en retard'), 'overdueCount' => __('Factures en retard'), 'openCount' => __('Factures ouvertes')] as $key => $label)
            <div class="border border-gray-200 bg-white p-5">
                <p class="text-sm text-gray-500">{{ $label }}</p>
                <p class="mt-2 text-xl font-semibold text-gray-900">{{ in_array($key, ['remaining', 'overdue']) ? str_replace('.', ',', $summary[$key]).' DH' : $summary[$key] }}</p>
            </div>
        @endforeach
    </div>

    <div class="border-y border-gray-200 bg-white p-4 sm:p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div><x-input-label for="receivable-search" :value="__('Facture ou client')" /><x-text-input id="receivable-search" wire:model.live.debounce.300ms="search" class="mt-1 w-full" /></div>
            <div><x-input-label for="receivable-customer" :value="__('Client')" /><select id="receivable-customer" wire:model.live="customerFilter" class="mt-1 w-full border-gray-300 shadow-sm"><option value="all">{{ __('Tous les clients') }}</option>@foreach ($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}</option>@endforeach</select></div>
            <div><x-input-label for="receivable-payment" :value="__('État paiement')" /><select id="receivable-payment" wire:model.live="paymentFilter" class="mt-1 w-full border-gray-300 shadow-sm"><option value="all">{{ __('Toutes les créances ouvertes') }}</option><option value="unpaid">{{ __('Impayée') }}</option><option value="partially_paid">{{ __('Partiellement payée') }}</option><option value="paid">{{ __('Soldée') }}</option></select></div>
            <div><x-input-label for="receivable-due" :value="__('État échéance')" /><select id="receivable-due" wire:model.live="dueFilter" class="mt-1 w-full border-gray-300 shadow-sm"><option value="all">{{ __('Toutes les échéances') }}</option><option value="overdue">{{ __('En retard') }}</option><option value="upcoming">{{ __('À échoir') }}</option><option value="no_due_date">{{ __('Sans échéance') }}</option></select></div>
            <div><x-input-label for="receivable-from" :value="__('Échéance du')" /><x-text-input id="receivable-from" type="date" wire:model.live="dateFrom" class="mt-1 w-full" /></div>
            <div><x-input-label for="receivable-to" :value="__('Échéance au')" /><x-text-input id="receivable-to" type="date" wire:model.live="dateTo" class="mt-1 w-full" /></div>
        </div>
        <x-secondary-button type="button" wire:click="resetFilters" class="mt-4">{{ __('Réinitialiser les filtres') }}</x-secondary-button>
    </div>

    @if ($selectedInvoiceId !== null)
        <form wire:submit="saveReminder" class="space-y-4 border border-gray-200 bg-white p-5">
            <h3 class="font-semibold text-gray-900">{{ __('Relancer la facture :number', ['number' => $selectedInvoiceNumber]) }}</h3>
            <x-input-error :messages="$errors->get('invoice_id')" />
            <div class="grid gap-4 sm:grid-cols-2">
                <div><x-input-label for="reminder-date" :value="__('Date de relance')" /><x-text-input id="reminder-date" type="date" wire:model="reminderDate" class="mt-1 w-full" /><x-input-error :messages="$errors->get('reminder_date')" class="mt-2" /></div>
                <div><x-input-label for="reminder-channel" :value="__('Canal')" /><select id="reminder-channel" wire:model="channel" class="mt-1 w-full border-gray-300 shadow-sm">@foreach ($channels as $value)<option value="{{ $value }}">{{ ['email' => __('Email'), 'phone' => __('Téléphone'), 'whatsapp' => 'WhatsApp', 'manual' => __('Manuel')][$value] }}</option>@endforeach</select><x-input-error :messages="$errors->get('channel')" class="mt-2" /></div>
            </div>
            <div><x-input-label for="reminder-note" :value="__('Note')" /><textarea id="reminder-note" wire:model="note" rows="3" class="mt-1 w-full border-gray-300 shadow-sm"></textarea><x-input-error :messages="$errors->get('note')" class="mt-2" /></div>
            <p class="text-sm text-gray-500">{{ __('Enregistre une action de relance déjà effectuée. Aucun message n’est envoyé.') }}</p>
            <div class="flex gap-3"><x-primary-button wire:loading.attr="disabled" wire:target="saveReminder">{{ __('Enregistrer la relance') }}</x-primary-button><x-secondary-button type="button" wire:click="closeReminder">{{ __('Annuler') }}</x-secondary-button></div>
        </form>
    @endif

    <div class="overflow-x-auto border-y border-gray-200 bg-white">
        <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr>@foreach (['Facture', 'Client', 'Date facture', 'Échéance', 'Retard', 'Total TTC', 'Payé', 'Restant', 'État paiement', 'État échéance', 'Dernière relance', 'Action'] as $heading)<th class="whitespace-nowrap px-4 py-3">{{ __($heading) }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($receivables as $row)
                    @php($invoice = $row['invoice'])
                    <tr wire:key="receivable-{{ $invoice->id }}">
                        <td class="whitespace-nowrap px-4 py-4 font-medium">@can('invoices.view')<a href="{{ route('sales.invoices.show', $invoice) }}" wire:navigate class="text-indigo-700 hover:text-indigo-900">{{ $invoice->number }}</a>@else{{ $invoice->number }}@endcan</td>
                        <td class="px-4 py-4">{{ $invoice->customer_name }}</td>
                        <td class="whitespace-nowrap px-4 py-4">{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                        <td class="whitespace-nowrap px-4 py-4">{{ $invoice->due_date?->format('d/m/Y') ?? __('Sans échéance') }}</td>
                        <td class="whitespace-nowrap px-4 py-4 {{ $row['days'] > 0 ? 'text-rose-700' : 'text-gray-500' }}">{{ $row['days'] > 0 ? __(':days jours', ['days' => $row['days']]) : '—' }}</td>
                        @foreach ([$invoice->total_ttc, $row['paid'], $row['remaining']] as $amount)<td class="whitespace-nowrap px-4 py-4 text-right">{{ str_replace('.', ',', $amount) }} DH</td>@endforeach
                        <td class="whitespace-nowrap px-4 py-4"><span @class(['px-2 py-1 text-xs font-medium', 'bg-emerald-100 text-emerald-800' => $row['payment'] === 'paid', 'bg-amber-100 text-amber-800' => $row['payment'] === 'partially_paid', 'bg-gray-100 text-gray-700' => $row['payment'] === 'unpaid'])>{{ ['paid' => __('Soldée'), 'partially_paid' => __('Partiellement payée'), 'unpaid' => __('Impayée')][$row['payment']] }}</span></td>
                        <td class="whitespace-nowrap px-4 py-4"><span @class(['px-2 py-1 text-xs font-medium', 'bg-rose-100 text-rose-800' => $row['due'] === 'overdue', 'bg-gray-100 text-gray-700' => $row['due'] !== 'overdue'])>{{ ['settled' => __('Soldée'), 'overdue' => __('En retard'), 'upcoming' => __('À échoir'), 'no_due_date' => __('Sans échéance')][$row['due']] }}</span></td>
                        <td class="whitespace-nowrap px-4 py-4">@if ($invoice->latestReminder){{ $invoice->latestReminder->reminder_date->format('d/m/Y') }}<span class="block text-xs text-gray-500">{{ $invoice->latestReminder->channel }}</span>@else{{ __('Aucune') }}@endif</td>
                        <td class="px-4 py-4">@can('payments.create')@if ($row['payment'] !== 'paid')<x-secondary-button type="button" wire:click="openReminder({{ $invoice->id }})">{{ __('Relancer') }}</x-secondary-button>@endif@endcan</td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="px-6 py-12 text-center text-gray-500">{{ __('Aucune facture trouvée.') }}</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </div>
    @if ($receivables->hasPages())<div>{{ $receivables->links() }}</div>@endif
</section>
