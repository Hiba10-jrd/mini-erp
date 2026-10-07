<?php

namespace Tests\Feature;

use App\Services\Backup\BackupWorkspace;
use Tests\TestCase;

class BackupWorkspaceTest extends TestCase
{
    public function test_workspace_creation_collection_and_cleanup(): void
    {
        $service = new BackupWorkspace;
        $path = $service->create(storage_path('framework/testing'));
        try {
            $service->directory($path.'/source/logs');
            file_put_contents($path.'/source/document.txt', 'data');
            file_put_contents($path.'/source/logs/secret.log', 'excluded');
            $service->collect($path.'/source', $path.'/collected');
            $this->assertSame('data', file_get_contents($path.'/collected/document.txt'));
            $this->assertDirectoryDoesNotExist($path.'/collected/logs');
        } finally {
            $service->cleanup($path);
        }
        $this->assertDirectoryDoesNotExist($path);
    }

    public function test_traversal_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new BackupWorkspace)->create(storage_path('backups/../escape'));
    }

    public function test_symlink_is_refused(): void
    {
        $service = new BackupWorkspace;
        $path = $service->create(storage_path('framework/testing'));
        try {
            file_put_contents($path.'/target', 'data');
            if (! @symlink($path.'/target', $path.'/link')) {
                $this->markTestSkipped('Création de symlinks non autorisée par Windows.');
            }
            $this->expectException(\RuntimeException::class);
            $service->collect($path, $path.'-destination');
        } finally {
            @unlink($path.'/link');
            $service->cleanup($path);
            $service->cleanup($path.'-destination');
        }
    }
}
