<?php

namespace Tests\Feature;

use App\Services\Backup\BackupArchiveService;
use App\Services\Backup\BackupEncryptionService;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupRetentionService;
use App\Services\Backup\BackupVerificationService;
use App\Services\Backup\BackupWorkspace;
use Tests\Support\BackupFixtures;
use Tests\TestCase;

class BackupRetentionTest extends TestCase
{
    use BackupFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBackupFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupBackupFixtures();
        parent::tearDown();
    }

    private function inventory(array $dates): array
    {
        $root = app(BackupManager::class)->validateConfiguration(false);
        app(BackupWorkspace::class)->directory($root);
        $files = [];
        foreach ($dates as $index => $date) {
            $files[$root.'/fixture-'.$index.'.erpbackup'] = ['created_at' => $date];
            file_put_contents(array_key_last($files), 'verified fixture');
        }
        $verification = \Mockery::mock(BackupVerificationService::class);
        $verification->shouldReceive('verify')->andReturnUsing(fn ($path) => $files[$path] ?? throw new BackupException('Invalid'));
        $this->app->instance(BackupVerificationService::class, $verification);

        return [$root, array_keys($files)];
    }

    public function test_daily_weekly_monthly_union_keeps_latest_per_calendar_bucket(): void
    {
        config(['backup.retention' => ['daily' => 2, 'weekly' => 2, 'monthly' => 2]]);
        [$root, $files] = $this->inventory(['2026-10-07T12:00:00Z', '2026-10-07T10:00:00Z', '2026-10-06T12:00:00Z', '2026-09-30T12:00:00Z', '2026-09-01T12:00:00Z', '2026-08-01T12:00:00Z']);
        $result = app(BackupRetentionService::class)->afterSuccessfulBackup($files[0], $root);
        $this->assertEqualsCanonicalizing([$files[1], $files[4], $files[5]], $result['deleted']);
        foreach ([$files[0], $files[2], $files[3]] as $file) {
            $this->assertFileExists($file);
        }
    }

    public function test_monthly_tier_keeps_an_archive_outside_daily_and_weekly_buckets(): void
    {
        config(['backup.retention' => ['daily' => 1, 'weekly' => 1, 'monthly' => 3]]);
        [$root, $files] = $this->inventory(['2026-10-07T12:00:00Z', '2026-09-30T12:00:00Z', '2026-08-15T12:00:00Z', '2026-08-01T12:00:00Z']);
        $result = app(BackupRetentionService::class)->afterSuccessfulBackup($files[0], $root);
        $this->assertSame([$files[3]], $result['deleted']);
        $this->assertSame('monthly', $result['kept'][$files[2]]);
    }

    public function test_real_encrypted_archives_are_authenticated_before_deletion(): void
    {
        config(['backup.retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0]]);
        $root = app(BackupManager::class)->validateConfiguration(false);
        app(BackupWorkspace::class)->directory($root);
        $paths = [];
        foreach (['2026-09-01T12:00:00Z', '2026-10-07T12:00:00Z'] as $date) {
            [$work, $manifest] = $this->createPayloadFixture();
            $manifest['created_at'] = $date;
            file_put_contents($work.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $zip = app(BackupArchiveService::class)->create($work, $manifest);
            $path = $root.'/'.$manifest['backup_id'].'.erpbackup';
            app(BackupEncryptionService::class)->encrypt($zip, $path, $manifest);
            $paths[] = $path;
        }
        $result = app(BackupRetentionService::class)->afterSuccessfulBackup($paths[1], $root);
        $this->assertSame([$paths[0]], $result['deleted']);
        $this->assertFileDoesNotExist($paths[0]);
        $this->assertFileExists($paths[1]);
    }

    public function test_last_valid_protected_and_partial_archives_are_kept_even_with_zero_retention(): void
    {
        config(['backup.retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0]]);
        [$root, $files] = $this->inventory(['2026-10-07T12:00:00Z', '2026-09-01T12:00:00Z']);
        file_put_contents($files[1].'.protected', '');
        file_put_contents($root.'/pending.erpbackup.part', 'partial');
        file_put_contents($root.'/corrupted.erpbackup', 'invalid');
        $result = app(BackupRetentionService::class)->afterSuccessfulBackup($files[0], $root);
        $this->assertSame([], $result['deleted']);
        $this->assertSame('protégée', $result['kept'][$files[1]]);
        $this->assertFileExists($root.'/pending.erpbackup.part');
        $this->assertFileExists($root.'/corrupted.erpbackup');
    }

    public function test_restore_lock_prevents_all_deletions(): void
    {
        config(['backup.retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0]]);
        [$root, $files] = $this->inventory(['2026-10-07T12:00:00Z', '2026-09-01T12:00:00Z']);
        $lock = fopen($this->backupRoot.'/backups/.restore.lock', 'c');
        flock($lock, LOCK_EX | LOCK_NB);
        try {
            $this->assertSame([], app(BackupRetentionService::class)->afterSuccessfulBackup($files[0], $root)['deleted']);
            $this->assertFileExists($files[1]);
        } finally {
            fclose($lock);
        }
    }

    public function test_invalid_current_backup_cannot_authorize_prune(): void
    {
        [$root, $files] = $this->inventory(['2026-10-07T12:00:00Z']);
        $current = $root.'/invalid.erpbackup';
        file_put_contents($current, 'invalid');
        try {
            app(BackupRetentionService::class)->afterSuccessfulBackup($current, $root);
            $this->fail('Invalid current backup accepted');
        } catch (BackupException) {
            $this->assertFileExists($files[0]);
        }
    }

    public function test_failed_create_never_invokes_retention(): void
    {
        config(['backup.encryption_key' => null]);
        $retention = \Mockery::mock(BackupRetentionService::class);
        $retention->shouldNotReceive('afterSuccessfulBackup');
        $this->app->instance(BackupRetentionService::class, $retention);
        $this->artisan('backup:create')->assertFailed();
    }

    public function test_successful_create_calls_retention_with_published_verified_archive(): void
    {
        $retention = \Mockery::mock(BackupRetentionService::class);
        $retention->shouldReceive('afterSuccessfulBackup')->once()->andReturnUsing(function ($path, $root) {
            $this->assertFileExists($path);
            $this->assertSame(dirname($path), $root);
            $this->assertNotEmpty(app(BackupVerificationService::class)->verify($path, $root)['backup_id']);

            return ['kept' => [], 'deleted' => []];
        });
        $this->app->instance(BackupRetentionService::class, $retention);
        $this->artisan('backup:create')->assertSuccessful();
    }

    public function test_retention_failure_does_not_remove_successful_backup(): void
    {
        $retention = \Mockery::mock(BackupRetentionService::class);
        $retention->shouldReceive('afterSuccessfulBackup')->andThrow(new BackupException('Retention failed'));
        $this->app->instance(BackupRetentionService::class, $retention);
        $this->artisan('backup:create')->assertSuccessful();
        $this->assertCount(1, glob($this->backupRoot.'/backups/mini-erp/*.erpbackup'));
    }
}
