<?php

namespace Tests\Feature;

use App\Services\Backup\BackupEncryptionService;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\Support\BackupFixtures;
use Tests\TestCase;

class BackupManifestTest extends TestCase
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

    public function test_manifest_records_metadata_sizes_hashes_and_migrations_without_secrets(): void
    {
        config(['app.key' => 'APP_SECRET', 'mail.mailers.smtp.password' => 'MAIL_SECRET', 'reverb.apps.apps.0.secret' => 'REVERB_SECRET', 'database.connections.mysql.password' => 'DB_SECRET']);
        [$path, $manifest] = $this->createPayloadFixture();
        $json = file_get_contents($path.'/manifest.json');
        $this->assertTrue(BackupEncryptionService::isUuid($manifest['backup_id']));
        $this->assertTrue(BackupEncryptionService::isUtcDate($manifest['created_at']));
        $this->assertSame(1, $manifest['format_version']);
        $this->assertSame('test-v1', $manifest['key_id']);
        $this->assertSame(PHP_VERSION, $manifest['php_version']);
        $this->assertSame(Application::VERSION, $manifest['laravel_version']);
        $this->assertSame('fixture_db', $manifest['database']['name']);
        $this->assertSame('8.4.fixture', $manifest['database']['version']);
        $this->assertSame(['2026_01_01_fixture'], $manifest['migrations']['applied']);
        $this->assertSame(3, $manifest['files_count']);
        $this->assertSame(array_sum(array_column($manifest['files'], 'size')), $manifest['total_size']);
        $this->assertSame(hash('sha256', 'dump fixture'), $manifest['database']['dump']['sha256']);
        foreach ($manifest['files'] as $name => $file) {
            $this->assertSame(filesize($path.'/'.$name), $file['size']);
            $this->assertSame(hash_file('sha256', $path.'/'.$name), $file['sha256']);
        }
        foreach (['APP_SECRET', 'MAIL_SECRET', 'REVERB_SECRET', 'DB_SECRET', config('backup.encryption_key'), 'password', 'encryption_key'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertTrue($manifest['git_commit'] === null || preg_match('/^[a-f0-9]{40,64}$/D', $manifest['git_commit']) === 1);
    }

    public function test_sqlite_metadata_reports_version_logical_name_and_applied_migrations(): void
    {
        config(['database.default' => 'backup_metadata', 'database.connections.backup_metadata' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::purge('backup_metadata');
        DB::statement('CREATE TABLE migrations (migration TEXT, batch INTEGER)');
        DB::insert('INSERT INTO migrations VALUES (?, ?)', ['2026_fixture', 1]);
        $metadata = (new DatabaseBackupService)->metadata('sqlite');
        $this->assertSame(':memory:', $metadata['name']);
        $this->assertNotEmpty($metadata['version']);
        $this->assertSame(['2026_fixture'], $metadata['migrations']['applied']);
        $this->assertNotEmpty($metadata['migrations']['available']);
        DB::disconnect('backup_metadata');
    }
}
