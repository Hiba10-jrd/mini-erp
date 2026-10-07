<?php

namespace Tests\Support;

use App\Services\Backup\BackupEncryptionService;
use App\Services\Backup\BackupManifestService;
use App\Services\Backup\BackupWorkspace;
use App\Services\Backup\DatabaseBackupService;
use ZipArchive;

trait BackupFixtures
{
    protected string $backupRoot;

    protected function initializeBackupFixtures(): void
    {
        $workspace = new BackupWorkspace;
        $this->backupRoot = $workspace->create(storage_path('framework/testing'));
        $this->app->useStoragePath($this->backupRoot.'/storage');
        $workspace->directory($this->backupRoot.'/backups');
        config([
            'backup.enabled' => true,
            'backup.disk' => 'backups',
            'backup.path' => 'mini-erp',
            'backup.key_id' => 'test-v1',
            'backup.encryption_key' => 'base64:'.base64_encode(random_bytes(32)),
            'filesystems.disks.backups.root' => $this->backupRoot.'/backups',
        ]);
        $this->app->instance(DatabaseBackupService::class, new class extends DatabaseBackupService
        {
            public function dump(string $workspace): string
            {
                file_put_contents($workspace.'/database.sql', 'dump fixture');

                return 'mysql';
            }

            public function metadata(string $driver): array
            {
                return ['name' => 'fixture_db', 'version' => '8.4.fixture', 'migrations' => ['available' => ['2026_01_01_fixture'], 'applied' => ['2026_01_01_fixture']]];
            }
        });
        foreach (['private', 'public'] as $type) {
            $workspace->directory(storage_path('app/'.$type));
            file_put_contents(storage_path('app/'.$type.'/receipt.txt'), $type.' fixture');
        }
    }

    protected function cleanupBackupFixtures(): void
    {
        (new BackupWorkspace)->cleanup($this->backupRoot);
    }

    protected function createPayloadFixture(): array
    {
        $workspace = new BackupWorkspace;
        $path = $workspace->create($this->backupRoot.'/fixtures');
        $workspace->directory($path.'/database');
        file_put_contents($path.'/database/database.sql', 'dump fixture');
        foreach (['private', 'public'] as $type) {
            $workspace->collect(storage_path('app/'.$type), $path.'/storage/'.$type);
        }
        $manifest = app(BackupManifestService::class)->write($path, 'mysql');

        return [$path, $manifest];
    }

    protected function createEncryptedFixture(?callable $mutateManifest = null, ?callable $mutateZip = null): array
    {
        [$path, $manifest] = $this->createPayloadFixture();
        $stored = $mutateManifest ? $mutateManifest($manifest) : $manifest;
        file_put_contents($path.'/manifest.json', json_encode($stored, JSON_THROW_ON_ERROR));
        $zipPath = $path.'/payload.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL));
        foreach (array_merge(['manifest.json'], array_keys($manifest['files'])) as $name) {
            $this->assertTrue($zip->addFile($path.'/'.$name, $name));
        }
        if ($mutateZip) {
            $mutateZip($zip);
        }
        $this->assertTrue($zip->close());
        $encrypted = $this->backupRoot.'/fixture-'.bin2hex(random_bytes(8)).'.erpbackup';
        app(BackupEncryptionService::class)->encrypt($zipPath, $encrypted, $manifest);

        return [$encrypted, $manifest];
    }
}
