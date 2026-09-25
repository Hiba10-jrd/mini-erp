<?php

use App\Enums\UserAccountStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\PasswordManagementService;
use App\Services\UserManagementService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'current';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** @var array<int, int|string> */
    public array $selectedRoleIds = [];

    #[Locked]
    public ?int $editingUserId = null;

    #[Locked]
    public ?int $passwordUserId = null;

    public string $temporaryPassword = '';

    public string $temporaryPassword_confirmation = '';

    public ?string $feedback = null;

    public function mount(): void
    {
        Gate::authorize('users.administer');
    }

    public function with(): array
    {
        Gate::authorize('users.administer');

        $search = trim($this->search);
        $statusFilter = in_array($this->statusFilter, ['current', 'active', 'disabled', 'archived', 'all'], true)
            ? $this->statusFilter
            : 'current';

        return [
            'users' => User::query()
                ->with('roles')
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->when(
                    $statusFilter === 'current',
                    fn ($query) => $query->where('account_status', '<>', UserAccountStatus::Archived->value)
                )
                ->when(
                    in_array($statusFilter, ['active', 'disabled', 'archived'], true),
                    fn ($query) => $query->where('account_status', $statusFilter)
                )
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(10),
            'roles' => Role::query()->orderBy('name')->get(),
        ];
    }

    public function updatingSearch(): void
    {
        Gate::authorize('users.administer');
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        Gate::authorize('users.administer');
        $this->resetPage();
    }

    public function prepareCreate(): void
    {
        Gate::authorize('users.administer');
        $this->resetUserForm();
        $this->dispatch('open-modal', name: 'manage-user');
    }

    public function editUser(int $userId): void
    {
        Gate::authorize('users.administer');

        $user = User::query()->with('roles')->findOrFail($userId);

        abort_if($user->isArchived(), 403);

        $this->editingUserId = $user->getKey();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->password_confirmation = '';
        $this->selectedRoleIds = $user->roles
            ->pluck('id')
            ->map(fn ($roleId): int => (int) $roleId)
            ->all();
        $this->resetValidation();

        $this->dispatch('open-modal', name: 'manage-user');
    }

    public function saveUser(UserManagementService $service): void
    {
        Gate::authorize('users.administer');

        $this->name = trim($this->name);
        $this->email = Str::lower(trim($this->email));

        $validated = $this->validate($this->userRules());
        $attributes = [
            'name' => $validated['name'],
            'email' => $validated['email'],
        ];

        if ($this->editingUserId === null) {
            $attributes['password'] = $validated['password'];
            $service->createUser($attributes, $validated['selectedRoleIds']);
            $this->feedback = __('Utilisateur créé. Il devra changer son mot de passe à sa première connexion.');
        } else {
            $updatedUser = $service->updateUser(
                $this->editingUserId,
                $attributes,
                $validated['selectedRoleIds']
            );

            if ($updatedUser->is(Auth::user()) && ! $updatedUser->isSuperAdministrator()) {
                $this->redirectRoute('dashboard', navigate: true);

                return;
            }

            $this->feedback = __('Utilisateur mis à jour.');
        }

        $this->resetUserForm();
        $this->dispatch('close-modal', name: 'manage-user');
    }

    public function preparePasswordReset(int $userId): void
    {
        Gate::authorize('users.administer');

        $user = User::query()->with('roles')->findOrFail($userId);

        abort_if($user->isSuperAdministrator() || $user->isArchived(), 403);

        $this->passwordUserId = $user->getKey();
        $this->reset('temporaryPassword', 'temporaryPassword_confirmation');
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'reset-user-password');
    }

    public function resetUserPassword(PasswordManagementService $passwordService): void
    {
        Gate::authorize('users.administer');

        $validated = $this->validate([
            'passwordUserId' => ['required', 'integer'],
            'temporaryPassword' => ['required', 'string', Password::defaults(), 'confirmed:temporaryPassword_confirmation'],
        ]);

        $passwordService->resetByAdministrator(
            (int) $validated['passwordUserId'],
            $validated['temporaryPassword']
        );

        $this->reset('passwordUserId', 'temporaryPassword', 'temporaryPassword_confirmation');
        $this->feedback = __('Mot de passe temporaire enregistré. Les sessions actives ont été invalidées.');
        $this->dispatch('close-modal', name: 'reset-user-password');
    }

    public function disableUser(int $userId, UserManagementService $service): void
    {
        Gate::authorize('users.administer');
        $service->disableUser($userId);
        $this->feedback = __('Compte désactivé.');
    }

    public function reactivateUser(int $userId, UserManagementService $service): void
    {
        Gate::authorize('users.administer');
        $service->reactivateUser($userId);
        $this->feedback = __('Compte réactivé avec ses rôles existants.');
    }

    public function archiveUser(int $userId, UserManagementService $service): void
    {
        Gate::authorize('users.administer');
        $service->archiveUser($userId);
        $this->feedback = __('Compte archivé.');
    }

    /** @return array<string, array<int, mixed>> */
    private function userRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->editingUserId),
            ],
            'password' => $this->editingUserId === null
                ? ['required', 'string', Password::defaults(), 'confirmed']
                : ['nullable', 'string'],
            'selectedRoleIds' => ['required', 'array', 'min:1'],
            'selectedRoleIds.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(Role::class, 'id'),
            ],
        ];
    }

    private function resetUserForm(): void
    {
        $this->reset(
            'name',
            'email',
            'password',
            'password_confirmation',
            'selectedRoleIds',
            'editingUserId'
        );
        $this->resetValidation();
    }
}; ?>

<div class="space-y-6">
    @if ($feedback)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
            {{ $feedback }}
        </div>
    @endif

    @error('accountStatus')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            {{ $message }}
        </div>
    @enderror

    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
        <div class="border-b border-gray-200 p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('Utilisateurs') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Créez les comptes internes, attribuez leurs rôles et gérez leur accès.') }}</p>
                </div>

                <x-primary-button type="button" wire:click="prepareCreate">
                    {{ __('Créer un utilisateur') }}
                </x-primary-button>
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="user-search" :value="__('Rechercher par nom ou adresse email')" />
                    <x-text-input id="user-search" type="search" class="mt-1 block w-full" wire:model.live.debounce.300ms="search" :placeholder="__('Rechercher…')" />
                </div>

                <div>
                    <x-input-label for="user-status-filter" :value="__('État du compte')" />
                    <select id="user-status-filter" wire:model.live="statusFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="current">{{ __('Actifs et désactivés') }}</option>
                        <option value="active">{{ __('Actifs') }}</option>
                        <option value="disabled">{{ __('Désactivés') }}</option>
                        <option value="archived">{{ __('Archivés') }}</option>
                        <option value="all">{{ __('Tous') }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">{{ __('Nom') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">{{ __('Email') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">{{ __('Rôles') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">{{ __('État') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">{{ __('Créé le') }}</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-600">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse ($users as $user)
                        @php($isSuperAdministrator = $user->roles->contains('slug', 'super-admin'))
                        <tr wire:key="user-row-{{ $user->id }}" class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">{{ $user->name }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">{{ $user->email }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($user->roles as $role)
                                        <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700">{{ $role->name }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm">
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-xs font-medium',
                                    'bg-emerald-50 text-emerald-700' => $user->isActive(),
                                    'bg-amber-50 text-amber-800' => $user->isDisabled(),
                                    'bg-gray-100 text-gray-700' => $user->isArchived(),
                                ])>{{ $user->account_status->label() }}</span>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">{{ $user->created_at->format('d/m/Y') }}</td>
                            <td class="px-6 py-4 text-right text-sm font-medium">
                                <div class="flex flex-wrap justify-end gap-x-4 gap-y-2">
                                    @unless ($user->isArchived())
                                        <button type="button" wire:click="editUser({{ $user->id }})" class="text-indigo-600 hover:text-indigo-900">{{ __('Modifier') }}</button>
                                    @endunless

                                    @unless ($isSuperAdministrator || $user->isArchived())
                                        <button type="button" wire:click="preparePasswordReset({{ $user->id }})" class="text-gray-600 hover:text-gray-900">{{ __('Réinitialiser le mot de passe') }}</button>
                                    @endunless

                                    @if (! $isSuperAdministrator && $user->isActive())
                                        <button type="button" wire:click="disableUser({{ $user->id }})" wire:confirm="{{ __('Désactiver ce compte et invalider ses sessions actives ?') }}" class="text-amber-700 hover:text-amber-900">{{ __('Désactiver') }}</button>
                                    @elseif (! $isSuperAdministrator && $user->isDisabled())
                                        <button type="button" wire:click="reactivateUser({{ $user->id }})" class="text-emerald-700 hover:text-emerald-900">{{ __('Réactiver') }}</button>
                                    @endif

                                    @if (! $isSuperAdministrator && ! $user->isArchived())
                                        <button type="button" wire:click="archiveUser({{ $user->id }})" wire:confirm="{{ __('Archiver définitivement ce compte ? Aucune restauration n’est disponible dans ce lot.') }}" class="text-red-600 hover:text-red-900">{{ __('Archiver') }}</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <p class="text-sm font-medium text-gray-900">{{ __('Aucun utilisateur trouvé') }}</p>
                                <p class="mt-1 text-sm text-gray-500">{{ __('Modifiez la recherche ou le filtre sélectionné.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="border-t border-gray-200 px-6 py-4">{{ $users->links() }}</div>
        @endif
    </div>

    <x-modal name="manage-user" focusable>
        <form wire:submit="saveUser" class="p-6">
            <h3 class="text-lg font-semibold text-gray-900">{{ $editingUserId === null ? __('Créer un utilisateur') : __('Modifier l’utilisateur') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('Les rôles et les données sont vérifiés côté serveur avant toute modification.') }}</p>

            <div class="mt-6 space-y-5">
                <div>
                    <x-input-label for="managed-user-name" :value="__('Nom')" />
                    <x-text-input id="managed-user-name" type="text" class="mt-1 block w-full" wire:model="name" required autocomplete="name" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="managed-user-email" :value="__('Adresse email')" />
                    <x-text-input id="managed-user-email" type="email" class="mt-1 block w-full" wire:model="email" required autocomplete="email" />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                @if ($editingUserId === null)
                    <div>
                        <x-input-label for="managed-user-password" :value="__('Mot de passe initial')" />
                        <x-text-input id="managed-user-password" type="password" class="mt-1 block w-full" wire:model="password" required autocomplete="new-password" />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="managed-user-password-confirmation" :value="__('Confirmer le mot de passe initial')" />
                        <x-text-input id="managed-user-password-confirmation" type="password" class="mt-1 block w-full" wire:model="password_confirmation" required autocomplete="new-password" />
                        <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                    </div>
                @endif

                <fieldset>
                    <legend class="text-sm font-medium text-gray-700">{{ __('Rôles') }}</legend>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        @foreach ($roles as $role)
                            <label wire:key="managed-role-{{ $role->id }}" class="flex items-center gap-3 rounded-lg border border-gray-200 px-3 py-3 text-sm text-gray-700 hover:bg-gray-50">
                                <input type="checkbox" wire:model="selectedRoleIds" value="{{ $role->id }}" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                <span>{{ $role->name }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('selectedRoleIds')" class="mt-2" />
                    <x-input-error :messages="$errors->has('selectedRoleIds.*') ? [$errors->first('selectedRoleIds.*')] : []" class="mt-2" />
                </fieldset>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Annuler') }}</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="saveUser">{{ $editingUserId === null ? __('Créer le compte') : __('Enregistrer') }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="reset-user-password" focusable>
        <form wire:submit="resetUserPassword" class="p-6">
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Réinitialiser le mot de passe') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('Les sessions actives seront invalidées et l’utilisateur devra remplacer ce mot de passe temporaire.') }}</p>

            <div class="mt-6 space-y-5">
                <div>
                    <x-input-label for="temporary-password" :value="__('Nouveau mot de passe temporaire')" />
                    <x-text-input id="temporary-password" type="password" class="mt-1 block w-full" wire:model="temporaryPassword" required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('temporaryPassword')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="temporary-password-confirmation" :value="__('Confirmer le mot de passe temporaire')" />
                    <x-text-input id="temporary-password-confirmation" type="password" class="mt-1 block w-full" wire:model="temporaryPassword_confirmation" required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('temporaryPassword_confirmation')" class="mt-2" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Annuler') }}</x-secondary-button>
                <x-danger-button wire:loading.attr="disabled" wire:target="resetUserPassword">{{ __('Réinitialiser') }}</x-danger-button>
            </div>
        </form>
    </x-modal>
</div>
