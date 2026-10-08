<?php

namespace Tests\Feature;

use App\Services\Backup\BackupWorkspace;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DatabaseBackupServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'backup_test', 'database.connections.backup_test' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'username' => 'test', 'password' => 'TOP_SECRET_PASSWORD']]);
        DB::purge('backup_test');
    }

    public function test_missing_dump_binary_is_a_clean_error(): void
    {
        $service = new class extends DatabaseBackupService
        {
            public function findDumpBinary(string $driver): ?string
            {
                return null;
            }
        };
        $this->expectExceptionMessage('mysqldump ou mariadb-dump introuvable');
        $service->dump(storage_path('framework/testing'));
    }

    public function test_failed_process_does_not_expose_password_or_output(): void
    {
        $service = new class extends DatabaseBackupService
        {
            public array $arguments = [];

            public array $environment = [];

            public function findDumpBinary(string $driver): ?string
            {
                return 'mysqldump';
            }

            protected function runProcess(array $arguments, array $environment): Process
            {
                $this->arguments = $arguments;
                $this->environment = $environment;
                throw new \RuntimeException('TOP_SECRET_PASSWORD in process output');
            }
        };
        try {
            $service->dump(storage_path('framework/testing'));
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Échec du dump de la base de données.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('TOP_SECRET_PASSWORD', implode(' ', $service->arguments));
            $this->assertSame('TOP_SECRET_PASSWORD', $service->environment['MYSQL_PWD']);
        }
    }

    public function test_unsuccessful_process_is_rejected(): void
    {
        $service = new class extends DatabaseBackupService
        {
            public function findDumpBinary(string $driver): ?string
            {
                return 'mysqldump';
            }

            protected function runProcess(array $arguments, array $environment): Process
            {
                $process = \Mockery::mock(Process::class);
                $process->shouldReceive('isSuccessful')->once()->andReturn(false);

                return $process;
            }
        };
        $this->expectExceptionMessage('Échec du dump');
        $service->dump(storage_path('framework/testing'));
    }

    public function test_sqlite_dump_is_a_readable_consistent_database(): void
    {
        $workspace = new BackupWorkspace;
        $path = $workspace->create(storage_path('framework/testing'));
        try {
            $source = $path.'/source.sqlite';
            touch($source);
            config(['database.default' => 'backup_sqlite', 'database.connections.backup_sqlite' => ['driver' => 'sqlite', 'database' => $source]]);
            DB::purge('backup_sqlite');
            DB::statement('CREATE TABLE sample (value TEXT)');
            DB::insert('INSERT INTO sample VALUES (?)', ['saved']);
            $this->assertSame('sqlite', (new DatabaseBackupService)->dump($path));
            $pdo = new \PDO('sqlite:'.$path.'/database.sqlite');
            $this->assertSame('saved', $pdo->query('SELECT value FROM sample')->fetchColumn());
            $pdo = null;
        } finally {
            DB::disconnect('backup_sqlite');
            $workspace->cleanup($path);
        }
    }
}
