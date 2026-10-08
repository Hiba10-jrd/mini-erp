<?php

namespace Tests\Feature;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupVerificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BackupFixtures;
use Tests\TestCase;
use ZipArchive;

class BackupVerificationTest extends TestCase
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

    public function test_valid_archive_is_verified_and_plaintext_workspace_is_removed(): void
    {
        [$path, $expected] = $this->createEncryptedFixture();
        $manifest = app(BackupVerificationService::class)->verify($path, $this->backupRoot.'/verification');
        $this->assertSame($expected, $manifest);
        $this->assertSame([], glob($this->backupRoot.'/verification/work-*'));
        $this->assertFileExists($path);
    }

    public static function manifestCorruptions(): array
    {
        return [['invalid-object'], ['format-version'], ['hash'], ['size'], ['dump-hash'], ['dump-path'], ['file-count'], ['total-size'], ['header-mismatch']];
    }

    #[DataProvider('manifestCorruptions')]
    public function test_invalid_manifest_hash_sizes_or_dump_are_rejected(string $corruption): void
    {
        [$path] = $this->createEncryptedFixture(function (array $manifest) use ($corruption) {
            switch ($corruption) {
                case 'invalid-object': return ['invalid' => true];
                case 'format-version': $manifest['format_version'] = 999;
                    break;
                case 'hash': $manifest['files']['storage/private/receipt.txt']['sha256'] = str_repeat('0', 64);
                    break;
                case 'size':
                    $manifest['files']['storage/private/receipt.txt']['size']++;
                    $manifest['total_size']++;
                    break;
                case 'dump-hash': $manifest['database']['dump']['sha256'] = str_repeat('0', 64);
                    break;
                case 'dump-path': $manifest['database']['dump']['path'] = 'database/wrong.sql';
                    break;
                case 'file-count': $manifest['files_count']++;
                    break;
                case 'total-size': $manifest['total_size']++;
                    break;
                case 'header-mismatch': $manifest['key_id'] = 'other-key';
                    break;
            }

            return $manifest;
        });
        $this->assertVerificationFailure($path);
    }

    public static function zipCorruptions(): array
    {
        return [
            ['no-manifest'], ['invalid-json'], ['missing-dump'], ['undeclared-file'], ['unexpected-entry'],
            ['absolute'], ['traversal'], ['backslash'], ['symlink'], ['case-duplicate'], ['windows-drive'],
        ];
    }

    #[DataProvider('zipCorruptions')]
    public function test_missing_manifest_or_unsafe_zip_entries_are_rejected(string $corruption): void
    {
        [$path] = $this->createEncryptedFixture(null, function (ZipArchive $zip) use ($corruption) {
            switch ($corruption) {
                case 'no-manifest': $zip->deleteName('manifest.json');
                    break;
                case 'invalid-json': $zip->addFromString('manifest.json', '{invalid');
                    break;
                case 'missing-dump': $zip->deleteName('database/database.sql');
                    break;
                case 'undeclared-file': $zip->addFromString('storage/private/undeclared.txt', 'unexpected');
                    break;
                case 'unexpected-entry': $zip->addFromString('unexpected.txt', 'unexpected');
                    break;
                case 'absolute': $zip->addFromString('/storage/private/file', 'unexpected');
                    break;
                case 'traversal': $zip->addFromString('storage/private/../../escape', 'unexpected');
                    break;
                case 'backslash': $zip->addFromString('storage/private/..\\escape', 'unexpected');
                    break;
                case 'windows-drive': $zip->addFromString('C:/storage/private/file', 'unexpected');
                    break;
                case 'symlink':
                    $zip->setExternalAttributesName('storage/private/receipt.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16);
                    break;
                case 'case-duplicate': $zip->addFromString('storage/private/RECEIPT.txt', 'duplicate');
                    break;
            }
        });
        $this->assertVerificationFailure($path);
    }

    public function test_encrypted_corruption_also_cleans_verification_workspace(): void
    {
        [$path] = $this->createEncryptedFixture();
        file_put_contents($path, 'trailing', FILE_APPEND);
        $this->assertVerificationFailure($path);
    }

    private function assertVerificationFailure(string $path): void
    {
        try {
            app(BackupVerificationService::class)->verify($path, $this->backupRoot.'/verification');
            $this->fail('Invalid backup accepted');
        } catch (BackupException $exception) {
            $this->assertNotEmpty($exception->getMessage());
            $this->assertSame([], glob($this->backupRoot.'/verification/work-*'));
        }
    }
}
