<?php

use App\Livewire\Concerns\FiltersReportPeriod;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Gate;

new class extends \Livewire\Volt\Component
{
    use FiltersReportPeriod { preset as private existingPreset; }

    public function preset(string $preset): void
    {
        if (! in_array($preset, ['week', 'quarter'], true)) {
            $this->existingPreset($preset);

            return;
        }
        Gate::authorize('reports.view');
        $this->from = ($preset === 'week' ? today()->startOfWeek() : today()->startOfQuarter())->toDateString();
        $this->to = ($preset === 'week' ? today()->endOfWeek() : today()->endOfQuarter())->toDateString();
        $this->applyPeriod();
    }

    public function with(): array
    {
        Gate::authorize('reports.view');

        return app(DashboardService::class)->read($this->appliedFrom, $this->appliedTo);
    }
}; ?>
@php
    $money = fn ($amount) => \App\Services\ReportService::money($amount, $currency);
    $cards = [
        ['Chiffre d’affaires HT', $flows['CA net HT'], 'CA net HT', 'blue', '↗'],
        ['Encaissements', $flows['Encaissements'], 'Paiements enregistrés sur la période', 'green', '↓'],
        ['Dépenses TTC', $flows['Dépenses TTC'], 'Dépenses enregistrées sur la période', 'red', '↑'],
        ['Solde caisse', $current['Solde caisse'], 'Solde actuel, toutes périodes', 'violet', '≋'],
    ];
    $pendingCards = [
        ['quotes', 'Devis en attente', 'Brouillons et envoyés', 'sales.quotes.index', 'sales.view'],
        ['salesOrders', 'Commandes clients', 'À livrer ou à confirmer', 'sales.orders.index', 'sales.view'],
        ['purchaseOrders', 'Commandes fournisseurs', 'Brouillons et confirmées', 'purchases.orders.index', 'purchases.view'],
        ['receipts', 'Réceptions brouillon', 'À valider', 'purchases.receipts.index', 'purchases.view'],
        ['unpaid', 'Factures impayées', 'Avec un solde restant', 'sales.invoices.index', 'invoices.view'],
        ['reminders', 'Relances à traiter', 'Factures échues avec solde restant', 'finance.receivables.index', 'payments.view'],
    ];
@endphp
<section class="dashboard-executive" aria-label="{{ __('Vue d’ensemble') }}" wire:loading.class="dashboard-updating">
    <header class="dashboard-header">
        <div><span class="dashboard-eyebrow">{{ __('Tableau de bord') }}</span><h1>{{ __('Vue d’ensemble') }}</h1><p>{{ __('Suivez en temps réel la performance de votre activité.') }}</p></div>
        <a class="dashboard-report-link" href="{{ route('reports.index') }}" wire:navigate>{{ __('Consulter les rapports') }} <span aria-hidden="true">↗</span></a>
    </header>
    <div class="dashboard-filter">
        <div class="dashboard-presets" role="group" aria-label="{{ __('Période') }}">
            @foreach (['today' => 'Aujourd’hui', 'week' => 'Cette semaine', 'month' => 'Ce mois', 'quarter' => 'Ce trimestre', 'year' => 'Cette année', 'previous' => 'Mois précédent'] as $preset => $label)
                @php
                    $bounds = match ($preset) {
                        'today' => [today(), today()],
                        'week' => [today()->startOfWeek(), today()->endOfWeek()],
                        'month' => [today()->startOfMonth(), today()->endOfMonth()],
                        'quarter' => [today()->startOfQuarter(), today()->endOfQuarter()],
                        'year' => [today()->startOfYear(), today()->endOfYear()],
                        default => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
                    };
                    $selected = $appliedFrom === $bounds[0]->toDateString() && $appliedTo === $bounds[1]->toDateString();
                @endphp
                <button type="button" wire:click="preset('{{ $preset }}')" wire:loading.attr="disabled" aria-pressed="{{ $selected ? 'true' : 'false' }}" class="{{ $selected ? 'is-selected' : '' }}">{{ __($label) }}</button>
            @endforeach
        </div>
        <form wire:submit="applyPeriod" class="dashboard-date-form">
            <label><span>{{ __('Du') }}</span><input type="date" value="{{ $from }}" wire:model="from" aria-invalid="{{ $errors->has('from') ? 'true' : 'false' }}" /></label>
            <label><span>{{ __('Au') }}</span><input type="date" value="{{ $to }}" wire:model="to" aria-invalid="{{ $errors->has('to') ? 'true' : 'false' }}" /></label>
            <button type="submit" wire:loading.attr="disabled">{{ __('Appliquer') }}</button>
        </form>
        @error('from')<p class="dashboard-form-error" role="alert">{{ $message }}</p>@enderror
        @error('to')<p class="dashboard-form-error" role="alert">{{ $message }}</p>@enderror
    </div>
    <div class="dashboard-kpis">
        @foreach ($cards as [$label, $amount, $hint, $color, $icon])
            <article class="dashboard-kpi dashboard-tone-{{ $color }}">
                <div class="dashboard-kpi-heading"><h2>{{ __($label) }}</h2><span class="dashboard-icon" aria-hidden="true">{{ $icon }}</span></div>
                <strong><bdi>{{ $money($amount) }}</bdi></strong><p>{{ __($hint) }}</p>
            </article>
        @endforeach
    </div>
    <div class="dashboard-main-grid">
        <x-dashboard-chart :chart="$chart" :currency="$currency" />
        <section class="dashboard-panel dashboard-situation">
            <div class="dashboard-panel-heading"><div><h2>{{ __('Situation actuelle') }}</h2><p>{{ __('Indépendante de la période sélectionnée') }}</p></div><span class="dashboard-live-dot" aria-hidden="true"></span></div>
            @foreach ([
                ['Créances clients', $money($current['Créances clients']), 'blue'],
                ['Fournisseurs à payer', $money($current['Fournisseurs à payer']), 'violet'],
                ['Valeur du stock', $money($stockValue), 'green'],
                ['Factures en retard', $overdueCount, 'orange'],
                ['Alertes stock', $current['Alertes stock'], 'red'],
            ] as [$label, $value, $color])
                <div class="dashboard-situation-row"><span><i class="dashboard-marker dashboard-tone-{{ $color }}"></i>{{ __($label) }}</span><strong><bdi>{{ $value }}</bdi></strong></div>
            @endforeach
            <p class="dashboard-note">{{ __('Stock estimé au prix d’achat actuel, par produit et dépôt.') }}</p>
            <p class="dashboard-note">{{ __('La caisse représente les mouvements réellement enregistrés.') }}</p>
        </section>
    </div>
    <section class="dashboard-panel dashboard-pending">
        <div class="dashboard-panel-heading"><div><h2>{{ __('Opérations en attente') }}</h2><p>{{ __('À suivre aujourd’hui, toutes périodes confondues') }}</p></div></div>
        <div class="dashboard-pending-grid">
            @foreach ($pendingCards as [$key, $label, $hint, $route, $permission])
                <div class="dashboard-pending-item">
                    <strong><bdi>{{ $pending[$key] }}</bdi></strong>
                    @if ($permissions[$permission])<a href="{{ route($route) }}" wire:navigate>{{ __($label) }}</a>@else<span>{{ __($label) }}</span>@endif
                    <small>{{ __($hint) }}</small>
                </div>
            @endforeach
        </div>
    </section>
    <div class="dashboard-bottom-grid">
        <section class="dashboard-panel dashboard-activity">
            <div class="dashboard-panel-heading"><div><h2>{{ __('Activité récente') }}</h2><p>{{ __('Dernières opérations et contacts enregistrés') }}</p></div><span class="dashboard-unit">{{ $feed->count() }}</span></div>
            <ul class="dashboard-feed">
                @forelse ($feed as $item)
                    <li wire:key="activity-{{ $item['key'] }}">
                        <span class="dashboard-feed-icon dashboard-tone-{{ $item['color'] }}" aria-hidden="true">•</span>
                        <div class="dashboard-feed-body"><span class="dashboard-feed-label">{{ __($item['label']) }}</span>
                            @if ($item['url'])<a href="{{ $item['url'] }}" wire:navigate><bdi>{{ $item['reference'] ?? __('Sans référence') }}</bdi></a>@else<strong><bdi>{{ $item['reference'] ?? __('Sans référence') }}</bdi></strong>@endif
                            <p>{{ $item['party'] }} <span aria-hidden="true">·</span> <time datetime="{{ $item['date']?->toDateString() }}"><bdi>{{ $item['date']?->format('d/m/Y') }}</bdi></time></p>
                        </div>
                        @if ($item['amount'] !== null)<bdi class="dashboard-feed-amount">{{ $money($item['amount']) }}</bdi>@endif
                    </li>
                @empty<li class="dashboard-empty">{{ __('Aucune opération.') }}</li>@endforelse
            </ul>
        </section>
        <section class="dashboard-panel dashboard-alerts">
            <div class="dashboard-panel-heading"><div><h2>{{ __('Alertes à traiter') }}</h2><p>{{ __('Priorités et documents à valider') }}</p></div><span class="dashboard-alert-symbol" aria-hidden="true">!</span></div>
            <div class="dashboard-alert-list">
                @foreach ([
                    ['overdue', 'Factures en retard', 'orange'],
                    ['stock', 'Alertes stock', 'red'],
                    ['supplierInvoices', 'Factures fournisseurs brouillon', 'violet'],
                    ['receipts', 'Réceptions brouillon', 'blue'],
                ] as [$key, $label, $color])
                    @if ($alerts[$key]->isNotEmpty())
                        <div class="dashboard-alert-group dashboard-tone-{{ $color }}"><h3>{{ __($label) }}</h3>
                            @foreach ($alerts[$key] as $row)
                                <p><strong><bdi>{{ $key === 'stock' ? $row->name : ($row->number ?? $row->supplier_invoice_number ?? $row->reference ?? __('Sans référence')) }}</bdi></strong>
                                <span>@if ($key === 'stock'){{ $row->warehouse_name }} · <bdi>{{ $row->report_quantity }}</bdi>@elseif ($key === 'overdue'){{ $row->customer_name }} · <bdi>{{ $money($row->report_remaining) }}</bdi>@else<bdi>{{ ($row->invoice_date ?? $row->receipt_date)?->format('d/m/Y') }}</bdi>@endif</span></p>
                            @endforeach
                        </div>
                    @endif
                @endforeach
                @if (collect($alerts)->every(fn ($rows) => $rows->isEmpty()))<p class="dashboard-empty">{{ __('Aucune alerte à traiter.') }}</p>@endif
                @if ($activity['Dernières relances']->isNotEmpty())<p class="dashboard-note">{{ __('Dernières relances') }} : {{ $activity['Dernières relances']->count() }} · {{ __('Consultez les contacts dans l’activité récente.') }}</p>@endif
            </div>
        </section>
    </div>
    <div class="dashboard-details-grid">
        <details class="dashboard-panel dashboard-details"><summary>{{ __('Journal de caisse') }}</summary>
            <p class="dashboard-note">{{ __('Entrées sur la période : :in · Sorties : :out', ['in' => $money($cashFlows['Entrées caisse']), 'out' => $money($cashFlows['Sorties caisse'])]) }}</p>
            <ul>@forelse ($cashDetails as $register)<li><span>{{ $register->name }}{{ $register->is_active ? '' : ' (' . __('inactive') . ')' }}</span><bdi>{{ $money($register->report_balance) }}</bdi></li>@empty<li>{{ __('Aucune caisse.') }}</li>@endforelse</ul>
            @if ($permissions['payments.view'])<a class="dashboard-report-link" href="{{ route('finance.cash.index') }}" wire:navigate>{{ __('Journal de caisse') }} <span aria-hidden="true">↗</span></a>@endif
        </details>
        <details class="dashboard-panel dashboard-details"><summary>{{ __('Commandes et réceptions sur la période') }}</summary>
            <p class="dashboard-note">{{ __('Achats facturés TTC') }} : <bdi>{{ $money($flows['Achats facturés TTC']) }}</bdi></p>
            @foreach (['Commandes clients', 'Commandes fournisseurs'] as $label)
                <h3>{{ __($label) }}</h3>
                @forelse ($operations[$label] as $status => $count)<p>{{ __(['draft' => 'Brouillon', 'confirmed' => 'Confirmée', 'cancelled' => 'Annulée', 'partially_delivered' => 'Partiellement livrée', 'delivered' => 'Livrée'][$status] ?? $status) }} : {{ $count }}</p>@empty<p>{{ __('Aucune commande.') }}</p>@endforelse
            @endforeach
            <p>{{ __('Réceptions validées : :count', ['count' => $operations['Réceptions validées']]) }}</p>
        </details>
    </div>
    <div wire:loading class="dashboard-loading" role="status">{{ __('Actualisation en cours…') }}</div>
</section>
