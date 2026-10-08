@props(['title', 'value', 'subtitle' => null, 'accent' => null])
@php
    $accents = ['CA net HT'=>'#087FF5','Encaissements'=>'#18C978','Créances clients'=>'#FF8A00','Dépenses TTC'=>'#19BFEF','Alertes stock'=>'#DC3545','Solde caisse'=>'#0DB6AD'];
    $color = $accent ?? $accents[$title] ?? '#087FF5';
@endphp
<div class="erp-kpi" style="--kpi-accent: {{ $color }}">
    <p class="erp-kpi-label">{{ __($title) }}</p>
    <p class="erp-kpi-value"><bdi class="erp-ltr">{{ $value }}</bdi></p>
    @if($subtitle)<p class="erp-kpi-subtitle">{{ __($subtitle) }}</p>@endif
</div>
