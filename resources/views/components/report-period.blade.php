@props(['label' => 'Période des opérations', 'appliedFrom', 'appliedTo'])
<form wire:submit="applyPeriod" class="space-y-3 border border-gray-200 bg-white p-5">
    <p class="text-sm font-medium text-gray-700">{{ $label }}</p>
    <div class="flex flex-wrap items-end gap-3">
        <div><x-input-label for="report-from" value="Du" /><x-text-input id="report-from" type="date" wire:model="from" /><x-input-error :messages="$errors->get('from')" /></div>
        <div><x-input-label for="report-to" value="Au" /><x-text-input id="report-to" type="date" wire:model="to" /><x-input-error :messages="$errors->get('to')" /></div>
        <x-primary-button>Appliquer</x-primary-button>
    </div>
    <div class="flex flex-wrap gap-2">@foreach (['today' => "Aujourd’hui", 'month' => 'Ce mois', 'previous' => 'Mois précédent', 'year' => 'Cette année'] as $key => $label)<x-secondary-button wire:click="preset('{{ $key }}')">{{ $label }}</x-secondary-button>@endforeach</div>
    <p class="text-xs text-gray-500">Période appliquée : {{ $appliedFrom }} — {{ $appliedTo }}</p>
</form>
