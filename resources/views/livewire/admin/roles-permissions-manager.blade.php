<?php

use App\Models\Permission;
use App\Models\Role;
use App\Services\RolePermissionManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $selectedRoleId = null;

    /** @var array<int, string> */
    public array $selectedPermissionNames = [];

    public ?string $feedback = null;

    public function mount(): void
    {
        Gate::authorize('roles.administer');

        $this->selectedRoleId = Role::query()
            ->where('slug', '<>', 'super-admin')
            ->orderBy('name')
            ->value('id');

        $this->loadSelectedRolePermissions();
    }

    public function with(): array
    {
        Gate::authorize('roles.administer');

        $roles = Role::query()->with('permissions')->orderBy('name')->get();
        $selectedRole = $roles->firstWhere('id', $this->selectedRoleId);
        $permissions = Permission::query()
            ->whereIn('name', config('erp.permissions', []))
            ->get()
            ->keyBy('name');
        $permissionGroups = collect(config('erp.permissions', []))
            ->groupBy(fn (string $permission): string => Str::before($permission, '.'));

        return compact('roles', 'selectedRole', 'permissions', 'permissionGroups');
    }

    public function selectRole(int $roleId): void
    {
        Gate::authorize('roles.administer');

        if (! Role::query()->whereKey($roleId)->exists()) {
            throw ValidationException::withMessages([
                'selectedRoleId' => __('Le rôle sélectionné n’existe pas.'),
            ]);
        }

        $this->selectedRoleId = $roleId;
        $this->feedback = null;
        $this->resetValidation();
        $this->loadSelectedRolePermissions();
    }

    public function savePermissions(RolePermissionManagementService $service): void
    {
        Gate::authorize('roles.administer');

        $validated = $this->validate([
            'selectedRoleId' => ['required', 'integer', Rule::exists(Role::class, 'id')],
            'selectedPermissionNames' => ['array'],
            'selectedPermissionNames.*' => ['string', 'distinct', Rule::in(config('erp.permissions', []))],
        ]);

        $service->syncPermissions(
            (int) $validated['selectedRoleId'],
            $validated['selectedPermissionNames'] ?? []
        );

        $this->feedback = __('Les permissions du rôle ont été enregistrées.');
    }

    public function isConsultationWritePermission(string $permissionName, ?string $roleSlug): bool
    {
        return $roleSlug === 'consultation'
            && in_array(Str::after($permissionName, '.'), [
                'create',
                'update',
                'delete',
                'validate',
                'manage',
            ], true);
    }

    private function loadSelectedRolePermissions(): void
    {
        if ($this->selectedRoleId === null) {
            $this->selectedPermissionNames = [];

            return;
        }

        $this->selectedPermissionNames = Role::query()
            ->with('permissions')
            ->find($this->selectedRoleId)?->permissions
            ->pluck('name')
            ->all() ?? [];
    }
}; ?>

<div class="space-y-6">
    @if ($feedback)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
            {{ $feedback }}
        </div>
    @endif

    @error('selectedRoleId')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $message }}</div>
    @enderror

    @error('selectedPermissionNames')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $message }}</div>
    @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="border-b border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('Rôles') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('Sélectionnez un rôle pour consulter ses permissions.') }}</p>
            </div>
            <div class="divide-y divide-gray-200">
                @foreach ($roles as $role)
                    <button
                        type="button"
                        wire:key="role-{{ $role->id }}"
                        wire:click="selectRole({{ $role->id }})"
                        @class([
                            'flex w-full items-center justify-between px-6 py-4 text-left text-sm transition',
                            'bg-indigo-50 text-indigo-900' => $selectedRoleId === $role->id,
                            'text-gray-700 hover:bg-gray-50' => $selectedRoleId !== $role->id,
                        ])
                    >
                        <span class="font-medium">{{ $role->name }}</span>
                        @if ($role->slug === 'super-admin')
                            <span class="text-xs text-gray-500">{{ __('Protégé') }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </section>

        <section class="bg-white p-6 shadow-sm sm:rounded-lg lg:col-span-2">
            <div class="flex flex-col gap-3 border-b border-gray-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ $selectedRole?->name ?? __('Aucun rôle') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Les associations sont enregistrées dans le catalogue existant.') }}</p>
                </div>
                @if ($selectedRole && $selectedRole->slug !== 'super-admin')
                    <x-primary-button type="button" wire:click="savePermissions">{{ __('Enregistrer') }}</x-primary-button>
                @endif
            </div>

            <div class="mt-6 space-y-6">
                @foreach ($permissionGroups as $module => $modulePermissions)
                    <fieldset>
                        <legend class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ $module }}</legend>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach ($modulePermissions as $permissionName)
                                @php($isConsultationWritePermission = $this->isConsultationWritePermission($permissionName, $selectedRole?->slug))
                                <label
                                    wire:key="permission-{{ $permissionName }}"
                                    @class([
                                        'flex items-start gap-3 rounded-lg border px-3 py-3 text-sm',
                                        'border-gray-300 bg-gray-100 text-gray-500 cursor-not-allowed' => $isConsultationWritePermission,
                                        'border-gray-200 text-gray-700' => ! $isConsultationWritePermission,
                                    ])
                                    @if ($isConsultationWritePermission) aria-disabled="true" @endif
                                >
                                    <input
                                        type="checkbox"
                                        wire:model="selectedPermissionNames"
                                        value="{{ $permissionName }}"
                                        @disabled($selectedRole?->slug === 'super-admin' || $isConsultationWritePermission)
                                        class="mt-0.5 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                    >
                                    @if ($isConsultationWritePermission)
                                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm2 6V5a2 2 0 10-4 0v2h4zm-5 4a1 1 0 011-1h4a1 1 0 110 2h-1v2a1 1 0 11-2 0v-2H8a1 1 0 01-1-1z" clip-rule="evenodd" />
                                        </svg>
                                        <span class="flex min-w-0 flex-1 flex-col">
                                            <span>{{ $permissionName }}</span>
                                            <span class="text-xs text-gray-500">{{ __('Non autorisé — lecture seule') }}</span>
                                        </span>
                                    @else
                                        <span>{{ $permissionName }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>

            @if ($selectedRole?->slug === 'super-admin')
                <p class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('Le rôle Super Administrateur conserve son accès global et ne peut pas être modifié ici.') }}</p>
            @elseif ($selectedRole?->slug === 'consultation')
                <p class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('Le rôle Consultation est limité à la lecture seule. Les permissions de création, modification, suppression, validation et gestion sont désactivées.') }}</p>
            @endif
        </section>
    </div>
</div>
