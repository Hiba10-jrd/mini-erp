<?php

namespace Tests\Feature;

use App\Jobs\BroadcastStoredNotification;
use App\Jobs\CheckCustomerInvoiceDeadlines;
use App\Jobs\CheckStockAlerts;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\CustomerReminderManagementService;
use App\Services\InternalNotificationDispatcher;
use App\Services\NotificationRecipientResolver;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Support\CustomerReminderFixtures;
use Tests\TestCase;

class InternalNotificationTest extends TestCase
{
    use CustomerReminderFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 0));
        $this->seed(RbacSeeder::class);
        Bus::fake([BroadcastStoredNotification::class]);
    }

    private function payload(string $type = 'invoice.overdue'): array
    {
        return ['type' => $type, 'title' => 'Alerte', 'message' => 'Notification test', 'url' => null, 'entity_type' => 'invoice', 'entity_id' => null, 'severity' => 'warning'];
    }

    public function test_recipients_are_active_authorized_users_and_super_admin(): void
    {
        $finance = $this->userWithPermissions(['payments.view']);
        $stock = $this->userWithPermissions(['stock.manage']);
        $inactive = $this->userWithPermissions(['payments.view']);
        $inactive->forceFill(['account_status' => 'disabled'])->save();
        $locked = $this->userWithPermissions(['payments.view']);
        $locked->forceFill(['must_change_password' => true])->save();
        $other = $this->userWithPermissions(['invoices.view']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('slug', 'super-admin')->first());
        $resolver = app(NotificationRecipientResolver::class);
        $this->assertEqualsCanonicalizing([$finance->id, $admin->id], $resolver->query('finance')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$stock->id, $admin->id], $resolver->query('stock')->pluck('id')->all());
        $this->assertFalse($resolver->allowed($other, 'invoice.overdue'));
    }

    public function test_database_persistence_dedup_and_broadcast_after_commit(): void
    {
        $user = $this->userWithPermissions(['payments.view']);
        $dispatcher = app(InternalNotificationDispatcher::class);
        DB::transaction(function () use ($dispatcher, $user, &$id): void {
            $id = $dispatcher->send($user, $this->payload(), 'invoice:1');
            $this->assertDatabaseHas('notifications', ['id' => $id]);
            Bus::assertNotDispatched(BroadcastStoredNotification::class);
        });
        Bus::assertDispatched(BroadcastStoredNotification::class, fn ($job) => $job->notificationId === $id);
        $this->assertNull($dispatcher->send($user, $this->payload(), 'invoice:1'));
        $this->assertSame(1, $user->notifications()->count());
        $stored = $user->notifications()->first();
        $this->assertSame(hash('sha256', 'invoice:1'), $stored->dedup_key);
        $this->assertSame($id, $stored->data['notification_id']);
        $this->assertArrayNotHasKey('_dedup_key', $stored->data);
    }

    public function test_broadcast_retries_only_existing_notification_and_private_payload(): void
    {
        $user = $this->userWithPermissions(['payments.view']);
        $id = app(InternalNotificationDispatcher::class)->send($user, $this->payload(), 'retry');
        Event::fake([BroadcastNotificationCreated::class]);
        $job = new BroadcastStoredNotification($id);
        $job->handle(app(NotificationRecipientResolver::class));
        $job->handle(app(NotificationRecipientResolver::class));
        $this->assertDatabaseCount('notifications', 1);
        Event::assertDispatched(BroadcastNotificationCreated::class, function ($event) use ($id, $user): bool {
            return $event->broadcastWith()['notification_id'] === $id
                && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$user->id
                && $event->connection === 'sync';
        });
    }

    public function test_dispatch_failure_preserves_database_notification(): void
    {
        $user = $this->userWithPermissions(['payments.view']);
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('Queue unavailable'));
        $id = app(InternalNotificationDispatcher::class)->send($user, $this->payload(), 'failure');
        $this->assertDatabaseHas('notifications', ['id' => $id, 'read_at' => null]);
    }

    public function test_broadcast_failure_keeps_same_database_row_for_retry(): void
    {
        $user = $this->userWithPermissions(['payments.view']);
        $id = app(InternalNotificationDispatcher::class)->send($user, $this->payload(), 'broadcast-failure');
        Notification::shouldReceive('sendNow')->twice()->andThrow(new \RuntimeException('Reverb unavailable'));
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                (new BroadcastStoredNotification($id))->handle(app(NotificationRecipientResolver::class));
                $this->fail();
            } catch (\RuntimeException) {
                $this->assertDatabaseCount('notifications', 1);
            }
        }
        $this->assertDatabaseHas('notifications', ['id' => $id]);
    }

    public function test_revoked_permission_prevents_broadcast(): void
    {
        $user = $this->userWithPermissions(['payments.view']);
        $id = app(InternalNotificationDispatcher::class)->send($user, $this->payload(), 'revoked');
        $user->roles()->detach();
        Event::fake([BroadcastNotificationCreated::class]);
        (new BroadcastStoredNotification($id))->handle(app(NotificationRecipientResolver::class));
        Event::assertNotDispatched(BroadcastNotificationCreated::class);
        $this->assertDatabaseHas('notifications', ['id' => $id]);
    }

    public function test_rollback_never_broadcasts_or_persists_an_uncommitted_notification(): void
    {
        $user = $this->userWithPermissions(['payments.view']);
        try {
            DB::transaction(function () use ($user): void {
                app(InternalNotificationDispatcher::class)->send($user, $this->payload(), 'rollback');
                throw new \RuntimeException;
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('notifications', 0);
        Bus::assertNotDispatched(BroadcastStoredNotification::class);
    }

    public function test_notification_ui_is_personal_and_marks_only_own_rows(): void
    {
        $user = $this->userWithPermissions(['payments.view']);
        $other = $this->userWithPermissions(['payments.view']);
        $dispatcher = app(InternalNotificationDispatcher::class);
        $id = $dispatcher->send($user, $this->payload(), 'one');
        $otherId = $dispatcher->send($other, $this->payload(), 'other');
        $this->actingAs($user);
        $this->get(route('notifications.index'))->assertOk();
        Volt::test('notification-bell')->assertViewHas('unread', 1)->call('markRead', $id)->assertViewHas('unread', 0);
        Volt::test('notifications-manager')->set('filter', 'read')->assertViewHas('notifications', fn ($rows) => $rows->total() === 1);
        try {
            Volt::test('notifications-manager')->call('markRead', $otherId);
            $this->fail('Another user notification must not be accessible.');
        } catch (ModelNotFoundException) {
        }
        $dispatcher->send($user, $this->payload(), 'two');
        Volt::test('notifications-manager')->call('markAllRead');
        $this->assertSame(0, $user->notifications()->whereNull('read_at')->count());
        $this->assertNull($other->notifications()->first()->read_at);
    }

    public function test_private_channel_rejects_other_user(): void
    {
        $user = $this->userWithPermissions(['payments.view']);
        $this->actingAs($user);
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test', 'broadcasting.connections.reverb.secret' => 'test', 'broadcasting.connections.reverb.app_id' => 'test']);
        require base_path('routes/channels.php');
        $this->post('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.User.'.$user->id])->assertOk();
        $this->post('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.User.'.($user->id + 99)])->assertForbidden();
    }

    public function test_deadlines_reuse_balances_and_deduplicate(): void
    {
        $operator = $this->userWithPermissions(['payments.view', 'payments.create', 'invoices.create', 'invoices.validate', 'sales.create', 'sales.update']);
        $this->actingAs($operator);
        $overdue = $this->invoice(true, '2026-10-04');
        $soon = $this->invoice(true, '2026-10-12');
        $today = $this->invoice(true, '2026-10-05');
        $paid = $this->invoice(true, '2026-10-01');
        $this->pay($paid, '120.00');
        $this->invoice(true, null);
        $this->invoice(true, '2026-10-13');
        $this->invoice(false, '2026-10-01');
        app()->call([new CheckCustomerInvoiceDeadlines, 'handle']);
        app()->call([new CheckCustomerInvoiceDeadlines, 'handle']);
        $this->assertDatabaseCount('notifications', 3);
        $rows = $operator->notifications()->get()->keyBy(fn ($n) => $n->data['entity_id']);
        $this->assertSame('invoice.overdue', $rows[$overdue->id]->data['type']);
        $this->assertSame('invoice.due-soon', $rows[$soon->id]->data['type']);
        $this->assertSame('invoice.due-soon', $rows[$today->id]->data['type']);
    }

    public function test_stock_alerts_scope_and_daily_dedup(): void
    {
        $user = $this->userWithPermissions(['stock.view']);
        $warehouse = Warehouse::create(['code' => 'ACTIVE', 'name' => 'Actif', 'is_active' => true]);
        Warehouse::forceCreate(['code' => 'OFF', 'name' => 'Inactif', 'is_active' => false]);
        $unit = Unit::create(['symbol' => 'pce', 'name' => 'Pièce']);
        $low = Product::forceCreate(['unit_id' => $unit->id, 'type' => 'product', 'reference' => 'LOW', 'name' => 'Faible', 'is_active' => true, 'minimum_stock' => 5]);
        Product::forceCreate(['unit_id' => $unit->id, 'type' => 'product', 'reference' => 'OUT', 'name' => 'Rupture', 'is_active' => true]);
        Product::forceCreate(['unit_id' => $unit->id, 'type' => 'service', 'reference' => 'SVC', 'name' => 'Service', 'is_active' => true]);
        Product::forceCreate(['unit_id' => $unit->id, 'type' => 'product', 'reference' => 'OFF', 'name' => 'Inactif', 'is_active' => false]);
        WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $low->id, 'quantity' => 5]);
        app()->call([new CheckStockAlerts, 'handle']);
        app()->call([new CheckStockAlerts, 'handle']);
        $this->assertSame(2, $user->notifications()->count());
        $this->assertEqualsCanonicalizing(['stock.low', 'stock.out'], $user->notifications()->get()->map(fn ($n) => $n->data['type'])->all());
        $this->travel(1)->days();
        app()->call([new CheckStockAlerts, 'handle']);
        $this->assertSame(4, $user->notifications()->count());
    }

    public function test_reminder_notification_after_commit(): void
    {
        $user = $this->userWithPermissions(['payments.view', 'payments.create', 'invoices.create', 'invoices.validate', 'sales.create', 'sales.update']);
        $this->actingAs($user);
        $invoice = $this->invoice();
        $reminder = app(CustomerReminderManagementService::class)->create($invoice, ['reminder_date' => today()->toDateString(), 'channel' => 'manual', 'note' => 'Private note']);
        $this->assertSame(1, $user->notifications()->count());
        $stored = $user->notifications()->first();
        $this->assertSame('reminder.created', $stored->data['type']);
        $this->assertSame(hash('sha256', 'reminder:'.$reminder->id), $stored->dedup_key);
        $this->assertStringNotContainsString('Private note', json_encode($stored->data));
    }
}
