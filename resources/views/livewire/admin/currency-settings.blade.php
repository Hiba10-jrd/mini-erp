<?php

use App\Models\CommercialSetting;
use App\Services\CommercialSettingsService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component
{
    public string $currencyCode = '';

    public string $currencyName = '';

    public ?string $feedback = null;

    public function mount(): void
    {
        Gate::authorize('company.administer');
        $setting = CommercialSetting::query()->first();
        $this->currencyCode = $setting?->currency_code ?? '';
        $this->currencyName = $setting?->currency_name ?? '';
    }

    public function saveCurrency(CommercialSettingsService $service): void
    {
        Gate::authorize('company.administer');
        $this->currencyCode = Str::upper(trim($this->currencyCode));
        $this->currencyName = trim($this->currencyName);

        $validated = $this->validate([
            'currencyCode' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'currencyName' => ['required', 'string', 'max:100'],
        ]);

        $service->save([
            'currency_code' => $validated['currencyCode'],
            'currency_name' => $validated['currencyName'],
        ]);
        $this->feedback = __('La devise a été enregistrée.');
    }
}; ?>

<section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
    <div class="border-b border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900">{{ __('Devise principale') }}</h3>
        <p class="mt-1 text-sm text-gray-600">{{ __('La devise configurée sera réutilisée par les futurs documents commerciaux.') }}</p>
    </div>
    <form wire:submit="saveCurrency" class="grid gap-5 p-6 sm:grid-cols-2">
        <div>
            <x-input-label for="currency-code" :value="__('Code de devise')" />
            <x-text-input id="currency-code" wire:model="currencyCode" class="mt-1 block w-full uppercase" maxlength="3" placeholder="MAD" required />
            <x-input-error :messages="$errors->get('currencyCode')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="currency-name" :value="__('Nom de la devise')" />
            <x-text-input id="currency-name" wire:model="currencyName" class="mt-1 block w-full" placeholder="Dirham marocain" required />
            <x-input-error :messages="$errors->get('currencyName')" class="mt-2" />
        </div>
        <div class="flex items-center justify-between gap-4 sm:col-span-2">
            <p class="text-sm text-gray-600">{{ $currencyCode && $currencyName ? __('Devise actuelle : :code — :name', ['code' => $currencyCode, 'name' => $currencyName]) : __('Aucune devise configurée.') }}</p>
            <x-primary-button type="submit">{{ __('Enregistrer') }}</x-primary-button>
        </div>
        @if ($feedback)<p class="text-sm text-emerald-700 sm:col-span-2" role="status">{{ $feedback }}</p>@endif
    </form>
</section>
