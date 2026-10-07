<?php

namespace App\Services\Backup;

use ZipArchive;

class FileRestoreService
{
    public function __construct(private RestoreTargetGuard $targets, private BackupWorkspace $workspace, private BackupArchiveService $archive) {}

    private function validateEntries(ZipArchive $zip, array $manifest): void
    {
        $entries = $this->archive->entries($zip);
        $expected = array_merge(['manifest.json'], array_keys($manifest['files']));
        if (count($entries) !== count($expected) || array_diff(array_keys($entries), $expected) !== []) {
            throw new BackupException('Entrées ZIP incompatibles avec le manifeste.');
        }
        foreach ($manifest['files'] as $name => $descriptor) {
            $this->archive->assertEntryName($name);
            if (! isset($entries[$name]) || $entries[$name]['size'] !== $descriptor['size']) {
                throw new BackupException('Taille de fichier incorrecte.');
            }
            if (str_starts_with($name, 'storage/') && (preg_match('/\.erpbackup(?:\.part)?$/i', $name) || in_array(basename($name), ['.env', 'restore-client.cnf', 'restore-import.sql', 'payload.zip'], true))) {
                throw new BackupException('Archive ou fichier de configuration interdit dans la cible storage.');
            }
        }
    }

    public function extractDump(ZipArchive $zip, array $manifest, string $workspace): string
    {
        $this->validateEntries($zip, $manifest);
        $name = $manifest['database']['dump']['path'];
        $destination = $workspace.'/'.basename($name);
        $this->copyEntry($zip, $name, $destination, $manifest['files'][$name]);

        return $destination;
    }

    public function restore(ZipArchive $zip, array $manifest, string $target): void
    {
        $this->validateEntries($zip, $manifest);
        $target = $this->targets->validateStorage($target);
        $this->workspace->directory($target);
        foreach (['private', 'public'] as $type) {
            $this->workspace->directory($target.'/'.$type);
        }
        foreach ($manifest['files'] as $name => $descriptor) {
            if (! str_starts_with($name, 'storage/')) {
                continue;
            }
            $destination = $target.'/'.substr($name, strlen('storage/'));
            $this->workspace->assertSafePath($destination);
            $this->workspace->directory(dirname($destination));
            $this->copyEntry($zip, $name, $destination, $descriptor);
        }
    }

    private function copyEntry(ZipArchive $zip, string $name, string $destination, array $descriptor): void
    {
        $this->workspace->assertSafePath($destination);
        $input = $zip->getStream($name);
        $output = @fopen($destination, 'xb');
        $created = is_resource($output);
        $success = false;
        try {
            if (! is_resource($input) || ! $created) {
                throw new BackupException('Copie exclusive de fichier impossible ; écrasement interdit.');
            }
            @chmod($destination, 0600);
            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($input)) {
                $chunk = fread($input, BackupEncryptionService::CHUNK_BYTES);
                if ($chunk === false || ($chunk === '' && ! feof($input))) {
                    throw new BackupException('Lecture ZIP impossible.');
                }
                $size += strlen($chunk);
                if ($size > $descriptor['size']) {
                    throw new BackupException('Taille de fichier incorrecte.');
                }
                hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new BackupException('Écriture de fichier restauré impossible.');
                    }
                    $offset += $written;
                }
            }
            if ($size !== $descriptor['size'] || ! hash_equals($descriptor['sha256'], hash_final($hash))) {
                throw new BackupException('Intégrité du fichier restauré incorrecte.');
            }
            if (! fflush($output) || ! fsync($output)) {
                throw new BackupException('Écriture de fichier restauré impossible.');
            }
            $success = true;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            if ($created && ! $success && is_file($destination) && ! @unlink($destination)) {
                throw new BackupException('Nettoyage du fichier restauré incomplet impossible.');
            }
        }
    }

    public function checkConsistency(string $target, array $manifest): void
    {
        $target = $this->targets->canonicalPath($target);
        $expected = [];
        foreach ($manifest['files'] as $name => $descriptor) {
            if (str_starts_with($name, 'storage/')) {
                $expected[substr($name, strlen('storage/'))] = $descriptor;
            }
        }
        $actual = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                throw new BackupException('Symlink ou fichier spécial dans la cible restaurée.');
            }
            $this->workspace->assertSafePath($file->getPathname());
            $name = str_replace('\\', '/', substr($file->getPathname(), strlen($target) + 1));
            if (! isset($expected[$name]) || $file->getSize() !== $expected[$name]['size'] || ! hash_equals($expected[$name]['sha256'], hash_file('sha256', $file->getPathname()))) {
                throw new BackupException('Fichiers restaurés incompatibles avec le manifeste.');
            }
            $actual[] = $name;
        }
        if (count($actual) !== count($expected)) {
            throw new BackupException('Fichiers restaurés manquants.');
        }
    }
}
