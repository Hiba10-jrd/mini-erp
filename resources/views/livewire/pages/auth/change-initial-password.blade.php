<?php

use App\Livewire\Actions\Logout;
use App\Models\User;
use App\Services\PasswordManagementService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 401);

        if (! $user->must_change_password) {
            $this->redirectRoute('dashboard', navigate: true);
        }
    }

    public function updatePassword(PasswordManagementService $passwordService): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isActive(), 403);
        abort_unless($user->must_change_password, 403);

        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed', 'different:current_password'],
            ]);

            $passwordService->changeOwnPassword(
                $user->getKey(),
                $validated['current_password'],
                $validated['password'],
                request()->hasSession() ? request()->session()->getId() : null
            );
        } catch (ValidationException $exception) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $exception;
        }

        Session::regenerate();
        $this->reset('current_password', 'password', 'password_confirmation');

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirectRoute('login', navigate: true);
    }
}; ?>

<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ __('Modifiez votre mot de passe') }}</h1>
        <p class="mt-2 text-sm text-gray-600">
            {{ __('Pour sécuriser votre compte, remplacez le mot de passe temporaire avant de continuer.') }}
        </p>
    </div>

    <form wire:submit="updatePassword" class="space-y-5">
        <div>
            <x-input-label for="required-current-password" :value="__('Mot de passe actuel')" />
            <x-text-input id="required-current-password" wire:model="current_password" type="password" class="mt-1 block w-full" required autocomplete="current-password" autofocus />
            <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="required-new-password" :value="__('Nouveau mot de passe')" />
            <x-text-input id="required-new-password" wire:model="password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="required-password-confirmation" :value="__('Confirmer le nouveau mot de passe')" />
            <x-text-input id="required-password-confirmation" wire:model="password_confirmation" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between gap-4">
            <button type="button" wire:click="logout" class="text-sm text-gray-600 underline hover:text-gray-900">
                {{ __('Se déconnecter') }}
            </button>

            <x-primary-button wire:loading.attr="disabled" wire:target="updatePassword">
                {{ __('Enregistrer et continuer') }}
            </x-primary-button>
        </div>
    </form>
</div>
