<?php

namespace App\Services\Backup;

use ZipArchive;

class BackupArchiveService
{
    public const MAX_ENTRIES = 100000;

    public const MAX_MANIFEST_BYTES = 16777216;

    public function assertEntryName(string $name): void
    {
        if ($name === '' || preg_match('/[\\\\:\x00-\x1f\x7f]/', $name) || str_starts_with($name, '/') || str_ends_with($name, '/')) {
            throw new BackupException('Chemin interne d’archive interdit.');
        }
        foreach (explode('/', $name) as $component) {
            if ($component === '' || $component === '.' || $component === '..' || rtrim($component, '. ') !== $component || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $component)) {
                throw new BackupException('Chemin interne d’archive interdit.');
            }
        }
        if ($name !== 'manifest.json' && ! in_array($name, ['database/database.sql', 'database/database.sqlite'], true) && ! str_starts_with($name, 'storage/private/') && ! str_starts_with($name, 'storage/public/')) {
            throw new BackupException('Entrée d’archive inattendue.');
        }
    }

    public function create(string $workspace, array $manifest): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new BackupException('Extension ZipArchive requise.');
        }
        $path = $workspace.'/payload.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new BackupException('Impossible de créer l’archive ZIP.');
        }
        try {
            $names = array_merge(['manifest.json'], array_keys($manifest['files']));
            if (count($names) > self::MAX_ENTRIES + 1) {
                throw new BackupException('Trop de fichiers dans la sauvegarde.');
            }
            $seen = [];
            foreach ($names as $name) {
                $this->assertEntryName($name);
                $lower = strtolower($name);
                if (isset($seen[$lower])) {
                    throw new BackupException('Entrée d’archive dupliquée.');
                }
                $seen[$lower] = true;
                $source = $workspace.'/'.$name;
                (new BackupWorkspace)->assertSafePath($source);
                if (! is_file($source) || ! $zip->addFile($source, $name) || ! $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100600 << 16)) {
                    throw new BackupException('Impossible d’ajouter un fichier à l’archive ZIP.');
                }
            }
        } finally {
            $closed = $zip->close();
        }
        if (! $closed) {
            throw new BackupException('Impossible de finaliser l’archive ZIP.');
        }
        @chmod($path, 0600);

        return $path;
    }

    public function open(string $path): ZipArchive
    {
        if (! class_exists(ZipArchive::class)) {
            throw new BackupException('Extension ZipArchive requise.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new BackupException('Archive ZIP invalide.');
        }

        return $zip;
    }

    public function entries(ZipArchive $zip): array
    {
        if ($zip->numFiles < 2 || $zip->numFiles > self::MAX_ENTRIES + 1) {
            throw new BackupException('Nombre d’entrées ZIP invalide.');
        }
        $entries = $seen = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                throw new BackupException('Entrée ZIP invalide.');
            }
            $name = $stat['name'];
            $this->assertEntryName($name);
            if (isset($seen[strtolower($name)])) {
                throw new BackupException('Entrée d’archive dupliquée.');
            }
            $seen[strtolower($name)] = true;
            $opsys = $attributes = 0;
            if (! $zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                throw new BackupException('Attributs ZIP illisibles.');
            }
            $type = ($attributes >> 16) & 0170000;
            if (($opsys === ZipArchive::OPSYS_UNIX && $type !== 0 && $type !== 0100000) || ($attributes & 0x10) !== 0 || ($stat['encryption_method'] ?? 0) !== ZipArchive::EM_NONE) {
                throw new BackupException('Symlink ou type d’entrée ZIP interdit.');
            }
            $entries[$name] = $stat;
        }

        return $entries;
    }
}
