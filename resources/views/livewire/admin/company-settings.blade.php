<?php

use App\Models\Company;
use App\Services\CompanyManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public string $legalName = '';

    public string $tradeName = '';

    public string $ice = '';

    public string $taxId = '';

    public string $commercialRegister = '';

    public string $address = '';

    public string $phone = '';

    public string $email = '';

    public string $website = '';

    public string $bankName = '';

    public string $bankAccountHolder = '';

    public string $bankReference = '';

    public ?TemporaryUploadedFile $logo = null;

    public ?string $feedback = null;

    public function mount(): void
    {
        Gate::authorize('company.administer');
        $this->fillFromCompany(Company::query()->first());
    }

    public function with(): array
    {
        Gate::authorize('company.administer');

        $company = Company::query()->first();

        return [
            'company' => $company,
            'logoUrl' => $company?->logo_path
                ? Storage::disk('public')->url($company->logo_path)
                : null,
        ];
    }

    public function updatedLogo(): void
    {
        Gate::authorize('company.administer');
        $this->validateOnly('logo', ['logo' => $this->logoRules()]);
    }

    public function saveCompany(CompanyManagementService $service): void
    {
        Gate::authorize('company.administer');

        $validated = $this->validate($this->rules());

        $service->save([
            'legal_name' => trim($validated['legalName']),
            'trade_name' => $this->nullableString($validated['tradeName']),
            'ice' => $this->nullableString($validated['ice']),
            'tax_id' => $this->nullableString($validated['taxId']),
            'commercial_register' => $this->nullableString($validated['commercialRegister']),
            'address' => $this->nullableString($validated['address']),
            'phone' => $this->nullableString($validated['phone']),
            'email' => $this->nullableString($validated['email']),
            'website' => $this->nullableString($validated['website']),
            'bank_name' => $this->nullableString($validated['bankName']),
            'bank_account_holder' => $this->nullableString($validated['bankAccountHolder']),
            'bank_reference' => $this->nullableString($validated['bankReference']),
        ], $this->logo);

        $this->reset('logo');
        $this->feedback = __('Les informations de l’entreprise ont été enregistrées.');
        $this->fillFromCompany(Company::query()->first());
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'legalName' => ['required', 'string', 'max:255'],
            'tradeName' => ['nullable', 'string', 'max:255'],
            'ice' => ['nullable', 'string', 'max:100'],
            'taxId' => ['nullable', 'string', 'max:100'],
            'commercialRegister' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:2048'],
            'bankName' => ['nullable', 'string', 'max:255'],
            'bankAccountHolder' => ['nullable', 'string', 'max:255'],
            'bankReference' => ['nullable', 'string', 'max:255'],
            'logo' => $this->logoRules(),
        ];
    }

    /** @return array<int, string> */
    private function logoRules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function fillFromCompany(?Company $company): void
    {
        $this->legalName = $company?->legal_name ?? '';
        $this->tradeName = $company?->trade_name ?? '';
        $this->ice = $company?->ice ?? '';
        $this->taxId = $company?->tax_id ?? '';
        $this->commercialRegister = $company?->commercial_register ?? '';
        $this->address = $company?->address ?? '';
        $this->phone = $company?->phone ?? '';
        $this->email = $company?->email ?? '';
        $this->website = $company?->website ?? '';
        $this->bankName = $company?->bank_name ?? '';
        $this->bankAccountHolder = $company?->bank_account_holder ?? '';
        $this->bankReference = $company?->bank_reference ?? '';
    }
}; ?>

<div class="space-y-6">
    @if ($feedback)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
            {{ $feedback }}
        </div>
    @endif

    <form wire:submit="saveCompany" class="space-y-6">
        <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="border-b border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('Informations légales') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('Renseignez les informations officielles de l’entreprise.') }}</p>
            </div>
            <div class="grid gap-5 p-6 sm:grid-cols-2">
                <div>
                    <x-input-label for="company-legal-name" :value="__('Raison sociale')" />
                    <x-text-input id="company-legal-name" wire:model="legalName" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('legalName')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="company-trade-name" :value="__('Nom commercial')" />
                    <x-text-input id="company-trade-name" wire:model="tradeName" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('tradeName')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="company-ice" :value="__('ICE')" />
                    <x-text-input id="company-ice" wire:model="ice" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('ice')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="company-tax-id" :value="__('IF')" />
                    <x-text-input id="company-tax-id" wire:model="taxId" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('taxId')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="company-commercial-register" :value="__('RC')" />
                    <x-text-input id="company-commercial-register" wire:model="commercialRegister" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('commercialRegister')" class="mt-2" />
                </div>
            </div>
        </section>

        <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="border-b border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('Coordonnées') }}</h3>
            </div>
            <div class="grid gap-5 p-6 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-input-label for="company-address" :value="__('Adresse')" />
                    <textarea id="company-address" wire:model="address" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                    <x-input-error :messages="$errors->get('address')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="company-phone" :value="__('Téléphone')" />
                    <x-text-input id="company-phone" wire:model="phone" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="company-email" :value="__('Email')" />
                    <x-text-input id="company-email" type="email" wire:model="email" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="company-website" :value="__('Site web')" />
                    <x-text-input id="company-website" type="url" wire:model="website" class="mt-1 block w-full" placeholder="https://" />
                    <x-input-error :messages="$errors->get('website')" class="mt-2" />
                </div>
            </div>
        </section>

        <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="border-b border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('Identité visuelle') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('Formats acceptés : JPG, PNG ou WEBP, 2 Mo maximum.') }}</p>
            </div>
            <div class="grid gap-6 p-6 sm:grid-cols-[minmax(0,1fr)_12rem]">
                <div>
                    <x-input-label for="company-logo" :value="__('Logo de l’entreprise')" />
                    <input id="company-logo" type="file" wire:model="logo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="mt-1 block w-full rounded-md border border-gray-300 bg-white text-sm text-gray-700 shadow-sm file:mr-4 file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-gray-700" />
                    <x-input-error :messages="$errors->get('logo')" class="mt-2" />
                    <div wire:loading wire:target="logo" class="mt-2 text-sm text-gray-500">{{ __('Téléversement en cours...') }}</div>
                </div>
                <div class="flex min-h-32 items-center justify-center rounded-lg border border-gray-200 bg-gray-50 p-3">
                    @if ($logo && $logo->isPreviewable())
                        <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('Aperçu du logo') }}" class="max-h-28 max-w-full object-contain" />
                    @elseif ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ __('Logo de l’entreprise') }}" class="max-h-28 max-w-full object-contain" />
                    @else
                        <span class="text-center text-xs text-gray-500">{{ __('Aucun logo enregistré') }}</span>
                    @endif
                </div>
            </div>
        </section>

        <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="border-b border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('Informations bancaires') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('Ne renseignez pas de mot de passe bancaire ni d’identifiant de banque en ligne.') }}</p>
            </div>
            <div class="grid gap-5 p-6 sm:grid-cols-2">
                <div>
                    <x-input-label for="company-bank-name" :value="__('Nom de la banque')" />
                    <x-text-input id="company-bank-name" wire:model="bankName" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('bankName')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="company-bank-account-holder" :value="__('Titulaire du compte')" />
                    <x-text-input id="company-bank-account-holder" wire:model="bankAccountHolder" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('bankAccountHolder')" class="mt-2" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="company-bank-reference" :value="__('Références bancaires')" />
                    <x-text-input id="company-bank-reference" wire:model="bankReference" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('bankReference')" class="mt-2" />
                </div>
            </div>
        </section>

        <div class="flex justify-end">
            <x-primary-button type="submit">{{ __('Enregistrer les informations') }}</x-primary-button>
        </div>
    </form>
</div>
