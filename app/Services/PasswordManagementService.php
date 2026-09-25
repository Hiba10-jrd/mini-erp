<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordManagementService
{
    public function __construct(
        private readonly UserSessionInvalidator $sessionInvalidator,
    ) {}

    public function changeOwnPassword(
        int $userId,
        string $currentPassword,
        string $newPassword,
        ?string $currentSessionId = null
    ): User {
        return DB::transaction(function () use ($userId, $currentPassword, $newPassword, $currentSessionId): User {
            $user = User::query()->lockForUpdate()->findOrFail($userId);

            if (! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => __('Le mot de passe actuel est incorrect.'),
                ]);
            }

            $this->ensurePasswordIsDifferent($user, $newPassword, 'password');

            $this->storePassword($user, $newPassword, false);
            $this->sessionInvalidator->invalidate($user, $currentSessionId);

            return $user;
        });
    }

    public function resetByAdministrator(int $userId, string $temporaryPassword): User
    {
        return DB::transaction(function () use ($userId, $temporaryPassword): User {
            Role::query()
                ->where('slug', 'super-admin')
                ->lockForUpdate()
                ->firstOrFail();

            $user = User::query()->lockForUpdate()->findOrFail($userId);

            if ($user->isSuperAdministrator()) {
                throw ValidationException::withMessages([
                    'temporaryPassword' => __('Le mot de passe d’un Super Administrateur ne peut pas être réinitialisé ici.'),
                ]);
            }

            $this->ensurePasswordIsDifferent($user, $temporaryPassword, 'temporaryPassword');
            $this->storePassword($user, $temporaryPassword, true);
            $this->sessionInvalidator->invalidate($user);

            return $user;
        });
    }

    public function completeBrokerReset(User $user, string $newPassword): User
    {
        return DB::transaction(function () use ($user, $newPassword): User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());

            $this->ensurePasswordIsDifferent($lockedUser, $newPassword, 'password');
            $this->storePassword($lockedUser, $newPassword, false);
            $this->sessionInvalidator->invalidate($lockedUser);

            return $lockedUser;
        });
    }

    private function storePassword(User $user, string $password, bool $mustChange): void
    {
        $user->forceFill([
            'password' => $password,
            'must_change_password' => $mustChange,
            'remember_token' => Str::random(60),
        ])->save();
    }

    private function ensurePasswordIsDifferent(User $user, string $newPassword, string $errorKey): void
    {
        if (Hash::check($newPassword, $user->password)) {
            throw ValidationException::withMessages([
                $errorKey => __('Le nouveau mot de passe doit être différent du mot de passe actuel.'),
            ]);
        }
    }
}
