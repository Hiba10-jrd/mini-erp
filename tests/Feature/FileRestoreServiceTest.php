<?php

namespace Tests\Feature;

use App\Services\Backup\BackupArchiveService;
use App\Services\Backup\BackupException;
use App\Services\Backup\FileRestoreService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RestoreFixtures;
use Tests\TestCase;
use ZipArchive;

class FileRestoreServiceTest extends TestCase
{
    use RestoreFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeRestoreFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupRestoreFixtures();
        parent::tearDown();
    }

    private function payload(?callable $mutate = null): array
    {
        [$path, $manifest] = $this->createPayloadFixture();
        $zipPath = app(BackupArchiveService::class)->create($path, $manifest);
        if ($mutate !== null) {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($zipPath));
            $mutate($zip);
            $this->assertTrue($zip->close());
        }

        return [app(BackupArchiveService::class)->open($zipPath), $manifest];
    }

    public function test_private_and_public_files_are_restored_and_hashes_match(): void
    {
        [$zip, $manifest] = $this->payload();
        try {
            app(FileRestoreService::class)->restore($zip, $manifest, $this->targetStorage());
            app(FileRestoreService::class)->checkConsistency($this->targetStorage(), $manifest);
            $this->assertSame('private fixture', file_get_contents($this->targetStorage().'/private/receipt.txt'));
            $this->assertSame('public fixture', file_get_contents($this->targetStorage().'/public/receipt.txt'));
            $this->assertSame($manifest['files']['storage/private/receipt.txt']['sha256'], hash_file('sha256', $this->targetStorage().'/private/receipt.txt'));
            $this->assertFileDoesNotExist($this->targetStorage().'/manifest.json');
            $this->assertFileDoesNotExist($this->targetStorage().'/database.sql');
            $this->assertFileDoesNotExist($this->targetStorage().'/payload.zip');
        } finally {
            $zip->close();
        }
    }

    public function test_nonempty_target_and_overwrite_are_refused(): void
    {
        [$zip, $manifest] = $this->payload();
        try {
            app(FileRestoreService::class)->restore($zip, $manifest, $this->targetStorage());
            file_put_contents($this->targetStorage().'/private/receipt.txt', 'preserved sentinel');
            try {
                app(FileRestoreService::class)->restore($zip, $manifest, $this->targetStorage());
                $this->fail('Overwrite accepted');
            } catch (BackupException $exception) {
                $this->assertStringContainsString('nouvelle ou vide', $exception->getMessage());
                $this->assertSame('preserved sentinel', file_get_contents($this->targetStorage().'/private/receipt.txt'));
            }
        } finally {
            $zip->close();
        }
    }

    public static function unsafeEntries(): array
    {
        return [['storage/private/../../escape'], ['/absolute'], ['storage/private/..\\escape'], ['C:/injected'], ['unexpected'], ['storage/private/RECEIPT.txt']];
    }

    #[DataProvider('unsafeEntries')]
    public function test_unsafe_entries_are_refused_before_creating_target(string $name): void
    {
        [$zip, $manifest] = $this->payload(fn ($zip) => $zip->addFromString($name, 'unsafe'));
        try {
            try {
                app(FileRestoreService::class)->restore($zip, $manifest, $this->targetStorage());
                $this->fail('Unsafe entry accepted');
            } catch (BackupException) {
                $this->assertDirectoryDoesNotExist($this->targetStorage());
                $this->assertFileDoesNotExist($this->backupRoot.'/escape');
            }
        } finally {
            $zip->close();
        }
    }

    public function test_zip_symlink_is_refused_without_requiring_os_symlink_permissions(): void
    {
        [$zip, $manifest] = $this->payload(fn ($zip) => $zip->setExternalAttributesName('storage/private/receipt.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16));
        try {
            try {
                app(FileRestoreService::class)->restore($zip, $manifest, $this->targetStorage());
                $this->fail('Symlink accepted');
            } catch (BackupException $exception) {
                $this->assertStringContainsString('Symlink', $exception->getMessage());
                $this->assertDirectoryDoesNotExist($this->targetStorage());
            }
        } finally {
            $zip->close();
        }
    }

    public function test_bad_hash_removes_owned_invalid_file(): void
    {
        [$zip, $manifest] = $this->payload();
        $manifest['files']['storage/private/receipt.txt']['sha256'] = str_repeat('0', 64);
        try {
            try {
                app(FileRestoreService::class)->restore($zip, $manifest, $this->targetStorage());
                $this->fail('Bad hash accepted');
            } catch (BackupException $exception) {
                $this->assertStringContainsString('Intégrité', $exception->getMessage());
                $this->assertFileDoesNotExist($this->targetStorage().'/private/receipt.txt');
            }
        } finally {
            $zip->close();
        }
    }

    public function test_consistency_refuses_extra_files(): void
    {
        [$zip, $manifest] = $this->payload();
        try {
            app(FileRestoreService::class)->restore($zip, $manifest, $this->targetStorage());
        } finally {
            $zip->close();
        }
        file_put_contents($this->targetStorage().'/unlisted', 'extra');
        $this->expectExceptionMessage('Fichiers restaurés incompatibles');
        app(FileRestoreService::class)->checkConsistency($this->targetStorage(), $manifest);
    }
}
