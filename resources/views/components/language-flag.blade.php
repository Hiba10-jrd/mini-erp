@props(['country'])
<svg class="erp-language-flag" viewBox="0 0 30 20" aria-hidden="true">
    @if ($country === 'fr')
        <path fill="#fff" d="M0 0h30v20H0z" /><path fill="#002395" d="M0 0h10v20H0z" /><path fill="#ed2939" d="M20 0h10v20H20z" />
    @elseif ($country === 'gb')
        <path fill="#012169" d="M0 0h30v20H0z" /><path stroke="#fff" stroke-width="5" d="m0 0 30 20M30 0 0 20" /><path stroke="#c8102e" stroke-width="2" d="m0 0 30 20M30 0 0 20" /><path stroke="#fff" stroke-width="7" d="M15 0v20M0 10h30" /><path stroke="#c8102e" stroke-width="4" d="M15 0v20M0 10h30" />
    @else
        <path fill="#006c35" d="M0 0h30v20H0z" /><text x="15" y="10" text-anchor="middle" fill="#fff" font-size="4" font-family="Arial, sans-serif" lang="ar" direction="rtl" textLength="23" lengthAdjust="spacingAndGlyphs">لا إله إلا الله محمد رسول الله</text><path stroke="#fff" stroke-width="1" d="M7 15h16m-1-1v2" />
    @endif
</svg>
