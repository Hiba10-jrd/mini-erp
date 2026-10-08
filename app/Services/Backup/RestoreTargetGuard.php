<?php

namespace App\Services\Backup;

use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Throwable;

class RestoreTargetGuard
{
    public function __construct(private BackupWorkspace $workspace) {}

    public function canonicalPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $this->workspace->assertSafePath($path);
        if (! preg_match('~^(?:[A-Za-z]:/|/(?!/))~', $path) || preg_match('/[\x00-\x1f\x7f<>"|?*]/', $path) || str_contains(preg_replace('~^[A-Za-z]:~', '', $path), ':')) {
            throw new BackupException('La cible exige un chemin absolu local sûr.');
        }
        foreach (explode('/', preg_replace('~^[A-Za-z]:~', '', $path)) as $component) {
            if ($component !== '' && (rtrim($component, '. ') !== $component || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $component))) {
                throw new BackupException('Chemin cible interdit.');
            }
        }
        $missing = [];
        $cursor = rtrim($path, '/');
        while (! file_exists($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor || $cursor === '') {
                throw new BackupException('Parent de cible inaccessible.');
            }
            array_unshift($missing, basename($cursor));
            $cursor = $parent;
        }
        $resolved = realpath($cursor);
        if ($resolved === false || ($missing !== [] && ! is_dir($cursor))) {
            throw new BackupException('Parent de cible inaccessible.');
        }

        return rtrim(str_replace('\\', '/', $resolved), '/').($missing === [] ? '' : '/'.implode('/', $missing));
    }

    public function overlaps(string $first, string $second): bool
    {
        $first = rtrim(str_replace('\\', '/', $first), '/');
        $second = rtrim(str_replace('\\', '/', $second), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $first = strtolower($first);
            $second = strtolower($second);
        }

        return $first === $second || str_starts_with($first.'/', $second.'/') || str_starts_with($second.'/', $first.'/');
    }

    private function protectedRoots(): array
    {
        $roots = [public_path(), storage_path('app'), storage_path('framework'), storage_path('logs')];
        foreach (config('filesystems.disks', []) as $disk) {
            if (($disk['driver'] ?? null) === 'local' && is_string($disk['root'] ?? null)) {
                $roots[] = $disk['root'];
            }
        }

        return array_unique($roots);
    }

    private function assertIsolatedPath(string $path): void
    {
        if ($path === $this->canonicalPath(storage_path()) || $path === $this->canonicalPath(base_path())) {
            throw new BackupException('Cible utilisée par l’application interdite.');
        }
        foreach ($this->protectedRoots() as $root) {
            if ($this->overlaps($path, $this->canonicalPath($root))) {
                throw new BackupException('Cible située dans une racine active, publique ou de sauvegarde.');
            }
        }
        foreach ($this->configuredDatabases() as $config) {
            if (($config['driver'] ?? null) === 'sqlite' && is_string($config['database'] ?? null) && $config['database'] !== ':memory:') {
                $database = $config['database'];
                if (! preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $database)) {
                    $database = base_path($database);
                }
                if ($this->overlaps($path, $this->canonicalPath($database))) {
                    throw new BackupException('Cible utilisée par une base configurée de l’application.');
                }
            }
        }
    }

    public function validateStorage(string $target): string
    {
        $target = $this->canonicalPath($target);
        $this->assertIsolatedPath($target);
        if (file_exists($target) && (! is_dir($target) || (new \FilesystemIterator($target, \FilesystemIterator::SKIP_DOTS))->valid())) {
            throw new BackupException('La cible storage doit être nouvelle ou vide.');
        }

        return $target;
    }

    public function validateDatabase(string $target, bool $requireNew = true): array
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $target)) {
            foreach ($this->configuredDatabases() as $config) {
                if (strcasecmp((string) ($config['database'] ?? ''), $target) === 0) {
                    throw new BackupException('La base cible correspond à une base active ou configurée de l’application.');
                }
            }
            if (in_array(strtolower($target), ['mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
                throw new BackupException('Base système interdite comme cible.');
            }

            return ['driver' => config('backup.restore.mysql.driver', 'mysql'), 'target' => $target];
        }
        $target = $this->canonicalPath($target);
        $this->assertIsolatedPath($target);
        if ($requireNew && (file_exists($target) || is_link($target))) {
            throw new BackupException('Le fichier SQLite cible doit être nouveau.');
        }
        if (! $requireNew && ! is_file($target)) {
            throw new BackupException('Fichier SQLite restauré inaccessible.');
        }

        return ['driver' => 'sqlite', 'target' => $target];
    }

    private function configuredDatabases(): array
    {
        try {
            $parser = new ConfigurationUrlParser;
            $connections = array_map(fn ($config) => $parser->parseConfiguration($config), config('database.connections', []));
            foreach ($connections as $config) {
                foreach (['read', 'write'] as $role) {
                    if (! is_array($config[$role] ?? null)) {
                        continue;
                    }
                    $variants = array_is_list($config[$role]) ? $config[$role] : [$config[$role]];
                    foreach ($variants as $variant) {
                        if (is_array($variant)) {
                            $connections[] = $parser->parseConfiguration(array_merge($config, $variant));
                        }
                    }
                }
            }
            // Also protect any already-resolved connection with runtime overrides.
            foreach (DB::getConnections() as $connection) {
                $connections[] = $connection->getConfig();
            }

            return $connections;
        } catch (Throwable) {
            throw new BackupException('Impossible de vérifier les bases actives configurées.');
        }
    }
}
