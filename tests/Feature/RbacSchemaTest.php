<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_be_assigned_a_role(): void
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => 'Commercial',
            'slug' => 'commercial',
        ]);

        $user->roles()->attach($role);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $this->assertTrue(
            $user->roles()->whereKey($role->id)->exists()
        );
    }

    public function test_a_role_can_have_permissions(): void
    {
        $role = Role::create([
            'name' => 'Commercial',
            'slug' => 'commercial',
        ]);

        $permission = Permission::create([
            'name' => 'customers.view',
        ]);

        $role->permissions()->attach($permission);

        $this->assertDatabaseHas('permission_role', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);

        $this->assertTrue(
            $role->permissions()->whereKey($permission->id)->exists()
        );
    }

    public function test_a_user_can_have_multiple_roles(): void
    {
        $user = User::factory()->create();

        $commercial = Role::create([
            'name' => 'Commercial',
            'slug' => 'commercial',
        ]);

        $consultation = Role::create([
            'name' => 'Consultation',
            'slug' => 'consultation',
        ]);

        $user->roles()->attach([
            $commercial->id,
            $consultation->id,
        ]);

        $this->assertCount(2, $user->roles);
    }
}
