<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseRestoreService
{
    private const QUARANTINE_TABLES = ['sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    private \WeakMap $restoredConnections;

    public function __construct(private RestoreTargetGuard $targets, private BackupWorkspace $workspace, private MySqlRestoreGuard $mysqlGuard)
    {
        $this->restoredConnections = new \WeakMap;
    }

    public function assertCompatible(string $source, string $target): void
    {
        if (($source === 'sqlite' && $target === 'sqlite') || (in_array($source, ['mysql', 'mariadb'], true) && in_array($target, ['mysql', 'mariadb'], true))) {
            return;
        }
        throw new BackupException('Drivers source et cible incompatibles.');
    }

    public function findClient(string $driver): ?string
    {
        $finder = new ExecutableFinder;
        foreach ($driver === 'mariadb' ? ['mariadb', 'mysql'] : ['mysql', 'mariadb'] as $name) {
            if ($client = $finder->find($name)) {
                return $client;
            }
        }

        return null;
    }

    public function validateTarget(string $target, string $driver): void
    {
        $validated = $this->targets->validateDatabase($target);
        $this->assertCompatible($driver, $validated['driver']);
        if ($driver === 'sqlite') {
            return;
        }
        if ($this->findClient($driver) === null) {
            throw new BackupException('Prérequis manquant : client mysql ou mariadb introuvable dans le PATH.');
        }
        try {
            $pdo = $this->connectMysql($target);
            $this->assertRestrictedAccount($pdo, $target);
            if ($this->tables($pdo, $driver) !== []) {
                throw new BackupException('La base MySQL/MariaDB cible doit exister et être vide.');
            }
            $objects = $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
            $routines = $pdo->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->fetchColumn();
            $events = $pdo->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()')->fetchColumn();
            if ((int) $objects + (int) $routines + (int) $events !== 0) {
                throw new BackupException('La base MySQL/MariaDB cible doit exister et être vide.');
            }
        } catch (BackupException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BackupException('Base MySQL/MariaDB cible inaccessible ; schéma vide préexistant requis.');
        }
    }

    protected function connectMysql(string $target): PDO
    {
        $config = $this->mysqlConfig();

        return new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$target.';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
    }

    protected function assertRestrictedAccount(PDO $pdo, string $target): void
    {
        $literalUnderscores = false;
        try {
            $literalUnderscores = (bool) $pdo->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
        } catch (Throwable) {
            // MariaDB / older MySQL: underscores in database grants are wildcards.
        }
        $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
        $this->mysqlGuard->assertGrants($grants, $target, $literalUnderscores);
    }

    private function mysqlConfig(): array
    {
        $config = config('backup.restore.mysql', []);
        if (! is_string($config['username'] ?? null) || $config['username'] === '' || ! is_string($config['password'] ?? null) || ! is_string($config['host'] ?? null) || ! preg_match('/^[A-Za-z0-9.-]+$/D', $config['host']) || ! ctype_digit((string) ($config['port'] ?? '')) || (int) $config['port'] < 1 || (int) $config['port'] > 65535) {
            throw new BackupException('Identifiants dédiés BACKUP_RESTORE_DB_* requis pour MySQL/MariaDB.');
        }
        if (strtolower($config['host']) === 'localhost') {
            $config['host'] = '127.0.0.1';
        }

        return $config;
    }

    protected function runImport(array $arguments, $input, string $workspace): Process
    {
        $process = new Process($arguments, $workspace, ['MYSQL_PWD' => false, 'MYSQL_HISTFILE' => $workspace.'/no-history'], $input, 300);
        $process->disableOutput();
        $process->run();

        return $process;
    }

    public function restore(string $dump, string $target, string $driver, string $workspace): PDO
    {
        $this->validateTarget($target, $driver);
        $this->workspace->assertSafePath($dump);
        if ($driver === 'sqlite') {
            $pdo = $this->restoreSqlite($dump, $target);
            $this->restoredConnections[$pdo] = ['target' => $target, 'driver' => $driver];

            return $pdo;
        }
        $config = $this->mysqlConfig();
        $credentials = $workspace.'/restore-client.cnf';
        $filtered = $workspace.'/restore-import.sql';
        $input = null;
        try {
            $this->mysqlGuard->prepareDump($dump, $filtered);
            $file = @fopen($credentials, 'xb');
            if (! is_resource($file)) {
                throw new BackupException('Impossible de créer les identifiants temporaires privés.');
            }
            @chmod($credentials, 0600);
            try {
                $quote = fn ($value) => '"'.strtr((string) $value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']).'"';
                $text = "[client]\nuser=".$quote($config['username'])."\npassword=".$quote($config['password'])."\nhost=".$quote($config['host'])."\nport=".(int) $config['port']."\n";
                if (fwrite($file, $text) !== strlen($text)) {
                    throw new BackupException('Écriture des identifiants temporaires impossible.');
                }
            } finally {
                fclose($file);
            }
            $client = $this->findClient($driver);
            if ($client === null) {
                throw new BackupException('Prérequis manquant : client mysql ou mariadb introuvable dans le PATH.');
            }
            $arguments = [$client, '--defaults-extra-file='.$credentials, '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], '--protocol=TCP', '--batch', '--binary-mode', '--local-infile=0', '--skip-reconnect', '--database='.$target];
            $input = @fopen($filtered, 'rb');
            if (! is_resource($input) || ! $this->runImport($arguments, $input, $workspace)->isSuccessful()) {
                throw new BackupException('Échec de l’import MySQL/MariaDB dans la cible isolée.');
            }
            $pdo = $this->connectMysql($target);
            $this->assertRestrictedAccount($pdo, $target);
            $this->restoredConnections[$pdo] = ['target' => $target, 'driver' => $driver];

            return $pdo;
        } catch (BackupException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BackupException('Échec de l’import MySQL/MariaDB dans la cible isolée.');
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            foreach ([$credentials, $filtered] as $temporary) {
                if (is_file($temporary) && ! @unlink($temporary)) {
                    throw new BackupException('Impossible de nettoyer les identifiants ou le dump temporaires.');
                }
            }
        }
    }

    private function restoreSqlite(string $dump, string $target): PDO
    {
        $input = $output = null;
        $created = false;
        try {
            $input = @fopen($dump, 'rb');
            if (! is_resource($input) || fread($input, 16) !== "SQLite format 3\0") {
                throw new BackupException('Dump SQLite invalide.');
            }
            rewind($input);
            $this->workspace->directory(dirname($target));
            $this->targets->validateDatabase($target);
            $output = @fopen($target, 'xb');
            $created = is_resource($output);
            if (! $created) {
                throw new BackupException('Création exclusive de la cible SQLite impossible.');
            }
            @chmod($target, 0600);
            if (stream_copy_to_stream($input, $output) === false || ! fflush($output) || ! fsync($output)) {
                throw new BackupException('Copie du dump SQLite impossible.');
            }
            fclose($output);
            $output = null;
            $pdo = new PDO('sqlite:'.$target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA trusted_schema = OFF');
            $pdo->exec('PRAGMA foreign_keys = ON');
            if ($pdo->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
                throw new BackupException('Contrôle d’intégrité SQLite échoué.');
            }

            return $pdo;
        } catch (BackupException $exception) {
            if (isset($pdo)) {
                $pdo = null;
            }
            if (is_resource($output)) {
                fclose($output);
                $output = null;
            }
            if ($created && is_file($target)) {
                @unlink($target);
            }
            throw $exception;
        } catch (Throwable) {
            if (isset($pdo)) {
                $pdo = null;
            }
            if (is_resource($output)) {
                fclose($output);
                $output = null;
            }
            if ($created && is_file($target)) {
                @unlink($target);
            }
            throw new BackupException('Échec de restauration SQLite dans la cible isolée.');
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    private function tables(PDO $pdo, string $driver): array
    {
        return $driver === 'sqlite'
            ? $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)
            : $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    }

    private function identifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function hasRememberToken(PDO $pdo, string $driver): bool
    {
        if ($driver === 'sqlite') {
            return in_array('remember_token', array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
        }

        return (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'remember_token'")->fetchColumn() > 0;
    }

    private function assertRestoredConnection(PDO $pdo, string $driver): void
    {
        if (! isset($this->restoredConnections[$pdo]) || $this->restoredConnections[$pdo]['driver'] !== $driver) {
            throw new BackupException('Connexion DB non issue d’un restore isolé validé.');
        }
        foreach (DB::getConnections() as $connection) {
            if ($connection->getRawPdo() === $pdo || $connection->getRawReadPdo() === $pdo) {
                throw new BackupException('La connexion restaurée est utilisée par l’application ; quarantaine refusée.');
            }
        }
        $this->targets->validateDatabase($this->restoredConnections[$pdo]['target'], false);
    }

    public function quarantine(PDO $pdo, string $driver): void
    {
        $this->assertRestoredConnection($pdo, $driver);
        try {
            $tables = $this->tables($pdo, $driver);
            $triggers = $driver === 'sqlite'
                ? $pdo->query("SELECT tbl_name FROM sqlite_master WHERE type = 'trigger'")->fetchAll(PDO::FETCH_COLUMN)
                : $pdo->query('SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
            if (array_intersect($triggers, array_merge(self::QUARANTINE_TABLES, ['users'])) !== []) {
                throw new BackupException('Triggers incompatibles avec la quarantaine sûre.');
            }
            // Prevent cascades from ephemeral state into retained business tables.
            if ($driver === 'sqlite') {
                foreach (array_diff($tables, self::QUARANTINE_TABLES) as $table) {
                    foreach ($pdo->query('PRAGMA foreign_key_list('.$this->identifier($table).')')->fetchAll(PDO::FETCH_ASSOC) as $foreignKey) {
                        if (in_array($foreignKey['table'], self::QUARANTINE_TABLES, true) || ($foreignKey['table'] === 'users' && $foreignKey['to'] === 'remember_token')) {
                            throw new BackupException('Relations de données métier incompatibles avec la quarantaine sûre.');
                        }
                    }
                }
            } else {
                $relations = $pdo->query('SELECT TABLE_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
                foreach ($relations as $relation) {
                    if (! in_array($relation['TABLE_NAME'], self::QUARANTINE_TABLES, true) && (in_array($relation['REFERENCED_TABLE_NAME'], self::QUARANTINE_TABLES, true) || ($relation['REFERENCED_TABLE_NAME'] === 'users' && $relation['REFERENCED_COLUMN_NAME'] === 'remember_token'))) {
                        throw new BackupException('Relations de données métier incompatibles avec la quarantaine sûre.');
                    }
                }
            }
            $pdo->beginTransaction();
            foreach (self::QUARANTINE_TABLES as $table) {
                if (in_array($table, $tables, true)) {
                    $pdo->exec('DELETE FROM '.$this->identifier($table));
                }
            }
            if (in_array('users', $tables, true) && $this->hasRememberToken($pdo, $driver)) {
                $pdo->exec('UPDATE users SET remember_token = NULL');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof BackupException) {
                throw $exception;
            }
            throw new BackupException('Neutralisation des sessions, jobs et tokens échouée.');
        }
    }

    public function checkConsistency(PDO $pdo, string $driver, array $manifest): void
    {
        $this->assertRestoredConnection($pdo, $driver);
        try {
            $pdo->query('SELECT 1')->fetchColumn();
            $tables = $this->tables($pdo, $driver);
            if ($tables === []) {
                throw new BackupException('La base restaurée ne contient aucune table.');
            }
            $applied = $manifest['migrations']['applied'];
            if ($applied !== null && $applied !== []) {
                if (! in_array('migrations', $tables, true)) {
                    throw new BackupException('Table migrations attendue absente.');
                }
                $actual = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
                sort($applied);
                if ($actual !== $applied) {
                    throw new BackupException('Migrations restaurées incompatibles avec le manifeste.');
                }
                $expectedTables = [];
                $coreMigrations = [
                    '0001_01_01_000000_create_users_table' => ['users', 'sessions', 'password_reset_tokens'],
                    '0001_01_01_000002_create_jobs_table' => ['jobs', 'job_batches', 'failed_jobs'],
                    '2026_09_24_170216_create_roles_and_permissions_tables' => ['roles', 'permissions', 'role_user', 'permission_role'],
                    '2026_09_29_130000_create_invoice_and_credit_note_tables' => ['invoices', 'invoice_items', 'credit_notes', 'credit_note_items'],
                    '2026_10_01_091604_create_payment_tables' => ['payments', 'payment_allocations'],
                    '2026_10_05_210002_create_notifications_table' => ['notifications'],
                    '2026_10_05_210003_create_operation_histories_table' => ['operation_histories'],
                ];
                foreach ($coreMigrations as $migration => $requiredTables) {
                    if (in_array($migration, $applied, true)) {
                        $expectedTables = array_merge($expectedTables, $requiredTables);
                    }
                }
                if (count($tables) < max(1, count(array_unique($expectedTables))) || array_diff($expectedTables, $tables) !== []) {
                    throw new BackupException('Tables ERP attendues absentes de la cible restaurée.');
                }
            }
            foreach (self::QUARANTINE_TABLES as $table) {
                if (in_array($table, $tables, true) && (int) $pdo->query('SELECT COUNT(*) FROM '.$this->identifier($table))->fetchColumn() !== 0) {
                    throw new BackupException('Quarantaine incomplète.');
                }
            }
            if (in_array('users', $tables, true) && $this->hasRememberToken($pdo, $driver) && (int) $pdo->query('SELECT COUNT(*) FROM users WHERE remember_token IS NOT NULL')->fetchColumn() !== 0) {
                throw new BackupException('Remember tokens non neutralisés.');
            }
            if ($driver === 'sqlite' && ($pdo->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok'] || $pdo->query('PRAGMA foreign_key_check')->fetch() !== false)) {
                throw new BackupException('Cohérence SQLite restaurée invalide.');
            }
        } catch (BackupException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BackupException('Vérification de cohérence de la base restaurée échouée.');
        }
    }
}
