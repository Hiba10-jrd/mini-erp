<?php

namespace App\Services\Backup;

use Illuminate\Support\Str;
use Throwable;

class BackupManager
{
    public function __construct(private BackupWorkspace $workspace, private DatabaseBackupService $database, private BackupManifestService $manifest, private BackupOperationLogger $logger, private BackupArchiveService $archive, private BackupEncryptionService $encryption, private BackupVerificationService $verification) {}

    public function validateConfiguration(bool $requireEnabled = true): string
    {
        if ($requireEnabled && config('backup.enabled') !== true) {
            throw new BackupException('Sauvegardes désactivées.');
        }
        $disk = config('filesystems.disks.'.config('backup.disk'));
        $path = config('backup.path');
        if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local' || ($disk['serve'] ?? true) !== false || ($disk['throw'] ?? false) !== true || ($disk['visibility'] ?? null) !== 'private' || ! is_string($disk['root'] ?? null) || ! is_string($path) || ! preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $path)) {
            throw new BackupException('Configuration de sauvegarde invalide : disque local privé et chemin relatif requis.');
        }
        $root = str_replace('\\', '/', $disk['root']);
        $this->workspace->assertSafePath($root);
        if (! preg_match('~^(?:[A-Za-z]:/|/)~', $root)) {
            throw new BackupException('Le disque de sauvegarde exige un chemin absolu.');
        }
        foreach ([public_path(), storage_path('app/public'), storage_path('app/private')] as $unsafe) {
            $unsafe = strtolower(str_replace('\\', '/', $unsafe));
            if (strtolower($root) === $unsafe || str_starts_with(strtolower($root).'/', rtrim($unsafe, '/').'/')) {
                throw new BackupException('Le disque de sauvegarde doit être isolé des fichiers collectés et publics.');
            }
        }

        return rtrim($root, '/').'/'.$path;
    }

    public function create(): string
    {
        $started = now()->utc()->toIso8601String();
        $id = (string) Str::uuid();
        try {
            return $this->performCreate($id, $started);
        } catch (Throwable $exception) {
            try {
                $this->logger->record('failed', ['operation_id' => $id, 'started_at' => $started]);
            } catch (Throwable) {
            }
            throw $exception;
        }
    }

    private function performCreate(string $operationId, string $started): string
    {
        $root = $this->validateConfiguration();
        $this->encryption->validateKey();
        $this->workspace->directory($root);
        // One lock per disk, independent of BACKUP_PATH and cache driver.
        $lockPath = rtrim(config('filesystems.disks.'.config('backup.disk').'.root'), '/\\').'/.backup.lock';
        $this->workspace->assertSafePath($lockPath);
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new BackupException('Impossible de créer le verrou de sauvegarde.');
        }
        @chmod($lockPath, 0600);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new BackupException('Une sauvegarde est déjà en cours.');
        }
        $work = null;
        $part = $final = null;
        $published = false;
        try {
            $work = $this->workspace->create($root);
            $driver = $this->database->dump($work);
            $this->workspace->directory($work.'/database');
            $dump = $driver === 'sqlite' ? 'database.sqlite' : 'database.sql';
            if (! rename($work.'/'.$dump, $work.'/database/'.$dump)) {
                throw new BackupException('Impossible de préparer le dump pour l’archive.');
            }
            foreach (['private', 'public'] as $source) {
                $this->workspace->collect(storage_path('app/'.$source), $work.'/storage/'.$source);
            }
            $manifest = $this->manifest->write($work, $driver);
            $plain = $this->archive->create($work, $manifest);
            $date = str_replace([':', '-'], ['', ''], substr($manifest['created_at'], 11));
            $filename = 'backup-'.substr($manifest['created_at'], 0, 10).'T'.$date.'-'.$manifest['backup_id'].'.erpbackup';
            $final = $root.'/'.$filename;
            if (file_exists($final) || file_exists($final.'.part')) {
                throw new BackupException('Nom de sauvegarde déjà utilisé.');
            }
            $part = $final.'.part';
            $this->encryption->encrypt($plain, $part, $manifest);
            $this->verification->verify($part, $root);
            if (! rename($part, $final)) {
                throw new BackupException('Publication atomique de la sauvegarde impossible.');
            }
            $published = true;
            $this->workspace->cleanup($work);
            $work = null;
            $this->logger->record('succeeded', ['operation_id' => $operationId, 'started_at' => $started, 'backup_id' => $manifest['backup_id'], 'target' => basename($final)]);

            // Retention failure never rolls back a successfully published backup.
            try {
                $retentionStarted = now()->utc()->toIso8601String();
                $result = app(BackupRetentionService::class)->afterSuccessfulBackup($final, $root);
                $this->logger->record('succeeded', ['operation' => 'retention', 'started_at' => $retentionStarted, 'backup_id' => $manifest['backup_id'], 'warnings' => isset($result['kept']['*']) ? ['Restore in progress; retention skipped.'] : []]);
            } catch (Throwable) {
                try {
                    $this->logger->record('retention_failed', ['operation' => 'retention', 'backup_id' => $manifest['backup_id'], 'warnings' => ['Retention failed; published backup retained.']]);
                } catch (Throwable) {
                }
            }

            return $final;
        } catch (Throwable $exception) {
            $cleanupFailed = false;
            foreach ([$part, $published ? $final : null] as $temporary) {
                if ($temporary !== null && is_file($temporary) && ! @unlink($temporary)) {
                    $cleanupFailed = true;
                }
            }
            if ($work !== null) {
                try {
                    $this->workspace->cleanup($work);
                } catch (Throwable) {
                    $cleanupFailed = true;
                }
            }
            if ($cleanupFailed) {
                throw new BackupException('Impossible de nettoyer les temporaires de sauvegarde.');
            }
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function resolveBackup(string $backup): string
    {
        $root = $this->validateConfiguration(false);
        $this->workspace->assertSafePath($backup);
        $candidate = preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $backup) ? $backup : $root.'/'.$backup;
        $this->workspace->assertSafePath($candidate);
        $resolvedRoot = realpath($root);
        $resolved = realpath($candidate);
        if ($resolvedRoot === false || $resolved === false || ! is_file($resolved) || strtolower(str_replace('\\', '/', dirname($resolved))) !== strtolower(str_replace('\\', '/', $resolvedRoot)) || ! str_ends_with($resolved, '.erpbackup')) {
            throw new BackupException('Archive introuvable ou située hors du disque de sauvegarde.');
        }

        return $resolved;
    }
}
