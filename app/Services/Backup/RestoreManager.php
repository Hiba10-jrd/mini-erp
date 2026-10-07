<?php

namespace App\Services\Backup;

use Illuminate\Foundation\Application;
use Throwable;

class RestoreManager
{
    public function __construct(private BackupManager $backups, private BackupWorkspace $workspace, private BackupEncryptionService $encryption, private BackupVerificationService $verification, private BackupArchiveService $archive, private RestoreTargetGuard $targets, private DatabaseRestoreService $database, private FileRestoreService $files, private BackupOperationLogger $logger) {}

    public function prepare(string $backup, string $targetDatabase, string $targetStorage, string $confirmation): array
    {
        foreach (['target-database' => $targetDatabase, 'target-storage' => $targetStorage, 'confirm' => $confirmation] as $option => $value) {
            if ($value === '') {
                throw new BackupException('Paramètre --'.$option.' obligatoire.');
            }
        }
        $storage = $this->targets->validateStorage($targetStorage);
        $database = $this->targets->validateDatabase($targetDatabase);
        if ($database['driver'] === 'sqlite' && $this->targets->overlaps($storage, $database['target'])) {
            throw new BackupException('Les cibles database et storage doivent être séparées.');
        }
        $path = $this->backups->resolveBackup($backup);
        $header = $this->encryption->readHeader($path);
        if (! hash_equals($header['backup_id'], $confirmation)) {
            throw new BackupException('--confirm doit correspondre exactement au backup_id.');
        }
        $root = $this->backups->validateConfiguration(false);
        $manifest = $this->verification->verify($path, $root);
        $this->database->assertCompatible($manifest['database']['driver'], $database['driver']);
        $this->database->validateTarget($database['target'], $database['driver']);
        $warnings = [];
        if ($manifest['php_version'] !== PHP_VERSION) {
            $warnings[] = 'Version PHP différente de celle du backup (informatif).';
        }
        if ($manifest['laravel_version'] !== Application::VERSION) {
            $warnings[] = 'Version Laravel différente de celle du backup (informatif).';
        }
        $available = array_map(fn ($path) => pathinfo($path, PATHINFO_FILENAME), glob(database_path('migrations/*.php')) ?: []);
        sort($available);
        $source = $manifest['migrations']['available'];
        sort($source);
        if ($available !== $source) {
            $warnings[] = 'Migrations disponibles différentes ; les migrations appliquées seront contrôlées dans la cible.';
        }

        return ['path' => $path, 'root' => $root, 'manifest' => $manifest, 'database' => $database['target'], 'driver' => $database['driver'], 'storage' => $storage, 'warnings' => $warnings];
    }

    public function restore(string $backup, string $targetDatabase, string $targetStorage, string $confirmation, ?callable $approve = null): RestoreReport
    {
        $report = new RestoreReport;
        $work = $lock = $zip = $pdo = null;
        $databaseStarted = false;
        $databaseRestored = false;
        $stage = 'verification_status';
        try {
            // Protect the selected archive throughout preparation and the confirmation prompt.
            $this->backups->validateConfiguration(false);
            $diskRoot = config('filesystems.disks.'.config('backup.disk').'.root');
            $lockPath = rtrim($diskRoot, '/\\').'/.restore.lock';
            $this->workspace->assertSafePath($lockPath);
            $lock = @fopen($lockPath, 'c');
            if (! is_resource($lock) || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new BackupException('Une restauration est déjà en cours ou le verrou est inaccessible.');
            }
            @chmod($lockPath, 0600);
            $plan = $this->prepare($backup, $targetDatabase, $targetStorage, $confirmation);
            $report->set('backup_id', $plan['manifest']['backup_id']);
            $report->set('target_database', $plan['database']);
            $report->set('target_storage', $plan['storage']);
            $report->set('warnings', $plan['warnings']);
            $report->set('verification_status', 'VALID');
            if ($approve !== null && $approve($plan) !== true) {
                $report->finish('CANCELLED', 'Confirmation interactive refusée.');

                return $report;
            }
            $this->targets->validateStorage($plan['storage']);
            $this->database->validateTarget($plan['database'], $plan['driver']);
            $work = $this->workspace->create($plan['root']);
            $header = $this->encryption->decrypt($plan['path'], $work.'/payload.zip');
            $zip = $this->archive->open($work.'/payload.zip');
            $manifest = $this->verification->verifyZip($zip, $header);
            if ($manifest !== $plan['manifest'] || ! hash_equals($manifest['backup_id'], $confirmation)) {
                throw new BackupException('Archive modifiée depuis la confirmation.');
            }
            $dump = $this->files->extractDump($zip, $manifest, $work);
            $stage = 'database_status';
            $databaseStarted = true;
            $pdo = $this->database->restore($dump, $plan['database'], $plan['driver'], $work);
            $databaseRestored = true;
            $report->set('database_status', 'RESTORED');
            // Quarantine before copying files: even an incomplete restore remains inert.
            $stage = 'quarantine_status';
            $this->database->quarantine($pdo, $plan['driver']);
            $report->set('quarantine_status', 'NEUTRALIZED');
            $stage = 'files_status';
            $this->files->restore($zip, $manifest, $plan['storage']);
            $report->set('files_status', 'RESTORED');
            $stage = 'verification_status';
            $this->database->checkConsistency($pdo, $plan['driver'], $manifest);
            $this->files->checkConsistency($plan['storage'], $manifest);
            $report->set('verification_status', 'VALID');
            $report->finish('SUCCESS');
        } catch (Throwable $exception) {
            $report->set($stage, 'FAILED');
            $report->finish($databaseRestored ? 'INCOMPLETE' : 'FAILED', $exception instanceof BackupException ? $exception->getMessage() : 'Échec de restauration isolée.');
            if ($databaseStarted && ! $databaseRestored) {
                $report->set('warnings', array_merge($report->toArray()['warnings'], ['La cible DB peut contenir un import partiel ; aucun fichier storage n’a été restauré.']));
            }
        } finally {
            $pdo = null;
            if ($zip !== null) {
                try {
                    $zip->close();
                } catch (Throwable) {
                    $report->finish($databaseRestored ? 'INCOMPLETE' : 'FAILED', 'Fermeture de l’archive restore échouée.');
                }
            }
            try {
                if ($work !== null) {
                    $this->workspace->cleanup($work);
                }
            } catch (Throwable) {
                $report->finish($databaseRestored ? 'INCOMPLETE' : 'FAILED', 'Nettoyage du workspace restore échoué.');
            } finally {
                if (is_resource($lock)) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
            try {
                $this->logger->restore($report);
            } catch (Throwable) {
                $report->finish($databaseRestored ? 'INCOMPLETE' : 'FAILED', 'Journalisation du restore échouée.');
            }
        }

        return $report;
    }
}
