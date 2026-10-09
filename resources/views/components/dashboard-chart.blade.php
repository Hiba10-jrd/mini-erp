@props(['chart', 'currency'])
@php
    $series = [
        ['key' => 'net_ht', 'label' => __('Chiffre d’affaires HT'), 'color' => '#2563eb'],
        ['key' => 'purchases', 'label' => __('Achats facturés TTC'), 'color' => '#8b5cf6'],
        ['key' => 'payments', 'label' => __('Encaissements'), 'color' => '#10b981'],
        ['key' => 'expenses', 'label' => __('Dépenses TTC'), 'color' => '#ef6464'],
    ];
    $rows = $chart['rows'];
    $values = collect($rows)->flatMap(fn ($row) => array_map(fn ($series) => (float) $row[$series['key']], $series));
    $low = min(0, $values->min() ?? 0);
    $high = max(1, $values->max() ?? 0);
    $range = $high - $low;
    $low = $low < 0 ? $low - $range * .08 : 0;
    $high += $range * .12;
    $y = fn ($value) => 252 - ((float) $value - $low) / ($high - $low) * 224;
    $x = fn ($index) => count($rows) === 1 ? 430 : 66 + $index / (count($rows) - 1) * 728;
    $paths = [];
    foreach ($series as $line) {
        $paths[$line['key']] = implode(' ', array_map(fn ($index) => ($index === 0 ? 'M' : 'L').round($x($index), 2).','.round($y($rows[$index][$line['key']]), 2), array_keys($rows)));
    }
    $empty = $values->every(fn ($value) => $value === 0.0);
    $locale = str_replace('_', '-', app()->getLocale());
@endphp
<section class="dashboard-panel dashboard-chart" wire:key="dashboard-chart-{{ sha1(json_encode($chart)) }}" aria-label="{{ __('Évolution de l’activité') }}">
    <div class="dashboard-panel-heading">
        <div><h2>{{ __('Évolution de l’activité') }}</h2><p>{{ __('Suivez l’évolution du chiffre d’affaires, des achats, des encaissements et des dépenses.') }}</p></div>
        <span class="dashboard-unit"><bdi>{{ $currency }}</bdi></span>
    </div>
    <ul class="dashboard-chart-legend" aria-label="{{ __('Séries du graphe') }}">
        @foreach ($series as $line)<li><span style="background: {{ $line['color'] }}" aria-hidden="true"></span>{{ $line['label'] }}</li>@endforeach
    </ul>
    <div class="dashboard-chart-stage" dir="ltr" x-data="{ point: null, rows: @js($rows), formatter: new Intl.NumberFormat(@js($locale), { style: 'currency', currency: @js($currency), maximumFractionDigits: 2 }) }" @mouseleave="if (!$el.contains(document.activeElement)) point = null" @keydown.escape="point = null">
        <svg viewBox="0 0 820 292" class="dashboard-chart-svg" role="group" aria-label="{{ __('Montants par période') }}">
            <defs><linearGradient id="dashboard-revenue-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#3b82f6" stop-opacity=".16" /><stop offset="100%" stop-color="#3b82f6" stop-opacity=".01" /></linearGradient></defs>
            @for ($tick = 0; $tick <= 4; $tick++)
                @php $value = $low + ($high - $low) * $tick / 4; @endphp
                <line x1="66" x2="794" y1="{{ $y($value) }}" y2="{{ $y($value) }}" stroke="#edf1f7" stroke-dasharray="3 5" />
                <text x="52" y="{{ $y($value) + 4 }}" text-anchor="end" class="dashboard-chart-axis">{{ number_format($value, 0, app()->getLocale() === 'en' ? '.' : ',', ' ') }}</text>
            @endfor
            <path d="{{ $paths['net_ht'] }} L{{ $x(count($rows) - 1) }},{{ $y(0) }} L{{ $x(0) }},{{ $y(0) }} Z" fill="url(#dashboard-revenue-fill)" />
            @foreach ($series as $line)
                <path d="{{ $paths[$line['key']] }}" fill="none" stroke="{{ $line['color'] }}" stroke-width="{{ $line['key'] === 'net_ht' ? 2.8 : 2 }}" stroke-linejoin="round" stroke-linecap="round" />
                @foreach ($rows as $index => $row)<circle cx="{{ $x($index) }}" cy="{{ $y($row[$line['key']]) }}" r="{{ count($rows) <= 15 ? 2.7 : 1.6 }}" fill="{{ $line['color'] }}" />@endforeach
            @endforeach
            @foreach ($rows as $index => $row)
                @if ($index === 0 || $index === count($rows) - 1 || $index % max(1, (int) ceil(count($rows) / 6)) === 0)
                    <text x="{{ $x($index) }}" y="282" text-anchor="{{ $index === 0 ? 'start' : ($index === count($rows) - 1 ? 'end' : 'middle') }}" class="dashboard-chart-axis dashboard-chart-axis-wide">{{ $row['label'] }}</text>
                @endif
                @if (in_array($index, [0, (int) floor((count($rows) - 1) / 2), count($rows) - 1], true))
                    <text x="{{ $x($index) }}" y="282" text-anchor="{{ $index === 0 ? 'start' : ($index === count($rows) - 1 ? 'end' : 'middle') }}" class="dashboard-chart-axis dashboard-chart-axis-mobile">{{ $row['label'] }}</text>
                @endif
                @php
                    $left = $index === 0 ? 66 : ($x($index - 1) + $x($index)) / 2;
                    $right = $index === count($rows) - 1 ? 794 : ($x($index) + $x($index + 1)) / 2;
                @endphp
                <rect x="{{ $left }}" y="20" width="{{ $right - $left }}" height="240" fill="transparent" tabindex="0" role="button" aria-label="{{ __('Données du :date', ['date' => $row['label']]) }}" @mouseenter="point = {{ $index }}" @focus="point = {{ $index }}" @blur="point = null" @click="point = {{ $index }}" @keydown.enter.prevent="point = {{ $index }}" @keydown.space.prevent="point = {{ $index }}">
                    <title>{{ $row['label'] }}@foreach ($series as $line) · {{ $line['label'] }} : {{ \App\Services\ReportService::money($row[$line['key']], $currency) }}@endforeach</title>
                </rect>
            @endforeach
        </svg>
        <div x-cloak x-show="point !== null" class="dashboard-chart-tooltip" :style="'left:clamp(min(120px, 47.5%), ' + (8 + (point / Math.max(1, rows.length - 1)) * 89) + '%, max(calc(100% - 120px), 52.5%))'" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" role="status" aria-live="polite">
            <strong x-text="rows[point]?.label"></strong>
            @foreach ($series as $line)<p><span><i style="background: {{ $line['color'] }}"></i>{{ $line['label'] }}</span><bdi x-text="formatter.format(rows[point]?.['{{ $line['key'] }}'] ?? 0)"></bdi></p>@endforeach
        </div>
    </div>
    <div class="dashboard-chart-footnote"><span>{{ __('Les avoirs émis sont déduits du chiffre d’affaires HT.') }}</span>@if ($empty)<span>{{ __('Aucune opération sur cette période.') }}</span>@endif</div>
    <details class="dashboard-chart-data"><summary>{{ __('Afficher les données du graphe') }}</summary><div class="overflow-x-auto"><table><thead><tr><th>{{ __('Période') }}</th>@foreach ($series as $line)<th>{{ $line['label'] }}</th>@endforeach</tr></thead><tbody>@foreach ($rows as $row)<tr><td>{{ $row['label'] }}</td>@foreach ($series as $line)<td><bdi class="erp-ltr">{{ \App\Services\ReportService::money($row[$line['key']], $currency) }}</bdi></td>@endforeach</tr>@endforeach</tbody></table></div></details>
</section>
