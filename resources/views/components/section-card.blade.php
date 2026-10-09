@props(['title' => null, 'description' => null])
<section {{ $attributes->class(['erp-card']) }}>@if($title)<div class="erp-section-heading"><h3>{{ __($title) }}</h3>@if($description)<p>{{ __($description) }}</p>@endif</div>@endif{{ $slot }}</section>
