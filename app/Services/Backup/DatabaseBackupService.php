<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupService
{
    public function findDumpBinary(string $driver): ?string
    {
        $finder = new ExecutableFinder;
        foreach ($driver === 'mariadb' ? ['mariadb-dump', 'mysqldump'] : ['mysqldump', 'mariadb-dump'] as $name) {
            if ($binary = $finder->find($name)) {
                return $binary;
            }
        }

        return null;
    }

    protected function runProcess(array $arguments, array $environment): Process
    {
        $process = new Process($arguments, null, $environment, null, 300);
        $process->disableOutput();
        $process->run();

        return $process;
    }

    public function metadata(string $driver): array
    {
        $available = array_map(fn ($path) => pathinfo($path, PATHINFO_FILENAME), glob(database_path('migrations/*.php')) ?: []);
        sort($available);
        $metadata = ['version' => null, 'name' => null, 'migrations' => ['available' => $available, 'applied' => null]];
        try {
            $connection = DB::connection();
            $config = $connection->getConfig();
            if (($config['driver'] ?? null) !== $driver) {
                return $metadata;
            }
            $name = (string) ($config['database'] ?? '');
            $metadata['name'] = $driver === 'sqlite' ? basename(str_replace('\\', '/', $name)) : $name;
            $metadata['version'] = $driver === 'sqlite'
                ? (string) $connection->selectOne('SELECT sqlite_version() AS version')->version
                : (string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $metadata['migrations']['applied'] = $connection->getSchemaBuilder()->hasTable('migrations')
                ? $connection->table('migrations')->orderBy('migration')->pluck('migration')->all()
                : [];
        } catch (Throwable) {
            // Optional metadata must never leak connection errors or credentials.
        }

        return $metadata;
    }

    public function dump(string $workspace): string
    {
        // Resolve Laravel's parsed connection config, including DB_URL overrides.
        try {
            $connection = DB::connection();
            $config = $connection->getConfig();
        } catch (Throwable) {
            throw new BackupException('Configuration de base de données invalide.');
        }
        $driver = $config['driver'] ?? '';
        $target = $workspace.'/database.sql';
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $binary = $this->findDumpBinary($driver);
            if (! $binary) {
                throw new BackupException('Prérequis manquant : mysqldump ou mariadb-dump introuvable dans le PATH.');
            }
            if (empty($config['database']) || empty($config['username'])) {
                throw new BackupException('Configuration de base de données invalide.');
            }
            $arguments = [$binary, '--no-defaults', '--single-transaction', '--quick', '--skip-lock-tables', '--host='.($config['host'] ?? '127.0.0.1'), '--port='.($config['port'] ?? 3306), '--user='.$config['username'], '--result-file='.$target];
            if (! empty($config['unix_socket'])) {
                $arguments[] = '--socket='.$config['unix_socket'];
            }
            $arguments[] = '--';
            $arguments[] = $config['database'];
            try {
                $process = $this->runProcess($arguments, ['MYSQL_PWD' => (string) ($config['password'] ?? '')]);
                if (! $process->isSuccessful() || ! is_file($target) || filesize($target) === 0) {
                    throw new BackupException;
                }
            } catch (Throwable) {
                @unlink($target);
                // Never propagate process output, command line or connection secrets.
                throw new BackupException('Échec du dump de la base de données.');
            }
            chmod($target, 0600);

            return $driver;
        }
        if ($driver === 'sqlite') {
            $source = $config['database'] ?? '';
            if ($source === ':memory:' || ! is_file($source)) {
                throw new BackupException('Prérequis manquant : fichier SQLite persistant requis.');
            }
            (new BackupWorkspace)->assertSafePath($source);
            try {
                $connection->getPdo()->exec('VACUUM INTO '.$connection->getPdo()->quote($workspace.'/database.sqlite'));
                chmod($workspace.'/database.sqlite', 0600);
            } catch (Throwable) {
                throw new BackupException('Échec du dump de la base de données.');
            }

            return $driver;
        }
        throw new BackupException('Driver de base de données non pris en charge.');
    }
}
