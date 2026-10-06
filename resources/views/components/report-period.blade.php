@props(['label' => 'Période des opérations', 'appliedFrom', 'appliedTo'])
<form wire:submit="applyPeriod" class="erp-card space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p><p class="text-xs text-slate-500">Période appliquée : {{ $appliedFrom }} — {{ $appliedTo }}</p></div>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-wrap gap-2">@foreach(['today'=>"Aujourd’hui",'month'=>'Ce mois','previous'=>'Mois précédent','year'=>'Cette année'] as $key=>$presetLabel)<x-secondary-button wire:click="preset('{{ $key }}')">{{ $presetLabel }}</x-secondary-button>@endforeach</div>
        <div class="flex flex-wrap items-end gap-3"><div><x-input-label for="report-from" value="Du" /><x-text-input id="report-from" type="date" wire:model="from" /><x-input-error :messages="$errors->get('from')" /></div><div><x-input-label for="report-to" value="Au" /><x-text-input id="report-to" type="date" wire:model="to" /><x-input-error :messages="$errors->get('to')" /></div><x-primary-button>Appliquer</x-primary-button></div>
    </div>
</form>
