<?php

namespace App\Services\Backup;

use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class BackupManifestService
{
    public function __construct(private DatabaseBackupService $database, private BackupArchiveService $archive) {}

    public function write(string $workspace, string $driver): array
    {
        $files = [];
        $total = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                throw new BackupException('Fichier de sauvegarde invalide.');
            }
            $name = str_replace('\\', '/', substr($file->getPathname(), strlen($workspace) + 1));
            $this->archive->assertEntryName($name);
            if ($name === 'manifest.json') {
                throw new BackupException('Manifeste déjà présent dans le workspace.');
            }
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                throw new BackupException('Impossible de calculer une empreinte de fichier.');
            }
            $files[$name] = ['size' => $file->getSize(), 'sha256' => $hash];
            $total += $file->getSize();
            if (count($files) > BackupArchiveService::MAX_ENTRIES || $total > BackupEncryptionService::MAX_PLAINTEXT_BYTES) {
                throw new BackupException('Manifeste ou sauvegarde supérieur aux limites autorisées.');
            }
        }
        ksort($files);
        $dumpPath = $driver === 'sqlite' ? 'database/database.sqlite' : 'database/database.sql';
        if (! isset($files[$dumpPath]) || $files[$dumpPath]['size'] === 0) {
            throw new BackupException('Dump de base de données absent ou vide.');
        }
        $metadata = $this->database->metadata($driver);
        $manifest = [
            'backup_id' => (string) Str::uuid(),
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'format_version' => BackupEncryptionService::FORMAT_VERSION,
            'key_id' => config('backup.key_id'),
            'laravel_version' => Application::VERSION,
            'php_version' => PHP_VERSION,
            'database' => [
                'driver' => $driver,
                'version' => $metadata['version'],
                'name' => $metadata['name'],
                'dump' => ['path' => $dumpPath] + $files[$dumpPath],
            ],
            'migrations' => $metadata['migrations'],
            'files' => $files,
            'files_count' => count($files),
            'total_size' => $total,
            'git_commit' => $this->gitCommit(),
        ];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > BackupArchiveService::MAX_MANIFEST_BYTES || count($files) > BackupArchiveService::MAX_ENTRIES || $total > BackupEncryptionService::MAX_PLAINTEXT_BYTES) {
            throw new BackupException('Manifeste ou sauvegarde supérieur aux limites autorisées.');
        }
        if (file_put_contents($workspace.'/manifest.json', $json) === false) {
            throw new BackupException('Impossible de créer le manifeste.');
        }
        @chmod($workspace.'/manifest.json', 0600);

        return $manifest;
    }

    private function gitCommit(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', '--verify', 'HEAD'], base_path(), null, null, 3);
            $process->run();
            $commit = trim($process->getOutput());

            return $process->isSuccessful() && preg_match('/^[a-f0-9]{40,64}$/D', $commit) ? $commit : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function validate(mixed $manifest, array $header): array
    {
        if (! is_array($manifest) || ($manifest['format_version'] ?? null) !== BackupEncryptionService::FORMAT_VERSION || ($manifest['backup_id'] ?? null) !== $header['backup_id'] || ($manifest['created_at'] ?? null) !== $header['created_at'] || ($manifest['key_id'] ?? null) !== $header['key_id']) {
            throw new BackupException('Manifeste invalide ou incompatible avec le header.');
        }
        if (! is_string($manifest['laravel_version'] ?? null) || ! is_string($manifest['php_version'] ?? null) || ! array_key_exists('git_commit', $manifest) || ($manifest['git_commit'] !== null && (! is_string($manifest['git_commit']) || ! preg_match('/^[a-f0-9]{40,64}$/D', $manifest['git_commit']))) || ! is_array($manifest['migrations'] ?? null) || ! is_array($manifest['files'] ?? null) || ! is_int($manifest['files_count'] ?? null) || ! is_int($manifest['total_size'] ?? null)) {
            throw new BackupException('Structure du manifeste invalide.');
        }
        $migrations = $manifest['migrations'];
        foreach (['available', 'applied'] as $type) {
            if (! array_key_exists($type, $migrations) || ($type === 'available' && ! is_array($migrations[$type])) || ($migrations[$type] !== null && ! is_array($migrations[$type]))) {
                throw new BackupException('Migrations du manifeste invalides.');
            }
            foreach ($migrations[$type] ?? [] as $migration) {
                if (! is_string($migration) || ! preg_match('/^[A-Za-z0-9_-]+$/D', $migration)) {
                    throw new BackupException('Migrations du manifeste invalides.');
                }
            }
        }
        $database = $manifest['database'] ?? null;
        if (! is_array($database) || ! in_array($database['driver'] ?? null, ['sqlite', 'mysql', 'mariadb'], true) || ! array_key_exists('version', $database) || ($database['version'] !== null && ! is_string($database['version'])) || ! array_key_exists('name', $database) || ($database['name'] !== null && ! is_string($database['name'])) || ! is_array($database['dump'] ?? null)) {
            throw new BackupException('Métadonnées de base de données invalides.');
        }
        $files = $manifest['files'];
        if (count($files) > BackupArchiveService::MAX_ENTRIES || count($files) !== $manifest['files_count'] || $manifest['total_size'] < 0 || $manifest['total_size'] > BackupEncryptionService::MAX_PLAINTEXT_BYTES) {
            throw new BackupException('Nombre de fichiers ou taille totale invalide.');
        }
        $total = 0;
        $seen = [];
        foreach ($files as $name => $file) {
            if (! is_string($name)) {
                throw new BackupException('Chemin du manifeste invalide.');
            }
            $this->archive->assertEntryName($name);
            if ($name === 'manifest.json' || isset($seen[strtolower($name)]) || ! is_array($file) || count($file) !== 2 || ! is_int($file['size'] ?? null) || $file['size'] < 0 || $file['size'] > BackupEncryptionService::MAX_PLAINTEXT_BYTES || ! is_string($file['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])) {
                throw new BackupException('Descripteur de fichier du manifeste invalide.');
            }
            $seen[strtolower($name)] = true;
            $total += $file['size'];
        }
        if ($total !== $manifest['total_size']) {
            throw new BackupException('Taille totale du manifeste incorrecte.');
        }
        $expectedDump = $database['driver'] === 'sqlite' ? 'database/database.sqlite' : 'database/database.sql';
        $dump = $database['dump'];
        if (($dump['path'] ?? null) !== $expectedDump || ! isset($files[$expectedDump]) || $files[$expectedDump]['size'] === 0 || ($dump['sha256'] ?? null) !== $files[$expectedDump]['sha256'] || ($dump['size'] ?? null) !== $files[$expectedDump]['size'] || count(array_filter(array_keys($files), fn ($name) => str_starts_with($name, 'database/'))) !== 1) {
            throw new BackupException('Dump du manifeste invalide.');
        }

        return $manifest;
    }
}
