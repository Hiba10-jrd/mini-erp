<?php

namespace Tests\Support;

use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupWorkspace;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

trait RestoreFixtures
{
    use BackupFixtures;

    protected function initializeRestoreFixtures(): void
    {
        $this->initializeBackupFixtures();
    }

    protected function createRestoreArchive(?callable $mutateManifest = null, ?callable $mutateZip = null): array
    {
        [$path, $manifest] = $this->createEncryptedFixture($mutateManifest, $mutateZip);
        $root = app(BackupManager::class)->validateConfiguration(false);
        (new BackupWorkspace)->directory($root);
        $destination = $root.'/'.basename($path);
        rename($path, $destination);

        return [$destination, $manifest];
    }

    protected function createRealSqliteSource(): void
    {
        $source = $this->backupRoot.'/source.sqlite';
        touch($source);
        config(['database.default' => 'restore_source', 'database.connections.restore_source' => ['driver' => 'sqlite', 'database' => $source, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('restore_source');
        $this->app->instance(DatabaseBackupService::class, new DatabaseBackupService);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'restore_source', '--force' => true]));
    }

    protected function targetDatabase(): string
    {
        return $this->backupRoot.'/isolated/db.sqlite';
    }

    protected function targetStorage(): string
    {
        return $this->backupRoot.'/isolated/files';
    }

    protected function restoreOptions(string $id): array
    {
        return ['--target-database' => $this->targetDatabase(), '--target-storage' => $this->targetStorage(), '--confirm' => $id, '--no-interaction' => true];
    }

    protected function cleanupRestoreFixtures(): void
    {
        DB::disconnect('restore_source');
        $this->cleanupBackupFixtures();
    }
}
