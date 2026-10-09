@props(['title' => 'Aucun résultat', 'description' => 'Les éléments disponibles apparaîtront ici.'])
<div {{ $attributes->class(['erp-empty-state']) }}><span class="erp-empty-symbol" aria-hidden="true">☷</span><h3>{{ __($title) }}</h3><p>{{ __($description) }}</p>{{ $slot }}</div>
