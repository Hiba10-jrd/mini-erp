<?php

namespace App\Services\Backup;

use Illuminate\Filesystem\Filesystem;

class BackupWorkspace
{
    public function assertSafePath(string $path): void
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_contains($path, "\0") || preg_match('~(^|/)\.{1,2}(/|$)~', $path)) {
            throw new BackupException('Chemin de sauvegarde invalide.');
        }
        $cursor = $path;
        while ($cursor !== '.' && dirname($cursor) !== $cursor) {
            if (is_link($cursor)) {
                throw new BackupException('Lien symbolique interdit dans la sauvegarde.');
            }
            $cursor = dirname($cursor);
        }
    }

    public function create(string $root): string
    {
        $this->assertSafePath($root);
        $path = $root.'/work-'.bin2hex(random_bytes(16));
        $this->directory($path);

        return $path;
    }

    public function directory(string $path): void
    {
        $this->assertSafePath($path);
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new BackupException('Impossible de créer le workspace privé.');
        }
        @chmod($path, 0700);
    }

    public function collect(string $source, string $destination): void
    {
        $this->assertSafePath($source);
        if (! is_dir($source)) {
            return;
        }
        $this->directory($destination);
        foreach (new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isLink()) {
                throw new BackupException('Lien symbolique interdit dans la sauvegarde.');
            }
            if (in_array(strtolower($entry->getFilename()), ['cache', 'caches', 'log', 'logs', 'temp', 'tmp', '.tmp'], true)) {
                continue;
            }
            $target = $destination.'/'.$entry->getFilename();
            if ($entry->isDir()) {
                $this->collect($entry->getPathname(), $target);
            } elseif ($entry->isFile()) {
                if (! copy($entry->getPathname(), $target)) {
                    throw new BackupException('Impossible de collecter un fichier.');
                }
                @chmod($target, 0600);
            } else {
                throw new BackupException('Type de fichier interdit dans la sauvegarde.');
            }
        }
    }

    public function cleanup(string $path): void
    {
        $this->assertSafePath($path);
        if (is_dir($path) && ! (new Filesystem)->deleteDirectory($path)) {
            throw new BackupException('Impossible de nettoyer le workspace.');
        }
    }
}
