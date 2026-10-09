<?php

use App\Livewire\Concerns\FiltersReportPeriod;
use App\Services\ReportService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use FiltersReportPeriod, WithPagination;

    #[Locked]
    public string $section = 'sales';

    public function selectSection(string $section): void
    {
        Gate::authorize('reports.view');
        abort_unless(in_array($section, ['sales', 'purchases', 'payments', 'expenses', 'receivables', 'stock'], true), 422);
        $this->section = $section;
        foreach (['page', 'creditsPage', 'supplierPage', 'movementsPage'] as $page) {
            $this->resetPage($page);
        }
    }

    private function displayTable($paginator, string $kind, string $currency): array
    {
        $money = fn ($value) => ReportService::money($value, $currency);
        $headers = match ($kind) {
            'sales' => ['Facture', 'Client', 'Date', 'HT net', 'TVA', 'TTC'],
            'credits' => ['Avoir', 'Client', 'Date', 'HT net', 'TVA', 'TTC'],
            'purchases' => ['Facture', 'Fournisseur', 'Date', 'HT net', 'TVA', 'TTC', 'Payé', 'Restant actuel'],
            'payments', 'supplierPayments' => ['Date', 'Référence', 'Tiers', 'Mode', 'Montant'],
            'expenses' => ['Date', 'Référence', 'Catégorie', 'Mode', 'TTC', 'TVA incluse'],
            'receivables' => ['Facture', 'Client', 'Échéance', 'Retard', 'Total TTC', 'Payé', 'Restant', 'Dernière relance'],
            'stock' => ['Produit', 'Dépôt', 'Quantité actuelle', 'Unité', 'Seuil minimum', 'État'],
            'movements' => ['Date', 'Produit', 'Dépôt', 'Type', 'Quantité', 'Référence'],
        };
        $rows = [];
        foreach ($paginator as $row) {
            $rows[] = match ($kind) {
                'sales', 'credits' => [$row->number, $row->customer_name, ($row->invoice_date ?? $row->credit_date)->format('d/m/Y'), $money(bcsub($row->subtotal_ht, $row->discount_total, 2)), $money($row->tax_total), $money($row->total_ttc)],
                'purchases' => [$row->number, $row->supplier_name, $row->invoice_date->format('d/m/Y'), $money(bcsub($row->subtotal_ht, $row->discount_total, 2)), $money($row->tax_total), $money($row->total_ttc), $money($row->report_paid), $money($row->report_remaining)],
                'payments', 'supplierPayments' => [$row->payment_date->format('d/m/Y'), $row->reference ?? '—', ($kind === 'payments' ? $row->customer : $row->supplier)?->name ?? '—', $row->paymentMethod?->name ?? '—', $money($row->amount)],
                'expenses' => [$row->expense_date->format('d/m/Y'), $row->reference ?? '—', $row->category?->name ?? '—', $row->paymentMethod?->name ?? '—', $money($row->amount), $money($row->tax_amount)],
                'receivables' => [$row->number, $row->customer_name, $row->due_date?->format('d/m/Y') ?? __('Sans échéance'), $row->due_date?->lt(today()) ? trans_choice(':count jour|:count jours', (int) $row->due_date->diffInDays(today()), ['count' => (int) $row->due_date->diffInDays(today())]) : '—', $money($row->total_ttc), $money($row->report_paid), $money($row->report_remaining), $row->latestReminder?->reminder_date->format('d/m/Y') ?? __('Aucune')],
                'stock' => [$row->name.($row->is_active ? '' : ' ('.__('inactif').')'), $row->warehouse_name.($row->warehouse_active ? '' : ' ('.__('inactif').')'), ReportService::decimal($row->report_quantity, 3), $row->unit?->symbol ?? '—', $row->minimum_stock ?? '—', __(['rupture' => 'Rupture', 'low' => 'Stock faible', 'available' => 'Disponible'][$row->report_state] ?? $row->report_state)],
                'movements' => [$row->created_at->format('d/m/Y H:i'), $row->product?->name ?? '—', $row->warehouse?->name ?? '—', __($row->type), $row->quantity, $row->reference ?? '—'],
            };
        }

        return compact('headers', 'rows', 'paginator');
    }

    public function with(): array
    {
        Gate::authorize('reports.view');
        $reports = app(ReportService::class);
        $from = $this->appliedFrom;
        $to = $this->appliedTo;
        $currency = $reports->currency();
        $summary = match ($this->section) {
            'sales' => $reports->salesSummary($from, $to),
            'purchases' => $reports->purchaseSummary($from, $to),
            'payments' => $reports->paymentSummary($from, $to),
            'expenses' => $reports->expenseSummary($from, $to),
            'receivables' => $reports->receivablesSummary(),
            'stock' => $reports->stockSummary(),
        };
        $tables = [$this->section => $this->displayTable($reports->table($this->section, $from, $to), $this->section, $currency)];
        foreach (match ($this->section) {
            'sales' => ['credits' => 'creditsPage'],
            'payments' => ['supplierPayments' => 'supplierPage'],
            'stock' => ['movements' => 'movementsPage'],
            default => [],
        } as $kind => $pageName) {
            $tables[$kind] = $this->displayTable($reports->table($kind, $from, $to, $pageName), $kind, $currency);
        }

        return ['summary' => $summary, 'currency' => $currency, 'tables' => $tables, 'monthly' => $this->section === 'sales' ? $reports->monthlySummary($from, $to) : []];
    }
}; ?>
<section class="space-y-5">
    <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Rapports ERP') }}">
        @foreach (['sales' => 'Ventes', 'purchases' => 'Achats', 'payments' => 'Paiements', 'expenses' => 'Dépenses', 'receivables' => 'Créances clients', 'stock' => 'Stock'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $section === $key ? 'true' : 'false' }}" wire:click="selectSection('{{ $key }}')" @class(['border px-4 py-2 text-sm font-medium', 'border-indigo-600 bg-indigo-50 text-indigo-800' => $section === $key, 'border-gray-300 bg-white text-gray-700' => $section !== $key])>{{ __($label) }}</button>
        @endforeach
    </div>
    <x-report-period :applied-from="$appliedFrom" :applied-to="$appliedTo" :label="$section === 'receivables' ? __('Échéances du / au — restants actuels') : ($section === 'stock' ? __('Période des mouvements — soldes actuels indépendants') : __('Période des opérations'))" />
    @if ($section === 'receivables')<p class="text-sm text-gray-500">{{ __('Les indicateurs décrivent toutes les créances actuelles. Le tableau affiche les créances ouvertes dont l’échéance est dans la période ; les factures sans échéance restent incluses dans les indicateurs.') }}</p>@endif
    @if ($section === 'stock')<p class="text-sm text-gray-500">{{ __('Soldes actuels par couple produit/dépôt, incluant les éléments inactifs identifiés. Aucune valorisation ni quantité globale entre unités différentes.') }}</p>@endif
    @if ($section === 'purchases')<p class="text-sm text-gray-500">{{ __('Achats et paiements sur la période. Restants fournisseurs calculés à ce jour.') }}</p>@endif
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">@foreach ($summary as $title => $value)<x-kpi-card :title="$title" :value="is_int($value) ? $value : \App\Services\ReportService::money($value, $currency)" />@endforeach</div>
    @if ($section === 'sales')
        <div class="overflow-x-auto border border-gray-200 bg-white"><h3 class="p-4 font-semibold">{{ __('Synthèse mensuelle') }}</h3><div class="erp-table-scroll"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-start"><tr><th class="p-4">{{ __('Mois') }}</th><th class="p-4">{{ __('CA net HT') }}</th><th class="p-4">{{ __('Encaissements') }}</th></tr></thead><tbody>@forelse ($monthly as $month)<tr class="border-t"><td class="p-4">{{ $month['month'] }}</td><td class="p-4">{{ \App\Services\ReportService::money($month['net_ht'], $currency) }}</td><td class="p-4">{{ \App\Services\ReportService::money($month['payments'], $currency) }}</td></tr>@empty<tr><td colspan="3" class="p-4 text-gray-500">{{ __('Aucune opération sur la période.') }}</td></tr>@endforelse</tbody></table></div></div>
    @endif
    @foreach ($tables as $kind => $table)
        <div wire:key="report-table-{{ $kind }}" class="overflow-x-auto border border-gray-200 bg-white">
            <h3 class="p-4 font-semibold">{{ __(['sales' => 'Factures émises', 'credits' => 'Avoirs émis', 'purchases' => 'Factures fournisseurs', 'payments' => 'Paiements clients', 'supplierPayments' => 'Paiements fournisseurs', 'expenses' => 'Dépenses', 'receivables' => 'Créances ouvertes par échéance', 'stock' => 'Stock actuel', 'movements' => 'Mouvements de la période'][$kind]) }}</h3>
            <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="bg-gray-50 text-start text-xs uppercase text-gray-500"><tr>@foreach ($table['headers'] as $header)<th class="whitespace-nowrap px-4 py-3">{{ __($header) }}</th>@endforeach</tr></thead><tbody class="divide-y divide-gray-100">@forelse ($table['rows'] as $cells)<tr>@foreach ($cells as $cell)<td class="whitespace-nowrap px-4 py-3">{{ $cell }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($table['headers']) }}" class="p-8 text-center text-gray-500">{{ __('Aucune donnée pour cette sélection.') }}</td></tr>@endforelse</tbody></table></div>
            <div class="p-4">{{ $table['paginator']->links() }}</div>
        </div>
    @endforeach
</section>
