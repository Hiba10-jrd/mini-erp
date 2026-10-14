<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/** Memoizes database facts only while an HTTP request is being handled. */
class RequestRbacMemo
{
    private ?Request $request = null;

    private array $entries = [];

    public function begin(Request $request): void
    {
        $this->entries = [];
        $this->request = $request;
    }

    public function end(): void
    {
        $this->entries = [];
        $this->request = null;
    }

    public function remember(User $user, string $check, string $subject, Closure $read): bool
    {
        if ($this->request === null || ! $user->exists || $user->getKey() === null) {
            return $read();
        }

        // RBAC facts do not depend on the authentication guard. Model, connection,
        // database and user identity distinguish the underlying records.
        $identity = $this->identity($user);
        $key = json_encode([$check, $subject], JSON_THROW_ON_ERROR);

        return $this->entries[$identity]['checks'][$key] ??= $read();
    }

    public function forgetUser(User $user): void
    {
        unset($this->entries[$this->identity($user)]);
    }

    /** Invalidate only changed permissions for memoized users assigned this role. */
    public function forgetRolePermissions(Role $role, array $permissions): void
    {
        if ($this->request === null || $permissions === []) {
            return;
        }

        $connection = $role->getConnection();
        $identities = [];
        foreach (array_keys($this->entries) as $identity) {
            [$model, $name, $database, $id] = json_decode($identity, true, flags: JSON_THROW_ON_ERROR);
            if ($model === User::class && $name === $connection->getName() && $database === $connection->getDatabaseName()) {
                $identities[(string) $id][] = $identity;
            }
        }
        if ($identities === []) {
            return;
        }

        $userIds = $connection->table('role_user')->where('role_id', $role->getKey())
            ->whereIn('user_id', array_keys($identities))->pluck('user_id');
        foreach ($userIds as $id) {
            foreach ($identities[(string) $id] as $identity) {
                foreach ($permissions as $permission) {
                    unset($this->entries[$identity]['checks'][json_encode(['permission', $permission], JSON_THROW_ON_ERROR)]);
                }
            }
        }
    }

    private function identity(User $user): string
    {
        $connection = $user->getConnection();

        return json_encode([
            $user::class, $connection->getName(), $connection->getDatabaseName(), (string) $user->getKey(),
        ], JSON_THROW_ON_ERROR);
    }
}
