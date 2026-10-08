<?php

use App\Livewire\Concerns\FiltersReportPeriod;
use App\Services\ReportService;
use Illuminate\Support\Facades\Gate;

new class extends \Livewire\Volt\Component
{
    use FiltersReportPeriod;

    public function with(): array
    {
        Gate::authorize('reports.view');
        $reports = app(ReportService::class);
        $sales = $reports->salesSummary($this->appliedFrom, $this->appliedTo);
        $purchases = $reports->purchaseSummary($this->appliedFrom, $this->appliedTo);
        $expenses = $reports->expenseSummary($this->appliedFrom, $this->appliedTo);
        $receivables = $reports->receivablesSummary();
        $cash = $reports->cashSummary($this->appliedFrom, $this->appliedTo);
        $stock = $reports->stockSummary();

        $cashDetails = $reports->cashRegisters();
        $operations = $reports->operationsSummary($this->appliedFrom, $this->appliedTo);

        return ['cashDetails' => $cashDetails, 'operations' => $operations, 'cashFlows' => $cash, 'currency' => $reports->currency(), 'flows' => ['CA net HT' => $sales['CA net HT'], 'Encaissements' => $sales['Encaissements'], 'Achats facturés TTC' => $purchases['Achats facturés TTC'], 'Dépenses TTC' => $expenses['Dépenses TTC']], 'current' => ['Créances clients' => $receivables['Créances clients'], 'Fournisseurs à payer' => $purchases['Fournisseurs à payer actuels'], 'Solde caisse' => $cash['Solde caisse actuel'], 'Alertes stock' => $stock['Alertes stock']], 'overdueCount' => $receivables['Factures en retard'], 'activity' => $reports->recentActivity()];
    }
}; ?>
<section class="space-y-6">
    <x-report-period :applied-from="$appliedFrom" :applied-to="$appliedTo" />
    <div><h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Sur la période') }}</h3><div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">@foreach ($flows as $title => $amount)<x-kpi-card :title="$title" :value="\App\Services\ReportService::money($amount, $currency)" />@endforeach</div></div>
    <div><h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Situation actuelle') }}</h3><p class="mb-3 text-sm text-gray-500">{{ __('Indépendante de la période — :count factures en retard. La caisse représente les mouvements réellement enregistrés.', ['count' => $overdueCount]) }}</p><div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">@foreach ($current as $title => $amount)<x-kpi-card :title="$title" :value="$title === 'Alertes stock' ? $amount : \App\Services\ReportService::money($amount, $currency)" :subtitle="$title === 'Alertes stock' ? __('Par couple produit/dépôt') : null" />@endforeach</div></div>
    <div class="grid gap-4 lg:grid-cols-2">@foreach ($activity as $title => $rows)<div class="erp-card"><h3 class="mb-3 font-semibold">{{ __($title) }}</h3><ul class="divide-y divide-gray-100">@forelse ($rows as $row)<li class="flex justify-between gap-3 py-3 text-sm"><span>{{ $row->number ?? $row->reference ?? $row->invoice?->number ?? __('Sans référence') }}<span class="block text-gray-500">{{ $row->customer_name ?? $row->customer?->name ?? $row->category?->name ?? $row->channel }} · {{ ($row->invoice_date ?? $row->payment_date ?? $row->expense_date ?? $row->reminder_date)?->format('d/m/Y') }}</span></span>@if ($row->total_ttc !== null || $row->amount !== null)<span>{{ \App\Services\ReportService::money($row->total_ttc ?? $row->amount, $currency) }}</span>@endif</li>@empty<li class="py-3 text-sm text-gray-500">{{ __('Aucune opération.') }}</li>@endforelse</ul></div>@endforeach</div>
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="erp-card"><h3 class="font-semibold">{{ __('Journal de caisse') }}</h3><p class="mt-2 text-sm">{{ __('Entrées sur la période : :in · Sorties : :out', ['in' => \App\Services\ReportService::money($cashFlows['Entrées caisse'], $currency), 'out' => \App\Services\ReportService::money($cashFlows['Sorties caisse'], $currency)]) }}</p><ul class="mt-3 divide-y">@forelse ($cashDetails as $register)<li class="flex justify-between py-2 text-sm"><span>{{ $register->name }}{{ $register->is_active ? '' : ' (' . __('inactive') . ')' }}</span><span>{{ \App\Services\ReportService::money($register->report_balance, $currency) }}</span></li>@empty<li class="py-2 text-sm text-gray-500">{{ __('Aucune caisse.') }}</li>@endforelse</ul></div>
        <div class="erp-card"><h3 class="font-semibold">{{ __('Commandes et réceptions sur la période') }}</h3>@foreach (['Commandes clients', 'Commandes fournisseurs'] as $label)<p class="mt-3 text-sm font-medium">{{ __($label) }}</p>@forelse ($operations[$label] as $status => $count)<p class="text-sm text-gray-500">{{ __(['draft' => 'Brouillon', 'confirmed' => 'Confirmée', 'cancelled' => 'Annulée', 'partially_delivered' => 'Partiellement livrée', 'delivered' => 'Livrée'][$status] ?? $status) }} : {{ $count }}</p>@empty<p class="text-sm text-gray-500">{{ __('Aucune commande.') }}</p>@endforelse @endforeach<p class="mt-3 text-sm">{{ __('Réceptions validées : :count', ['count' => $operations['Réceptions validées']]) }}</p></div>
        <div class="erp-card"><h3 class="font-semibold">{{ __('Devis récents sur la période') }}</h3><ul class="mt-3 divide-y">@forelse ($operations['Devis récents'] as $quote)<li class="flex justify-between py-2 text-sm"><span>{{ $quote->number }} · {{ $quote->quote_date->format('d/m/Y') }}</span><span>{{ \App\Services\ReportService::money($quote->total_ttc, $currency) }}</span></li>@empty<li class="py-2 text-sm text-gray-500">{{ __('Aucun devis.') }}</li>@endforelse</ul></div>
    </div>
    <a href="{{ route('reports.index') }}" wire:navigate class="text-indigo-700 underline">{{ __('Consulter les rapports') }}</a>
</section>
