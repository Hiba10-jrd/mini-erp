<?php

namespace Tests\Feature;

use App\Services\Backup\BackupEncryptionService;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupVerificationService;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\Support\BackupFixtures;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    use BackupFixtures;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBackupFixtures();
        $this->root = $this->backupRoot.'/backups';
    }

    protected function tearDown(): void
    {
        $this->cleanupBackupFixtures();
        parent::tearDown();
    }

    public function test_missing_prerequisite_fails_cleanly_and_cleans_workspace(): void
    {
        config(['database.default' => 'backup_missing', 'database.connections.backup_missing' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'username' => 'test', 'password' => 'TOP_SECRET_PASSWORD']]);
        DB::purge('backup_missing');
        $this->app->instance(DatabaseBackupService::class, new class extends DatabaseBackupService
        {
            public function findDumpBinary(string $driver): ?string
            {
                return null;
            }
        });
        $operationLog = \Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('backup_operations')->andReturn($operationLog);
        $this->artisan('backup:create')->expectsOutputToContain('Prérequis manquant')->assertFailed();
        $this->assertSame([], glob($this->root.'/mini-erp/work-*'));
        $operationLog->shouldHaveReceived('info')->with('Backup operation', \Mockery::on(fn ($data) => $data['result'] === 'failed' && isset($data['operation_id'])))->once();
        $lock = fopen($this->root.'/.backup.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }

    public function test_concurrent_lock_is_rejected(): void
    {
        $lock = fopen($this->root.'/.backup.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->artisan('backup:create')->expectsOutputToContain('déjà en cours')->assertFailed();
            $this->assertSame([], glob($this->root.'/mini-erp/work-*'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_success_creates_encrypted_backup_removes_plaintext_and_releases_lock(): void
    {
        $this->app->instance(DatabaseBackupService::class, new class extends DatabaseBackupService
        {
            public function dump(string $workspace): string
            {
                file_put_contents($workspace.'/database.sql', 'dump fixture');

                return 'mysql';
            }
        });
        $this->artisan('backup:create')->expectsOutputToContain('Sauvegarde chiffrée créée et vérifiée')->assertSuccessful();
        $paths = glob($this->root.'/mini-erp/*.erpbackup');
        $this->assertCount(1, $paths);
        $this->assertMatchesRegularExpression('/backup-\d{4}-\d{2}-\d{2}T\d{6}Z-[a-f0-9-]{36}\.erpbackup$/', basename($paths[0]));
        $manifest = app(BackupVerificationService::class)->verify($paths[0], $this->root.'/mini-erp');
        $this->assertSame(hash('sha256', 'dump fixture'), $manifest['files']['database/database.sql']['sha256']);
        $this->assertSame([], glob($this->root.'/mini-erp/work-*'));
        $this->assertSame([], glob($this->root.'/mini-erp/*.part'));
        $this->assertStringNotContainsString('dump fixture', file_get_contents($paths[0]));
        $this->assertStringNotContainsString('manifest.json', file_get_contents($paths[0]));
        $lock = fopen($this->root.'/.backup.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }

    public function test_unexpected_error_never_exposes_secrets(): void
    {
        $this->app->instance(DatabaseBackupService::class, new class extends DatabaseBackupService
        {
            public function dump(string $workspace): string
            {
                throw new \RuntimeException('TOP_SECRET_PASSWORD');
            }
        });
        $this->artisan('backup:create')->expectsOutput('Échec de préparation de la sauvegarde.')->assertFailed();
        $this->assertSame([], glob($this->root.'/mini-erp/work-*'));
    }

    public function test_missing_encryption_key_fails_before_creating_workspace(): void
    {
        config(['backup.encryption_key' => null]);
        $this->artisan('backup:create')->expectsOutput('BACKUP_ENCRYPTION_KEY obligatoire.')->assertFailed();
        $this->assertDirectoryDoesNotExist($this->root.'/mini-erp');
    }

    public function test_failed_part_verification_removes_part_dump_workspace_and_final_file(): void
    {
        $verification = \Mockery::mock(BackupVerificationService::class);
        $verification->shouldReceive('verify')->once()->andReturnUsing(function ($part, $root) {
            $this->assertFileExists($part);
            $this->assertSame([], glob($root.'/*.erpbackup'));
            $this->assertCount(1, glob($root.'/work-*/database/database.sql'));
            throw new BackupException('Empreinte SHA-256 incorrecte.');
        });
        $this->app->instance(BackupVerificationService::class, $verification);
        $operationLog = \Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('backup_operations')->andReturn($operationLog);
        $this->artisan('backup:create')->expectsOutput('Empreinte SHA-256 incorrecte.')->assertFailed();
        $this->assertSame([], glob($this->root.'/mini-erp/*.part'));
        $this->assertSame([], glob($this->root.'/mini-erp/*.erpbackup'));
        $this->assertSame([], glob($this->root.'/mini-erp/work-*'));
        $operationLog->shouldHaveReceived('info')->with('Backup operation', \Mockery::on(fn ($data) => $data['result'] === 'failed' && isset($data['operation_id'])))->once();
    }

    public function test_verify_and_list_commands_work_with_final_archive(): void
    {
        $this->artisan('backup:create')->assertSuccessful();
        $path = glob($this->root.'/mini-erp/*.erpbackup')[0];
        $this->artisan('backup:verify', ['backup' => basename($path)])->expectsOutput('VALID')->assertSuccessful();
        $header = app(BackupEncryptionService::class)->readHeader($path);
        $this->artisan('backup:list')->expectsTable(
            ['Fichier', 'Backup ID', 'Date UTC', 'Octets', 'key_id', 'Version', 'État'],
            [[basename($path), $header['backup_id'], $header['created_at'], filesize($path), 'test-v1', 1, 'HEADER OK (non authentifié)']]
        )->assertSuccessful();
        $this->artisan('backup:list', ['--verify' => true])->expectsTable(
            ['Fichier', 'Backup ID', 'Date UTC', 'Octets', 'key_id', 'Version', 'État'],
            [[basename($path), $header['backup_id'], $header['created_at'], filesize($path), 'test-v1', 1, 'VALID']]
        )->assertSuccessful();
        $this->assertSame([], glob($this->root.'/mini-erp/work-*'));
    }

    public function test_header_listing_needs_no_key_and_full_verification_detects_corruption(): void
    {
        $this->artisan('backup:create')->assertSuccessful();
        $path = glob($this->root.'/mini-erp/*.erpbackup')[0];
        config(['backup.encryption_key' => null, 'backup.enabled' => false]);
        $this->artisan('backup:list')->assertSuccessful();
        $this->artisan('backup:verify', ['backup' => basename($path)])->expectsOutput('INVALID : BACKUP_ENCRYPTION_KEY obligatoire.')->assertFailed();
        config(['backup.encryption_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->artisan('backup:verify', ['backup' => basename($path)])->expectsOutputToContain('INVALID : Authentification échouée')->assertFailed();
        $this->artisan('backup:list', ['--verify' => true])->assertFailed();
    }

    public function test_verify_refuses_traversal_and_outside_disk_paths(): void
    {
        $this->artisan('backup:verify', ['backup' => '../outside.erpbackup'])->expectsOutput('INVALID : Chemin de sauvegarde invalide.')->assertFailed();
        [$path] = $this->createEncryptedFixture();
        $this->artisan('backup:verify', ['backup' => $path])->expectsOutputToContain('INVALID : Archive introuvable ou située hors')->assertFailed();
    }
}
