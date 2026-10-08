<?php

namespace Tests\Feature;

use App\Services\Backup\BackupOperationLogger;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class BackupSecurityFinalizationTest extends TestCase
{
    public function test_scheduler_preserves_business_jobs_and_adds_daily_backup_without_restore(): void
    {
        $events = app(Schedule::class)->events();
        $backups = array_values(array_filter($events, fn ($event) => str_contains($event->command ?? '', 'backup:create')));
        $this->assertCount(1, $backups);
        $this->assertTrue($backups[0]->withoutOverlapping);
        $this->assertSame('0 2 * * *', $backups[0]->expression);
        $this->assertCount(2, array_filter($events, fn ($event) => $event instanceof CallbackEvent && in_array($event->expression, ['0 0 * * *', '0 * * * *'])));
        foreach ($events as $event) {
            $this->assertStringNotContainsString('backup:restore', $event->command ?? '');
        }
    }

    public function test_operation_log_has_required_fields_and_redacts_all_configured_secrets(): void
    {
        config(['database.connections.mysql.password' => 'DB_SENTINEL', 'app.key' => 'APP_SENTINEL', 'backup.encryption_key' => 'BACKUP_SENTINEL', 'mail.mailers.smtp.password' => 'MAIL_SENTINEL', 'reverb.apps.apps.0.secret' => 'REVERB_SENTINEL']);
        $logger = \Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('backup_operations')->once()->andReturn($logger);
        $logger->shouldReceive('info')->once()->with('Backup operation', \Mockery::on(function ($data) {
            foreach (['operation_id', 'backup_id', 'operation', 'started_at', 'finished_at', 'result', 'source', 'target', 'operator', 'key_id', 'warnings', 'error'] as $field) {
                $this->assertArrayHasKey($field, $data);
            }
            foreach (['DB_SENTINEL', 'APP_SENTINEL', 'BACKUP_SENTINEL', 'MAIL_SENTINEL', 'REVERB_SENTINEL'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($data));
            }

            return true;
        }));
        app(BackupOperationLogger::class)->record('failed', ['error' => 'DB_SENTINEL APP_SENTINEL BACKUP_SENTINEL MAIL_SENTINEL REVERB_SENTINEL']);
        $this->assertSame('daily', config('logging.channels.backup_operations.driver'));
        $this->assertStringContainsString('logs', config('logging.channels.backup_operations.path'));
    }

    public function test_storage_route_requires_signature_and_private_disks_are_not_served(): void
    {
        $this->assertFalse(config('filesystems.disks.attachments.serve'));
        $this->assertFalse(config('filesystems.disks.backups.serve'));
        $this->assertTrue(config('filesystems.disks.local.serve'));
        $this->assertSame('public', config('filesystems.disks.public.visibility'));
        $this->assertSame(storage_path('app/public'), config('filesystems.links.'.public_path('storage')));
        $this->get('/storage/attachments/example.pdf')->assertForbidden();
    }
}
