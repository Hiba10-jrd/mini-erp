<?php

namespace App\Services\Backup;

use Throwable;
use ZipArchive;

class BackupVerificationService
{
    public function __construct(private BackupWorkspace $workspace, private BackupEncryptionService $encryption, private BackupArchiveService $archive, private BackupManifestService $manifest) {}

    public function verify(string $path, string $temporaryRoot): array
    {
        $work = $this->workspace->create($temporaryRoot);
        try {
            $plain = $work.'/payload.zip';
            $header = $this->encryption->decrypt($path, $plain);
            $zip = $this->archive->open($plain);
            try {
                return $this->verifyZip($zip, $header);
            } finally {
                $zip->close();
            }
        } catch (BackupException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BackupException('Vérification de l’archive impossible.');
        } finally {
            $this->workspace->cleanup($work);
        }
    }

    public function verifyZip(ZipArchive $zip, array $header): array
    {
        $entries = $this->archive->entries($zip);
        if (! isset($entries['manifest.json'])) {
            throw new BackupException('Manifeste absent de l’archive.');
        }
        if ($entries['manifest.json']['size'] > BackupArchiveService::MAX_MANIFEST_BYTES) {
            throw new BackupException('Manifeste supérieur à la limite autorisée.');
        }
        $stream = $zip->getStream('manifest.json');
        if (! is_resource($stream)) {
            throw new BackupException('Manifeste illisible.');
        }
        try {
            $json = stream_get_contents($stream, BackupArchiveService::MAX_MANIFEST_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if ($json === false || strlen($json) !== $entries['manifest.json']['size'] || strlen($json) > BackupArchiveService::MAX_MANIFEST_BYTES) {
            throw new BackupException('Taille du manifeste incorrecte.');
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new BackupException('JSON du manifeste invalide.');
        }
        $manifest = $this->manifest->validate($decoded, $header);
        if (count($entries) !== count($manifest['files']) + 1 || array_diff(array_keys($entries), array_merge(['manifest.json'], array_keys($manifest['files']))) !== []) {
            throw new BackupException('Entrées ZIP incompatibles avec le manifeste.');
        }
        foreach ($manifest['files'] as $name => $file) {
            if (! isset($entries[$name]) || $entries[$name]['size'] !== $file['size']) {
                throw new BackupException('Taille de fichier incorrecte.');
            }
            $stream = $zip->getStream($name);
            if (! is_resource($stream)) {
                throw new BackupException('Fichier ZIP illisible.');
            }
            try {
                $hash = hash_init('sha256');
                $size = 0;
                while (! feof($stream)) {
                    $chunk = fread($stream, BackupEncryptionService::CHUNK_BYTES);
                    if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                        throw new BackupException('Fichier ZIP illisible.');
                    }
                    $size += strlen($chunk);
                    if ($size > $file['size']) {
                        throw new BackupException('Taille de fichier incorrecte.');
                    }
                    hash_update($hash, $chunk);
                }
                if ($size !== $file['size']) {
                    throw new BackupException('Taille de fichier incorrecte.');
                }
                if (! hash_equals($file['sha256'], hash_final($hash))) {
                    throw new BackupException('Empreinte SHA-256 incorrecte.');
                }
            } finally {
                fclose($stream);
            }
        }

        return $manifest;
    }
}
