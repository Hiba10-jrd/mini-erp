<?php

namespace Tests\Feature;

use App\Services\Backup\BackupArchiveService;
use App\Services\Backup\BackupEncryptionService;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupRetentionService;
use App\Services\Backup\BackupWorkspace;
use App\Services\Backup\DatabaseRestoreService;
use App\Services\Backup\FileRestoreService;
use App\Services\Backup\RestoreManager;
use App\Services\Backup\RestoreTargetGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\Support\RestoreFixtures;
use Tests\TestCase;

class RestoreValidationTest extends TestCase
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

    public static function requiredOptions(): array
    {
        return [['target-database'], ['target-storage'], ['confirm']];
    }

    #[DataProvider('requiredOptions')]
    public function test_restore_requires_every_safety_parameter(string $missing): void
    {
        $options = $this->restoreOptions('backup-id');
        unset($options['--'.$missing]);
        $this->artisan('backup:restore', ['backup' => 'nonexistent.erpbackup'] + $options)->expectsOutput('Paramètre --'.$missing.' obligatoire.')->assertFailed();
        $this->assertFileDoesNotExist($this->targetDatabase());
        $this->assertDirectoryDoesNotExist($this->targetStorage());
    }

    public function test_wrong_backup_id_is_rejected_before_target_writes(): void
    {
        [$path] = $this->createRestoreArchive();
        $report = app(RestoreManager::class)->restore($path, $this->targetDatabase(), $this->targetStorage(), 'wrong-id')->toArray();
        $this->assertSame('FAILED', $report['result']);
        $this->assertStringContainsString('exactement au backup_id', $report['error']);
        $this->assertFileDoesNotExist($this->targetDatabase());
        $this->assertDirectoryDoesNotExist($this->targetStorage());
    }

    public static function protectedStoragePaths(): array
    {
        return [['storage-root'], ['private'], ['public-storage'], ['public-root'], ['public-child'], ['app-child'], ['backups'], ['framework'], ['ancestor'], ['traversal'], ['drive-injection']];
    }

    #[DataProvider('protectedStoragePaths')]
    public function test_active_public_backup_or_unsafe_storage_is_rejected(string $case): void
    {
        $target = match ($case) {
            'storage-root' => storage_path(), 'private' => storage_path('app/private'),
            'public-storage' => storage_path('app/public'), 'public-root' => public_path(),
            'public-child' => public_path('isolated'), 'app-child' => storage_path('app/isolated'),
            'backups' => $this->backupRoot.'/backups/isolated', 'framework' => storage_path('framework/isolated'),
            'ancestor' => dirname($this->backupRoot), 'traversal' => $this->backupRoot.'/../escape',
            'drive-injection' => $this->backupRoot.'/C:/escape',
        };
        $report = app(RestoreManager::class)->restore('missing.erpbackup', $this->targetDatabase(), $target, 'anything')->toArray();
        $this->assertSame('FAILED', $report['result']);
        $this->assertSame('NOT_STARTED', $report['database_status']);
        $this->assertFileDoesNotExist($this->targetDatabase());
    }

    public function test_active_sqlite_database_and_existing_target_are_rejected(): void
    {
        $source = $this->backupRoot.'/active.sqlite';
        file_put_contents($source, 'active database sentinel');
        config(['database.connections.active_fixture' => ['driver' => 'sqlite', 'database' => $source]]);
        $report = app(RestoreManager::class)->restore('missing.erpbackup', $source, $this->targetStorage(), 'anything')->toArray();
        $this->assertSame('FAILED', $report['result']);
        $this->assertSame('active database sentinel', file_get_contents($source));
        $this->assertStringContainsString('base configurée', $report['error']);
    }

    public function test_mysql_names_in_database_url_overrides_are_protected(): void
    {
        config(['database.connections.url_fixture' => ['driver' => 'mysql', 'database' => 'ignored', 'url' => 'mysql://user:SECRET@localhost/active_url_db']]);
        $this->expectExceptionMessage('base active ou configurée');
        app(RestoreTargetGuard::class)->validateDatabase('ACTIVE_URL_DB');
    }

    public function test_configured_read_replica_database_is_protected(): void
    {
        config(['database.connections.replica_fixture' => ['driver' => 'mysql', 'database' => 'main_fixture', 'read' => ['database' => 'active_replica']]]);
        $this->expectExceptionMessage('base active ou configurée');
        app(RestoreTargetGuard::class)->validateDatabase('active_replica');
    }

    public function test_nonempty_storage_is_never_overwritten(): void
    {
        mkdir($this->targetStorage(), 0700, true);
        file_put_contents($this->targetStorage().'/sentinel', 'preserve');
        $report = app(RestoreManager::class)->restore('missing.erpbackup', $this->targetDatabase(), $this->targetStorage(), 'anything')->toArray();
        $this->assertSame('FAILED', $report['result']);
        $this->assertStringContainsString('nouvelle ou vide', $report['error']);
        $this->assertSame('preserve', file_get_contents($this->targetStorage().'/sentinel'));
    }

    public function test_invalid_archive_and_wrong_key_leave_targets_untouched(): void
    {
        [$path, $manifest] = $this->createRestoreArchive();
        config(['backup.encryption_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $report = app(RestoreManager::class)->restore($path, $this->targetDatabase(), $this->targetStorage(), $manifest['backup_id'])->toArray();
        $this->assertSame('FAILED', $report['result']);
        $this->assertStringContainsString('Authentification échouée', $report['error']);
        $this->assertSame([], glob($this->backupRoot.'/backups/mini-erp/work-*'));
        $this->assertFileDoesNotExist($this->targetDatabase());
        $this->assertDirectoryDoesNotExist($this->targetStorage());
    }

    public function test_driver_incompatibility_is_blocking(): void
    {
        [$path, $manifest] = $this->createRestoreArchive();
        $report = app(RestoreManager::class)->restore($path, $this->targetDatabase(), $this->targetStorage(), $manifest['backup_id'])->toArray();
        $this->assertStringContainsString('Drivers source et cible incompatibles', $report['error']);
        $this->assertSame('FAILED', $report['result']);
    }

    public function test_tampered_archive_or_incompatible_format_cannot_modify_targets(): void
    {
        foreach (['tampered', 'format'] as $case) {
            [$path, $manifest] = $this->createRestoreArchive($case === 'format' ? function ($manifest) {
                $manifest['format_version'] = 999;

                return $manifest;
            } : null);
            if ($case === 'tampered') {
                file_put_contents($path, 'trailing bytes', FILE_APPEND);
            }
            $report = app(RestoreManager::class)->restore($path, $this->targetDatabase(), $this->targetStorage(), $manifest['backup_id'])->toArray();
            $this->assertSame('FAILED', $report['result']);
            $this->assertSame('NOT_STARTED', $report['database_status']);
            $this->assertFileDoesNotExist($this->targetDatabase());
            $this->assertDirectoryDoesNotExist($this->targetStorage());
            $this->assertSame([], glob($this->backupRoot.'/backups/mini-erp/work-*'));
        }
    }

    public function test_restore_mutex_refuses_concurrency_before_database_writes(): void
    {
        $this->createRealSqliteSource();
        $path = app(BackupManager::class)->create();
        $id = app(BackupEncryptionService::class)->readHeader($path)['backup_id'];
        $lock = fopen($this->backupRoot.'/backups/.restore.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $report = app(RestoreManager::class)->restore($path, $this->targetDatabase(), $this->targetStorage(), $id)->toArray();
            $this->assertSame('FAILED', $report['result']);
            $this->assertStringContainsString('verrou', $report['error']);
            $this->assertFileDoesNotExist($this->targetDatabase());
            $this->assertDirectoryDoesNotExist($this->targetStorage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_archive_is_rechecked_after_interactive_approval(): void
    {
        $this->createRealSqliteSource();
        $path = app(BackupManager::class)->create();
        $id = app(BackupEncryptionService::class)->readHeader($path)['backup_id'];
        $report = app(RestoreManager::class)->restore($path, $this->targetDatabase(), $this->targetStorage(), $id, function () use ($path) {
            file_put_contents($path, 'changed after approval', FILE_APPEND);

            return true;
        })->toArray();
        $this->assertSame('FAILED', $report['result']);
        $this->assertSame('NOT_STARTED', $report['database_status']);
        $this->assertFileDoesNotExist($this->targetDatabase());
        $this->assertDirectoryDoesNotExist($this->targetStorage());
        $this->assertSame([], glob($this->backupRoot.'/backups/mini-erp/work-*'));
    }

    public function test_retention_cannot_delete_an_archive_during_restore_confirmation(): void
    {
        $this->createRealSqliteSource();
        $path = app(BackupManager::class)->create();
        $id = app(BackupEncryptionService::class)->readHeader($path)['backup_id'];
        config(['backup.retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0]]);
        $report = app(RestoreManager::class)->restore($path, $this->targetDatabase(), $this->targetStorage(), $id, function () use ($path) {
            $result = app(BackupRetentionService::class)->afterSuccessfulBackup($path, dirname($path));
            $this->assertSame(['*' => 'restore en cours'], $result['kept']);
            $this->assertSame([], $result['deleted']);
            $this->assertFileExists($path);

            return false;
        })->toArray();
        $this->assertSame('CANCELLED', $report['result']);
        $this->assertFileDoesNotExist($this->targetDatabase());
        $lock = fopen($this->backupRoot.'/backups/.restore.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }

    public function test_mysql_client_missing_is_clean_and_modifies_no_target(): void
    {
        [$path, $manifest] = $this->createRestoreArchive();
        $database = \Mockery::mock(DatabaseRestoreService::class)->makePartial();
        $database->shouldReceive('assertCompatible')->andReturnNull();
        $database->shouldReceive('validateTarget')->andThrow(new BackupException('Prérequis manquant : client mysql ou mariadb introuvable dans le PATH.'));
        $this->app->instance(DatabaseRestoreService::class, $database);
        $report = app(RestoreManager::class)->restore($path, 'isolated_target', $this->targetStorage(), $manifest['backup_id'])->toArray();
        $this->assertSame('FAILED', $report['result']);
        $this->assertStringContainsString('client mysql ou mariadb introuvable', $report['error']);
        $this->assertDirectoryDoesNotExist($this->targetStorage());
        $this->assertSame([], glob($this->backupRoot.'/backups/mini-erp/work-*'));
    }

    public function test_interactive_confirmation_defaults_to_refusal(): void
    {
        $this->createRealSqliteSource();
        $path = app(BackupManager::class)->create();
        $header = app(BackupEncryptionService::class)->readHeader($path);
        $options = $this->restoreOptions($header['backup_id']);
        unset($options['--no-interaction']);
        $this->artisan('backup:restore', ['backup' => $path] + $options)->expectsConfirmation('Confirmer la restauration isolée vers cette cible ?', 'no')->expectsOutputToContain('CANCELLED')->assertFailed();
        $this->assertFileDoesNotExist($this->targetDatabase());
        $this->assertDirectoryDoesNotExist($this->targetStorage());
    }

    public function test_database_failure_never_restores_files_and_cleans_workspace(): void
    {
        $this->createRealSqliteSource();
        $path = app(BackupManager::class)->create();
        $id = app(BackupEncryptionService::class)->readHeader($path)['backup_id'];
        $database = \Mockery::mock(DatabaseRestoreService::class)->makePartial();
        $database->shouldReceive('assertCompatible')->andReturnNull();
        $database->shouldReceive('validateTarget')->andReturnNull();
        $database->shouldReceive('restore')->once()->andThrow(new \RuntimeException('SECRET_INTERNAL_ERROR'));
        $files = \Mockery::mock(FileRestoreService::class, [$this->app->make(RestoreTargetGuard::class), $this->app->make(BackupWorkspace::class), $this->app->make(BackupArchiveService::class)])->makePartial();
        $files->shouldNotReceive('restore');
        $this->app->instance(DatabaseRestoreService::class, $database);
        $this->app->instance(FileRestoreService::class, $files);
        $operationLog = \Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('backup_operations')->andReturn($operationLog);
        $report = app(RestoreManager::class)->restore($path, $this->targetDatabase(), $this->targetStorage(), $id)->toArray();
        $this->assertSame('FAILED', $report['result']);
        $this->assertSame('FAILED', $report['database_status']);
        $this->assertSame('NOT_STARTED', $report['files_status']);
        $this->assertStringNotContainsString('SECRET_INTERNAL_ERROR', json_encode($report));
        $this->assertSame([], glob($this->backupRoot.'/backups/mini-erp/work-*'));
        $operationLog->shouldHaveReceived('info')->with('Restore operation', \Mockery::on(fn ($data) => $data['operation_id'] === $report['operation_id'] && $data['operation'] === 'restore'))->once();
    }

    public function test_file_failure_after_database_restore_is_incomplete_and_quarantined(): void
    {
        $this->createRealSqliteSource();
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 1, 'created_at' => 1]);
        $path = app(BackupManager::class)->create();
        $id = app(BackupEncryptionService::class)->readHeader($path)['backup_id'];
        $files = \Mockery::mock(FileRestoreService::class, [$this->app->make(RestoreTargetGuard::class), $this->app->make(BackupWorkspace::class), $this->app->make(BackupArchiveService::class)])->makePartial();
        $files->shouldReceive('restore')->once()->andThrow(new BackupException('Écriture de fichier restauré impossible.'));
        $this->app->instance(FileRestoreService::class, $files);
        $report = app(RestoreManager::class)->restore($path, $this->targetDatabase(), $this->targetStorage(), $id)->toArray();
        $this->assertSame('INCOMPLETE', $report['result']);
        $this->assertSame('RESTORED', $report['database_status']);
        $this->assertSame('NEUTRALIZED', $report['quarantine_status']);
        $this->assertSame('FAILED', $report['files_status']);
        $pdo = new \PDO('sqlite:'.$this->targetDatabase());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
        $pdo = null;
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame([], glob($this->backupRoot.'/backups/mini-erp/work-*'));
    }
}
