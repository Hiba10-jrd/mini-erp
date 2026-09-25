<?php

namespace App\Services;

use App\Enums\UserAccountStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function __construct(
        private readonly UserSessionInvalidator $sessionInvalidator,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     * @param  array<int, int|string>  $roleIds
     */
    public function createUser(array $attributes, array $roleIds): User
    {
        return DB::transaction(function () use ($attributes, $roleIds): User {
            $this->lockSuperAdministratorRole();
            $roleIds = $this->validatedRoleIds($roleIds);

            $user = new User([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
            ]);
            $user->forceFill([
                'must_change_password' => true,
                'account_status' => UserAccountStatus::Active,
            ])->save();

            $user->roles()->sync($roleIds);

            return $user->load('roles');
        });
    }

    /**
     * @param  array{name: string, email: string}  $attributes
     * @param  array<int, int|string>  $roleIds
     */
    public function updateUser(int $userId, array $attributes, array $roleIds): User
    {
        return DB::transaction(function () use ($userId, $attributes, $roleIds): User {
            $superAdministratorRole = $this->lockSuperAdministratorRole();
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $roleIds = $this->validatedRoleIds($roleIds);

            if ($user->isArchived()) {
                throw ValidationException::withMessages([
                    'accountStatus' => __('Un compte archivé ne peut plus être modifié.'),
                ]);
            }

            $currentlySuperAdministrator = $user->roles()
                ->whereKey($superAdministratorRole->getKey())
                ->exists();
            $willRemainSuperAdministrator = in_array(
                $superAdministratorRole->getKey(),
                $roleIds,
                true
            );

            if ($currentlySuperAdministrator && ! $willRemainSuperAdministrator) {
                $this->ensureAnotherSuperAdministratorExists($user);
            }

            $user->fill($attributes);

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $user->save();
            $user->roles()->sync($roleIds);

            return $user->load('roles');
        });
    }

    public function disableUser(int $userId): User
    {
        return $this->changeOrdinaryUserStatus(
            $userId,
            UserAccountStatus::Disabled,
            [UserAccountStatus::Active]
        );
    }

    public function reactivateUser(int $userId): User
    {
        return $this->changeOrdinaryUserStatus(
            $userId,
            UserAccountStatus::Active,
            [UserAccountStatus::Disabled]
        );
    }

    public function archiveUser(int $userId): User
    {
        return $this->changeOrdinaryUserStatus(
            $userId,
            UserAccountStatus::Archived,
            [UserAccountStatus::Active, UserAccountStatus::Disabled]
        );
    }

    /** @param list<UserAccountStatus> $allowedCurrentStatuses */
    private function changeOrdinaryUserStatus(
        int $userId,
        UserAccountStatus $newStatus,
        array $allowedCurrentStatuses
    ): User {
        return DB::transaction(function () use ($userId, $newStatus, $allowedCurrentStatuses): User {
            $this->lockSuperAdministratorRole();
            $user = User::query()->lockForUpdate()->findOrFail($userId);

            if ($user->isSuperAdministrator()) {
                throw ValidationException::withMessages([
                    'accountStatus' => __('Cette opération est interdite pour un Super Administrateur.'),
                ]);
            }

            if (! in_array($user->account_status, $allowedCurrentStatuses, true)) {
                throw ValidationException::withMessages([
                    'accountStatus' => __('Le statut actuel du compte ne permet pas cette opération.'),
                ]);
            }

            $user->forceFill([
                'account_status' => $newStatus,
                'remember_token' => Str::random(60),
            ])->save();

            if ($newStatus !== UserAccountStatus::Active) {
                $this->sessionInvalidator->invalidate($user);
            }

            return $user->load('roles');
        });
    }

    private function lockSuperAdministratorRole(): Role
    {
        return Role::query()
            ->where('slug', 'super-admin')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureAnotherSuperAdministratorExists(User $user): void
    {
        $anotherSuperAdministratorExists = User::query()
            ->where('users.id', '<>', $user->getKey())
            ->where('account_status', UserAccountStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->where('slug', 'super-admin'))
            ->exists();

        if (! $anotherSuperAdministratorExists) {
            throw ValidationException::withMessages([
                'selectedRoleIds' => __('Le rôle du dernier Super Administrateur actif ne peut pas être retiré.'),
            ]);
        }
    }

    /**
     * @param  array<int, int|string>  $roleIds
     * @return array<int, int>
     */
    private function validatedRoleIds(array $roleIds): array
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));

        if ($roleIds === []) {
            throw ValidationException::withMessages([
                'selectedRoleIds' => __('Au moins un rôle doit être attribué.'),
            ]);
        }

        $existingRoleIds = Role::query()
            ->whereKey($roleIds)
            ->pluck('id')
            ->map(fn ($roleId): int => (int) $roleId)
            ->all();

        sort($roleIds);
        sort($existingRoleIds);

        if ($roleIds !== $existingRoleIds) {
            throw ValidationException::withMessages([
                'selectedRoleIds' => __('Un ou plusieurs rôles sélectionnés sont invalides.'),
            ]);
        }

        return $roleIds;
    }
}
