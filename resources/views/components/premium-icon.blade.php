@props(['name' => 'document'])
<svg {{ $attributes->class(['premium-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('sales')<path d="M4 17 10 11l4 3 6-9M14 5h6v6M4 5v15h16" />@break
        @case('cart')<path d="M3 4h2l3 12h11l2-9H6M9 20h.01M18 20h.01" />@break
        @case('wallet')<path d="M20 8V5H5a2 2 0 0 0 0 4h15v11H5a2 2 0 0 1-2-2V7m17 6h-5v4h5m-2-2h.01" />@break
        @case('box')<path d="m12 3 9 5-9 5-9-5 9-5Zm-9 5v10l9 5 9-5V8m-9 5v10M7 6l10 5" />@break
        @case('bank')<path d="m3 7 9-4 9 4H3Zm2 4v7m7-7v7m7-7v7M3 21h18" />@break
        @case('settings')<path d="m9 3-1 3-3 1-2 5 2 5 3 1 1 3h6l1-3 3-1 2-5-2-5-3-1-1-3H9Z" /><circle cx="12" cy="12" r="3" />@break
        @default<path d="M14 3H5v18h14V8l-5-5Zm0 0v5h5M8 12h8m-8 4h5" />
    @endswitch
</svg>
