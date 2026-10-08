<?php

namespace Tests\Feature;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupWorkspace;
use App\Services\Backup\DatabaseRestoreService;
use App\Services\Backup\MySqlRestoreGuard;
use App\Services\Backup\RestoreTargetGuard;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\RestoreFixtures;
use Tests\TestCase;

class DatabaseRestoreServiceTest extends TestCase
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

    private function sqliteDump(): string
    {
        $path = $this->backupRoot.'/dump.sqlite';
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, remember_token TEXT)');
        $pdo->exec("INSERT INTO users VALUES (1, 'business user', 'old_token')");
        foreach (['invoices', 'payments', 'notifications', 'operation_histories', 'roles', 'permissions', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'] as $table) {
            $pdo->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY, value TEXT)');
            $pdo->exec("INSERT INTO $table VALUES (1, 'preserved business data')");
        }
        $pdo = null;

        return $path;
    }

    public function test_real_sqlite_restore_is_readable_and_quarantine_preserves_business_data(): void
    {
        $dump = $this->sqliteDump();
        $service = app(DatabaseRestoreService::class);
        $pdo = $service->restore($dump, $this->targetDatabase(), 'sqlite', $this->backupRoot);
        $this->assertSame('business user', $pdo->query('SELECT name FROM users')->fetchColumn());
        $service->quarantine($pdo, 'sqlite');
        $service->checkConsistency($pdo, 'sqlite', ['migrations' => ['applied' => null]]);
        foreach (['sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'] as $table) {
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
        }
        $this->assertNull($pdo->query('SELECT remember_token FROM users')->fetchColumn());
        foreach (['invoices', 'payments', 'notifications', 'operation_histories', 'roles', 'permissions'] as $table) {
            $this->assertSame('preserved business data', $pdo->query('SELECT value FROM '.$table)->fetchColumn());
        }
        $pdo = null;
        $source = new PDO('sqlite:'.$dump);
        $this->assertSame(1, (int) $source->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        $this->assertSame('old_token', $source->query('SELECT remember_token FROM users')->fetchColumn());
        $source = null;
    }

    public function test_existing_sqlite_target_is_never_overwritten(): void
    {
        $dump = $this->sqliteDump();
        mkdir(dirname($this->targetDatabase()), 0700, true);
        file_put_contents($this->targetDatabase(), 'existing target');
        try {
            app(DatabaseRestoreService::class)->restore($dump, $this->targetDatabase(), 'sqlite', $this->backupRoot);
            $this->fail('Overwrite accepted');
        } catch (BackupException) {
            $this->assertSame('existing target', file_get_contents($this->targetDatabase()));
        }
    }

    public function test_invalid_sqlite_dump_creates_no_target_file(): void
    {
        $dump = $this->backupRoot.'/invalid.sqlite';
        file_put_contents($dump, 'not SQLite');
        try {
            app(DatabaseRestoreService::class)->restore($dump, $this->targetDatabase(), 'sqlite', $this->backupRoot);
            $this->fail('Invalid dump accepted');
        } catch (BackupException) {
            $this->assertFileDoesNotExist($this->targetDatabase());
        }
    }

    public function test_quarantine_refuses_triggers_that_could_delete_business_data(): void
    {
        $dump = $this->sqliteDump();
        $source = new PDO('sqlite:'.$dump);
        $source->exec('CREATE TRIGGER unsafe AFTER DELETE ON sessions BEGIN DELETE FROM invoices; END');
        $source = null;
        $service = app(DatabaseRestoreService::class);
        $pdo = $service->restore($dump, $this->targetDatabase(), 'sqlite', $this->backupRoot);
        try {
            $service->quarantine($pdo, 'sqlite');
            $this->fail('Unsafe trigger accepted');
        } catch (BackupException) {
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        } finally {
            $pdo = null;
        }
    }

    public function test_expected_migrations_missing_is_a_consistency_failure(): void
    {
        $service = app(DatabaseRestoreService::class);
        $pdo = $service->restore($this->sqliteDump(), $this->targetDatabase(), 'sqlite', $this->backupRoot);
        try {
            $this->expectExceptionMessage('Table migrations attendue absente');
            $service->checkConsistency($pdo, 'sqlite', ['migrations' => ['applied' => ['2026_migration']]]);
        } finally {
            $pdo = null;
        }
    }

    public function test_quarantine_never_accepts_an_active_or_unowned_connection(): void
    {
        $source = $this->sqliteDump();
        config(['database.connections.active_guard' => ['driver' => 'sqlite', 'database' => $source]]);
        $pdo = new PDO('sqlite:'.$source);
        try {
            try {
                app(DatabaseRestoreService::class)->quarantine($pdo, 'sqlite');
                $this->fail('Unowned active connection accepted');
            } catch (BackupException $exception) {
                $this->assertStringContainsString('non issue d’un restore isolé', $exception->getMessage());
                $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
                $this->assertSame('old_token', $pdo->query('SELECT remember_token FROM users')->fetchColumn());
            }
        } finally {
            $pdo = null;
        }
    }

    public function test_a_restored_connection_attached_to_laravel_is_no_longer_an_isolated_target(): void
    {
        $service = app(DatabaseRestoreService::class);
        $pdo = $service->restore($this->sqliteDump(), $this->targetDatabase(), 'sqlite', $this->backupRoot);
        $connection = DB::connection();
        $original = $connection->getRawPdo();
        $connection->setPdo($pdo);
        try {
            try {
                $service->quarantine($pdo, 'sqlite');
                $this->fail('Application-bound restored connection accepted');
            } catch (BackupException $exception) {
                $this->assertStringContainsString('utilisée par l’application', $exception->getMessage());
                $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
            }
        } finally {
            $connection->setPdo($original);
            $pdo = null;
        }
    }

    public function test_quarantine_refuses_cascades_from_sessions_into_business_tables(): void
    {
        $dump = $this->sqliteDump();
        $source = new PDO('sqlite:'.$dump);
        $source->exec('CREATE TABLE linked_business (id INTEGER PRIMARY KEY, session_id INTEGER REFERENCES sessions(id) ON DELETE CASCADE)');
        $source->exec('INSERT INTO linked_business VALUES (1, 1)');
        $source = null;
        $service = app(DatabaseRestoreService::class);
        $pdo = $service->restore($dump, $this->targetDatabase(), 'sqlite', $this->backupRoot);
        try {
            $service->quarantine($pdo, 'sqlite');
            $this->fail('Business cascade accepted');
        } catch (BackupException) {
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM linked_business')->fetchColumn());
        } finally {
            $pdo = null;
        }
    }

    public function test_mysql_mariadb_form_a_compatible_group(): void
    {
        $service = app(DatabaseRestoreService::class);
        $service->assertCompatible('mysql', 'mariadb');
        $service->assertCompatible('mariadb', 'mysql');
        $this->expectExceptionMessage('Drivers source et cible incompatibles');
        $service->assertCompatible('sqlite', 'mysql');
    }

    public function test_migration_records_cannot_mask_missing_erp_tables(): void
    {
        $service = app(DatabaseRestoreService::class);
        $pdo = $service->restore($this->sqliteDump(), $this->targetDatabase(), 'sqlite', $this->backupRoot);
        $migration = '0001_01_01_000000_create_users_table';
        $pdo->exec('CREATE TABLE migrations (migration TEXT)');
        $pdo->prepare('INSERT INTO migrations VALUES (?)')->execute([$migration]);
        $service->quarantine($pdo, 'sqlite');
        $pdo->exec('ALTER TABLE sessions RENAME TO orphaned_sessions');
        try {
            $this->expectExceptionMessage('Tables ERP attendues absentes');
            $service->checkConsistency($pdo, 'sqlite', ['migrations' => ['applied' => [$migration]]]);
        } finally {
            $pdo = null;
        }
    }

    public function test_mysql_client_absent_fails_before_connecting_or_changing_targets(): void
    {
        $service = new class(app(RestoreTargetGuard::class), app(BackupWorkspace::class), app(MySqlRestoreGuard::class)) extends DatabaseRestoreService
        {
            public function findClient(string $driver): ?string
            {
                return null;
            }

            protected function connectMysql(string $target): PDO
            {
                throw new \LogicException('Must not connect');
            }
        };
        $this->expectExceptionMessage('client mysql ou mariadb introuvable');
        $service->validateTarget('isolated_target', 'mysql');
    }

    public function test_mysql_nonempty_target_is_refused_without_import(): void
    {
        $service = new class(app(RestoreTargetGuard::class), app(BackupWorkspace::class), app(MySqlRestoreGuard::class)) extends DatabaseRestoreService
        {
            public function findClient(string $driver): ?string
            {
                return 'mysql';
            }

            protected function connectMysql(string $target): PDO
            {
                $pdo = new PDO('sqlite::memory:');
                $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
                $pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_NAME TEXT, TABLE_SCHEMA TEXT, TABLE_TYPE TEXT)');
                $pdo->sqliteCreateFunction('DATABASE', fn () => 'isolated_target');
                $pdo->exec("INSERT INTO information_schema.TABLES VALUES ('existing', 'isolated_target', 'BASE TABLE')");

                return $pdo;
            }

            protected function assertRestrictedAccount(PDO $pdo, string $target): void {}
        };
        $this->expectExceptionMessage('doit exister et être vide');
        $service->validateTarget('isolated_target', 'mysql');
    }

    public static function unsafeGrants(): array
    {
        return [
            [['GRANT ALL PRIVILEGES ON *.* TO `restore`@`localhost`']],
            [['GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP ON `isolated`.* TO `restore`@`localhost`']],
            [['GRANT SELECT, INSERT, UPDATE, DELETE, CREATE ON `active_db`.* TO `restore`@`localhost`']],
            [['GRANT `admin_role`@`%` TO `restore`@`localhost`']],
            [['GRANT SELECT, INSERT, UPDATE, DELETE, CREATE ON `isolated`.* TO `restore`@`localhost` WITH GRANT OPTION']],
            [['GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, TRIGGER ON `isolated`.* TO `restore`@`localhost`']],
        ];
    }

    #[DataProvider('unsafeGrants')]
    public function test_mysql_grants_cannot_touch_other_databases_or_drop_target(array $grants): void
    {
        $this->expectException(BackupException::class);
        app(MySqlRestoreGuard::class)->assertGrants($grants, 'isolated');
    }

    public function test_mysql_escaped_schema_grants_are_accepted_and_wildcards_refused(): void
    {
        $grant = 'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, LOCK TABLES ON `isolated\\_target`.* TO `restore`@`localhost`';
        app(MySqlRestoreGuard::class)->assertGrants(['GRANT USAGE ON *.* TO `restore`@`localhost`', $grant], 'isolated_target');
        $this->expectException(BackupException::class);
        app(MySqlRestoreGuard::class)->assertGrants([str_replace('\\_', '_', $grant)], 'isolated_target');
    }

    public function test_mysql_import_credentials_are_private_not_in_argv_and_always_cleaned(): void
    {
        config(['backup.restore.mysql.username' => 'dedicated', 'backup.restore.mysql.password' => "SUPER_SECRET\"\\\nPASSWORD"]);
        $dump = $this->backupRoot.'/dump.sql';
        file_put_contents($dump, "DROP TABLE IF EXISTS `test`;\nCREATE TABLE `test` (id INT);\n");
        $service = new class(app(RestoreTargetGuard::class), app(BackupWorkspace::class), app(MySqlRestoreGuard::class)) extends DatabaseRestoreService
        {
            public array $arguments = [];

            public string $credentialText = '';

            public string $sql = '';

            public function validateTarget(string $target, string $driver): void {}

            public function findClient(string $driver): ?string
            {
                return 'mysql';
            }

            protected function runImport(array $arguments, $input, string $workspace): Process
            {
                $this->arguments = $arguments;
                $this->credentialText = file_get_contents($workspace.'/restore-client.cnf');
                $this->sql = stream_get_contents($input);
                throw new \RuntimeException('SUPER_SECRET stderr');
            }
        };
        try {
            $service->restore($dump, 'isolated_target', 'mysql', $this->backupRoot);
            $this->fail('Expected failure');
        } catch (BackupException $exception) {
            $this->assertStringNotContainsString('SUPER_SECRET', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('SUPER_SECRET', implode(' ', $service->arguments));
            $this->assertStringContainsString('SUPER_SECRET', $service->credentialText);
            $this->assertStringStartsWith('--defaults-extra-file=', $service->arguments[1]);
            $this->assertContains('--binary-mode', $service->arguments);
            $this->assertContains('--local-infile=0', $service->arguments);
            $this->assertStringNotContainsString('DROP TABLE', $service->sql);
            $this->assertStringContainsString('CREATE TABLE', $service->sql);
            $this->assertFileDoesNotExist($this->backupRoot.'/restore-client.cnf');
            $this->assertFileDoesNotExist($this->backupRoot.'/restore-import.sql');
        }
    }

    public function test_mysql_dump_preparation_preserves_drop_text_inside_business_values(): void
    {
        $dump = $this->backupRoot.'/multiline.sql';
        $output = $this->backupRoot.'/prepared.sql';
        $value = "INSERT INTO `business` VALUES ('first line\nDROP TABLE IF EXISTS `inside_value`;\nlast line');\n";
        file_put_contents($dump, "-- native header\nDROP TABLE IF EXISTS `business`;\n".$value);
        app(MySqlRestoreGuard::class)->prepareDump($dump, $output);
        $this->assertSame("-- native header\n".$value, file_get_contents($output));
    }
}
