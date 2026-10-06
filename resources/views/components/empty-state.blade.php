@props(['title' => 'Aucun résultat', 'description' => 'Les éléments disponibles apparaîtront ici.'])
<div {{ $attributes->class(['erp-empty-state']) }}><span class="erp-empty-symbol" aria-hidden="true">☷</span><h3>{{ $title }}</h3><p>{{ $description }}</p>{{ $slot }}</div>
