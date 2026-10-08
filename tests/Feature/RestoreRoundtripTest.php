<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Backup\BackupEncryptionService;
use App\Services\Backup\BackupManager;
use App\Services\Backup\RestoreManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use Psr\Log\LoggerInterface;
use Tests\Support\CustomerReminderFixtures;
use Tests\Support\RestoreFixtures;
use Tests\TestCase;

class RestoreRoundtripTest extends TestCase
{
    use CustomerReminderFixtures, RestoreFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeRestoreFixtures();
        $this->createRealSqliteSource();
    }

    protected function tearDown(): void
    {
        $this->cleanupRestoreFixtures();
        parent::tearDown();
    }

    public function test_real_erp_sqlite_backup_restore_roundtrip_preserves_business_and_quarantines_only_target(): void
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['remember_token' => 'OLD_REMEMBER_TOKEN']);
        $user->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());
        $this->actingAs($user);
        $invoice = $this->invoice();
        $this->pay($invoice, '25.00');
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'fixture', 'notifiable_type' => 'user', 'notifiable_id' => $user->id, 'data' => '{"message":"persisted notification"}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => 'OLD_SESSION_SECRET', 'last_activity' => 123]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'OLD_JOB_SECRET', 'attempts' => 0, 'available_at' => 123, 'created_at' => 123]);
        DB::table('job_batches')->insert(['id' => 'old-batch', 'name' => 'old batch', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'created_at' => 123]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => 'old payload', 'exception' => 'old exception']);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'OLD_RESET_SECRET']);
        $source = DB::connection()->getPdo();
        $sourceHash = hash_file('sha256', $this->backupRoot.'/source.sqlite');
        $before = [];
        foreach (['users', 'invoices', 'invoice_items', 'payments', 'payment_allocations', 'operation_histories', 'notifications', 'roles', 'permissions', 'role_user', 'permission_role'] as $table) {
            $before[$table] = $source->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        }
        $this->assertNotEmpty($before['invoices']);
        $this->assertNotEmpty($before['payments']);
        $this->assertNotEmpty($before['operation_histories']);
        config(['database.connections.restore_source.password' => 'DB_CREDENTIAL_SECRET', 'backup.restore.mysql.password' => 'RESTORE_PASSWORD_SECRET']);
        $operationLog = \Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('backup_operations')->andReturn($operationLog);
        $path = app(BackupManager::class)->create();
        $header = app(BackupEncryptionService::class)->readHeader($path);
        $report = app(RestoreManager::class)->restore($path, $this->targetDatabase(), $this->targetStorage(), $header['backup_id'])->toArray();
        $this->assertSame('SUCCESS', $report['result'], json_encode($report));
        $this->assertSame('RESTORED', $report['database_status']);
        $this->assertSame('RESTORED', $report['files_status']);
        $this->assertSame('NEUTRALIZED', $report['quarantine_status']);
        $this->assertSame('VALID', $report['verification_status']);
        $this->assertNotNull($report['finished_at']);
        $this->assertTrue(BackupEncryptionService::isUuid($report['operation_id']));
        $target = new PDO('sqlite:'.$this->targetDatabase());
        foreach ($before as $table => $expected) {
            if ($table === 'users') {
                foreach ($expected as &$row) {
                    $row['remember_token'] = null;
                } unset($row);
            }
            $actual = $target->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame($expected, $actual, $table.' changed');
        }
        foreach (['sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'] as $table) {
            $this->assertSame(0, (int) $target->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn(), $table);
            $this->assertSame(1, (int) $source->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn(), 'Active '.$table.' modified');
        }
        $this->assertSame('OLD_REMEMBER_TOKEN', $source->query('SELECT remember_token FROM users WHERE id = '.$user->id)->fetchColumn());
        $this->assertSame($source, DB::connection()->getPdo());
        $this->assertSame($sourceHash, hash_file('sha256', $this->backupRoot.'/source.sqlite'));
        foreach (['private', 'public'] as $type) {
            $this->assertSame(hash_file('sha256', storage_path('app/'.$type.'/receipt.txt')), hash_file('sha256', $this->targetStorage().'/'.$type.'/receipt.txt'));
        }
        $this->assertSame([], glob($this->backupRoot.'/backups/mini-erp/work-*'));
        $this->assertSame([], glob($this->targetStorage().'/*.erpbackup'));
        $this->assertFileDoesNotExist($this->targetStorage().'/restore-client.cnf');
        foreach (['DB_CREDENTIAL_SECRET', 'RESTORE_PASSWORD_SECRET', 'OLD_SESSION_SECRET', 'OLD_JOB_SECRET', 'OLD_RESET_SECRET', config('backup.encryption_key')] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($report));
        }
        $operationLog->shouldHaveReceived('info')->with('Restore operation', \Mockery::on(fn ($data) => $data['operation_id'] === $report['operation_id'] && $data['operation'] === 'restore'))->once();
        $target = $source = null;
    }

    public function test_restore_command_accepts_explicit_noninteractive_confirmation_and_emits_report(): void
    {
        $path = app(BackupManager::class)->create();
        $id = app(BackupEncryptionService::class)->readHeader($path)['backup_id'];
        $this->artisan('backup:restore', ['backup' => $path] + $this->restoreOptions($id))->expectsOutputToContain('"result": "SUCCESS"')->expectsOutput('SUCCESS : restauration isolée vérifiée.')->assertSuccessful();
        $this->assertFileExists($this->targetDatabase());
        $this->assertDirectoryExists($this->targetStorage().'/private');
        $this->assertSame([], glob($this->backupRoot.'/backups/mini-erp/work-*'));
    }
}
