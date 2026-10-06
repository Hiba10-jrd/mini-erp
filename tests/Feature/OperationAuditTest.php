<?php

namespace Tests\Feature;

use App\Models\OperationHistory;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\RolePermissionManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\Support\CustomerReminderFixtures;
use Tests\TestCase;

class OperationAuditTest extends TestCase
{
    use CustomerReminderFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_critical_update_records_actor_subject_and_before_after(): void
    {
        $actor = $this->userWithPermissions([]);
        $this->actingAs($actor);
        $target = User::factory()->create(['name' => 'Avant']);
        $target->update(['name' => 'Après']);
        $log = OperationHistory::where('subject_type', 'user')->where('subject_id', $target->id)->where('action', 'updated')->firstOrFail();
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame('Avant', $log->old_values['name']);
        $this->assertSame('Après', $log->new_values['name']);
        $this->assertArrayNotHasKey('password', $log->new_values);
    }

    public function test_rollback_removes_both_business_change_and_audit(): void
    {
        $user = User::factory()->create(['name' => 'Initial']);
        $before = OperationHistory::count();
        try {
            DB::transaction(function () use ($user) {
                $user->update(['name' => 'Rollback']);
                throw new \RuntimeException;
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame('Initial', $user->fresh()->name);
        $this->assertSame($before, OperationHistory::count());
    }

    public function test_recursive_sanitization_including_serialized_json(): void
    {
        $clean = app(AuditTrailService::class)->sanitize(['name' => 'Safe', 'password' => 'bad', 'remember_token' => 'bad', 'details' => json_encode(['token' => 'bad', 'two_factor_secret' => 'bad', 'session' => 'bad', 'content' => 'bad', 'nested' => ['api_key' => 'bad', 'name' => 'Safe']])]);
        $this->assertSame(['name' => 'Safe', 'details' => ['nested' => ['name' => 'Safe']]], $clean);
    }

    public function test_history_is_immutable(): void
    {
        $user = User::factory()->create();
        $log = OperationHistory::firstOrFail();
        try {
            $log->update(['action' => 'tampered']);
            $this->fail();
        } catch (\LogicException) {
        }
        try {
            $log->delete();
            $this->fail();
        } catch (\LogicException) {
        }
        $this->assertDatabaseHas('operation_histories', ['id' => $log->id, 'action' => 'created']);
    }

    public function test_super_admin_audit_filters_pagination_and_existing_sources(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('slug', 'super-admin')->first());
        $this->actingAs($admin);
        $this->get(route('admin.audit.index'))->assertOk();
        $service = app(AuditTrailService::class);
        for ($i = 0; $i < 25; $i++) {
            $service->record($admin, 'test.action', [], ['index' => $i]);
        }
        Volt::test('admin.audit-manager')->set('entity', 'user')->set('action', 'test.action')->set('user', (string) $admin->id)
            ->set('from', today()->toDateString())->set('to', today()->toDateString())
            ->assertViewHas('entries', fn ($rows) => $rows->total() === 25 && $rows->count() === 20);
        foreach (['sales-orders', 'purchase-orders', 'goods-receipts', 'supplier-invoices', 'stock', 'reminders'] as $source) {
            Volt::test('admin.audit-manager')->set('source', $source)->assertOk();
        }
        $this->actingAs($this->userWithPermissions(['reports.view']));
        $this->get(route('admin.audit.index'))->assertForbidden();
    }

    public function test_role_permission_changes_are_audited(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $role = Role::where('slug', 'commercial')->firstOrFail();
        app(RolePermissionManagementService::class)->syncPermissions($role->id, ['customers.view']);
        $log = OperationHistory::where('action', 'permissions.changed')->firstOrFail();
        $this->assertSame($role->id, $log->subject_id);
        $this->assertSame([], $log->old_values['permissions']);
        $this->assertSame(['customers.view'], $log->new_values['permissions']);
    }

    public function test_existing_business_histories_are_not_replicated(): void
    {
        $this->actingAs($this->userWithPermissions(['sales.create', 'sales.update', 'invoices.create', 'invoices.validate']));
        $invoice = $this->invoice();
        $this->assertDatabaseMissing('operation_histories', ['subject_type' => 'sales-order']);
        $this->assertDatabaseMissing('operation_histories', ['subject_type' => 'stock-movement']);
        $this->assertDatabaseCount('sales_order_histories', 4);
    }
}
