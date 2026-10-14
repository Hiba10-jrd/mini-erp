<?php

namespace Tests\Feature;

use App\Enums\UserAccountStatus;
use App\Http\Middleware\ScopeRbacMemo;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\RolePermissionManagementService;
use App\Services\UserManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RequestRbacMemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_and_any_role_reads_are_memoized(): void
    {
        $user = $this->userWithRole('super-admin');
        $this->inRequest(function () use ($user) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->assertTrue($user->isSuperAdministrator());
            $this->assertTrue($user->hasAnyRole());
            for ($i = 0; $i < 5; $i++) {
                $this->assertTrue($user->isSuperAdministrator());
                $this->assertTrue(Gate::forUser($user)->allows('erp.access'));
            }
            $this->assertCount(2, DB::getQueryLog());
        });
    }

    public function test_permissions_and_users_have_separate_entries_including_denials(): void
    {
        $allowed = $this->ordinaryUser(['payments.view']);
        $denied = $this->ordinaryUser([]);
        $this->inRequest(function () use ($allowed, $denied) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->assertTrue($allowed->hasPermission('payments.view'));
            $this->assertFalse($allowed->hasPermission('reports.view'));
            $this->assertFalse($denied->hasPermission('payments.view'));
            $this->assertCount(5, DB::getQueryLog());
            for ($i = 0; $i < 3; $i++) {
                $this->assertTrue($allowed->hasPermission('payments.view'));
                $this->assertFalse($allowed->hasPermission('reports.view'));
                $this->assertFalse($denied->hasPermission('payments.view'));
            }
            $this->assertCount(5, DB::getQueryLog());
        });
    }

    public function test_unknown_permission_is_denied_even_for_super_admin_without_sql(): void
    {
        $user = $this->userWithRole('super-admin');
        $this->inRequest(function () use ($user) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->assertFalse($user->hasPermission('unknown.permission'));
            $this->assertFalse(Gate::forUser($user)->allows('unknown.permission'));
            $this->assertCount(0, DB::getQueryLog());
        });
    }

    public function test_gate_account_and_password_checks_are_not_memoized(): void
    {
        $user = $this->userWithRole('super-admin');
        $this->inRequest(function () use ($user) {
            $gate = Gate::forUser($user);
            $this->assertTrue($gate->allows('payments.view'));
            $user->account_status = UserAccountStatus::Disabled;
            $this->assertFalse($gate->allows('payments.view'));
            $this->assertFalse($gate->allows('users.administer'));
            $user->account_status = UserAccountStatus::Active;
            $user->must_change_password = true;
            $this->assertFalse($gate->allows('payments.view'));
            $this->assertFalse($gate->allows('roles.administer'));
        });
    }

    public function test_role_permission_and_super_admin_revocations_are_visible_on_next_http_request(): void
    {
        $user = $this->ordinaryUser(['payments.view']);
        Route::get('/_test/rbac', fn () => response()->json([
            'allowed' => Gate::forUser($user)->allows('payments.view'),
            'super' => $user->isSuperAdministrator(),
            'role' => $user->hasAnyRole(),
        ]));
        $this->get('/_test/rbac')->assertJson(['allowed' => true, 'super' => false, 'role' => true]);
        $user->roles()->firstOrFail()->permissions()->detach();
        $this->get('/_test/rbac')->assertJson(['allowed' => false]);
        $user->roles()->sync([Role::where('slug', 'super-admin')->firstOrFail()->id]);
        $this->get('/_test/rbac')->assertJson(['allowed' => true, 'super' => true]);
        $user->roles()->detach();
        $this->get('/_test/rbac')->assertJson(['allowed' => false, 'super' => false, 'role' => false]);
    }

    public function test_revoked_permission_is_denied_on_next_livewire_request(): void
    {
        $user = $this->ordinaryUser(['payments.view']);
        $this->actingAs($user);
        $component = Volt::test('admin.cash-manager')->assertSuccessful();
        $user->roles()->firstOrFail()->permissions()->detach();
        $component->call('$refresh')->assertForbidden();
    }

    public function test_revoked_role_is_denied_on_next_livewire_request(): void
    {
        $user = $this->userWithRole('super-admin');
        $this->actingAs($user);
        $component = Volt::test('admin.cash-manager')->assertSuccessful();
        $user->roles()->detach();
        $component->call('$refresh')->assertForbidden();
    }

    public function test_user_service_invalidates_only_the_changed_user(): void
    {
        $changed = $this->userWithRole('super-admin');
        $other = $this->userWithRole('super-admin');
        $ordinaryRole = Role::create(['name' => 'No permissions', 'slug' => 'no-permissions']);
        $this->inRequest(function () use ($changed, $other, $ordinaryRole) {
            $this->assertTrue($changed->hasPermission('payments.view'));
            $this->assertTrue($other->hasPermission('payments.view'));
            app(UserManagementService::class)->updateUser($changed->id, [
                'name' => $changed->name, 'email' => $changed->email,
            ], [$ordinaryRole->id]);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->assertTrue($other->hasPermission('payments.view'));
            $this->assertCount(0, DB::getQueryLog());
            $this->assertFalse($changed->isSuperAdministrator());
            $this->assertFalse($changed->hasPermission('payments.view'));
            $this->assertTrue($changed->hasAnyRole());
            app(UserManagementService::class)->updateUser($changed->id, [
                'name' => $changed->name, 'email' => $changed->email,
            ], [Role::where('slug', 'super-admin')->firstOrFail()->id]);
            $this->assertTrue($changed->isSuperAdministrator());
            $this->assertTrue($changed->hasPermission('payments.view'));
        });
    }

    public function test_permission_service_invalidates_only_affected_users_and_permissions(): void
    {
        $changed = $this->ordinaryUser(['payments.view', 'reports.view']);
        $other = $this->ordinaryUser(['payments.view']);
        $role = $changed->roles()->firstOrFail();
        $this->inRequest(function () use ($changed, $other, $role) {
            $this->assertTrue($changed->hasPermission('payments.view'));
            $this->assertTrue($changed->hasPermission('reports.view'));
            $this->assertTrue($other->hasPermission('payments.view'));
            app(RolePermissionManagementService::class)->syncPermissions($role->id, ['reports.view']);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->assertTrue($other->hasPermission('payments.view'));
            $this->assertTrue($changed->hasPermission('reports.view'));
            $this->assertFalse($changed->isSuperAdministrator());
            $this->assertCount(0, DB::getQueryLog());
            $this->assertFalse($changed->hasPermission('payments.view'));
            $this->assertCount(1, DB::getQueryLog());
            app(RolePermissionManagementService::class)->syncPermissions($role->id, ['payments.view', 'reports.view']);
            DB::flushQueryLog();
            $this->assertTrue($other->hasPermission('payments.view'));
            $this->assertTrue($changed->hasPermission('reports.view'));
            $this->assertCount(0, DB::getQueryLog());
            $this->assertTrue($changed->hasPermission('payments.view'));
            $this->assertCount(1, DB::getQueryLog());
        });
    }

    public function test_request_exit_clears_memo_even_after_exception_and_cli_reads_remain_fresh(): void
    {
        $user = $this->userWithRole('super-admin');
        try {
            $this->inRequest(function () use ($user) {
                $this->assertTrue($user->isSuperAdministrator());
                throw new \RuntimeException('test');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('test', $e->getMessage());
        }
        $user->roles()->detach();
        $this->assertFalse($user->isSuperAdministrator());
        $user->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());
        $this->assertTrue($user->isSuperAdministrator());
    }

    private function inRequest(\Closure $callback): void
    {
        app(ScopeRbacMemo::class)->handle(Request::create('/_test/rbac'), function () use ($callback) {
            $callback();

            return new Response;
        });
    }

    private function userWithRole(string $slug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', $slug)->firstOrFail());

        return $user;
    }

    private function ordinaryUser(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Test '.$user->id, 'slug' => 'test-'.$user->id]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }
}
