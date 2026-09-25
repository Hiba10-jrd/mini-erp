<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RolePermissionManagementService
{
    /**
     * @param  array<int, string>  $permissionNames
     */
    public function syncPermissions(int $roleId, array $permissionNames): Role
    {
        return DB::transaction(function () use ($roleId, $permissionNames): Role {
            $role = Role::query()->lockForUpdate()->find($roleId);

            if ($role === null) {
                throw ValidationException::withMessages([
                    'selectedRoleId' => __('Le rôle sélectionné n’existe pas.'),
                ]);
            }

            if ($role->slug === 'super-admin') {
                throw ValidationException::withMessages([
                    'selectedRoleId' => __('Le rôle Super Administrateur ne peut pas être modifié.'),
                ]);
            }

            $permissionNames = array_values(array_unique($permissionNames));
            $catalog = config('erp.permissions', []);
            $unknownPermissions = array_diff($permissionNames, $catalog);

            if ($unknownPermissions !== []) {
                throw ValidationException::withMessages([
                    'selectedPermissionNames' => __('Une ou plusieurs permissions ne figurent pas dans le catalogue ERP.'),
                ]);
            }

            if ($role->slug === 'consultation' && $this->containsWritePermission($permissionNames)) {
                throw ValidationException::withMessages([
                    'selectedPermissionNames' => __('Le rôle Consultation est limité aux permissions de lecture.'),
                ]);
            }

            $permissions = Permission::query()
                ->whereIn('name', $permissionNames)
                ->lockForUpdate()
                ->get();

            if ($permissions->count() !== count($permissionNames)) {
                throw ValidationException::withMessages([
                    'selectedPermissionNames' => __('Une ou plusieurs permissions ne sont pas disponibles.'),
                ]);
            }

            $role->permissions()->sync($permissions->modelKeys());

            return $role->load('permissions');
        });
    }

    /** @param  array<int, string>  $permissionNames */
    private function containsWritePermission(array $permissionNames): bool
    {
        return collect($permissionNames)->contains(function (string $permission): bool {
            return in_array(Str::after($permission, '.'), [
                'create',
                'update',
                'delete',
                'validate',
                'manage',
            ], true);
        });
    }
}
