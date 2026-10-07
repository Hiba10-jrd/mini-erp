<?php

namespace Tests\Feature;

use App\Services\Backup\BackupManager;
use Tests\TestCase;

class BackupConfigurationTest extends TestCase
{
    public function test_disabled_backups_are_rejected(): void
    {
        config(['backup.enabled' => false]);
        $this->artisan('backup:create')->expectsOutputToContain('désactivées')->assertFailed();
    }

    public function test_invalid_configuration_is_rejected(): void
    {
        foreach (['../escape', '/absolute', 'a/../../b', 'C:\\temp'] as $path) {
            config(['backup.enabled' => true, 'backup.path' => $path]);
            $this->artisan('backup:create')->expectsOutputToContain('Configuration')->assertFailed();
        }
    }

    public function test_public_or_served_disks_are_rejected(): void
    {
        config(['backup.enabled' => true, 'backup.disk' => 'public']);
        $this->expectException(\RuntimeException::class);
        app(BackupManager::class)->validateConfiguration();
    }

    public function test_default_disk_is_private_and_isolated(): void
    {
        config(['backup.enabled' => true, 'backup.disk' => 'backups', 'backup.path' => 'mini-erp']);
        $this->assertSame(str_replace('\\', '/', storage_path('backups')).'/mini-erp', app(BackupManager::class)->validateConfiguration());
        $this->assertFalse(config('filesystems.disks.backups.serve'));
        $this->assertTrue(config('filesystems.disks.backups.throw'));
    }
}
