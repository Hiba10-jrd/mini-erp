@props(['title', 'value', 'subtitle' => null])
<div class="border border-gray-200 bg-white p-5">
    <p class="text-sm text-gray-500">{{ $title }}</p>
    <p class="mt-2 text-xl font-semibold text-gray-900">{{ $value }}</p>
    @if ($subtitle)<p class="mt-2 text-xs text-gray-500">{{ $subtitle }}</p>@endif
</div>
