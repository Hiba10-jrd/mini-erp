<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupEncryptionService;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupOperationLogger;
use App\Services\Backup\BackupVerificationService;
use Illuminate\Console\Command;
use Throwable;

class BackupListCommand extends Command
{
    protected $signature = 'backup:list {--verify : Vérifier entièrement chaque archive}';

    protected $description = 'Lister les headers des archives finales privées';

    public function handle(BackupManager $manager, BackupEncryptionService $encryption, BackupVerificationService $verification): int
    {
        $started = now()->utc()->toIso8601String();
        $result = 'failed';
        try {
            $root = $manager->validateConfiguration(false);
            $rows = [];
            $invalid = false;
            $files = glob($root.'/*.erpbackup') ?: [];
            sort($files);
            foreach ($files as $file) {
                try {
                    $path = $manager->resolveBackup($file);
                    $header = $encryption->readHeader($path);
                    $state = 'HEADER OK (non authentifié)';
                    if ($this->option('verify')) {
                        $verification->verify($path, $root);
                        $state = 'VALID';
                    }
                    $rows[] = [basename($path), $header['backup_id'], $header['created_at'], filesize($path), $header['key_id'], $header['format_version'], $state];
                } catch (BackupException $exception) {
                    $invalid = true;
                    $rows[] = [basename($file), '-', '-', '-', '-', '-', 'INVALID : '.$exception->getMessage()];
                } catch (Throwable) {
                    $invalid = true;
                    $rows[] = [basename($file), '-', '-', '-', '-', '-', 'INVALID : lecture impossible.'];
                }
            }
            $this->table(['Fichier', 'Backup ID', 'Date UTC', 'Octets', 'key_id', 'Version', 'État'], $rows);
            $result = $invalid ? 'failed' : 'succeeded';

            return $invalid ? self::FAILURE : self::SUCCESS;
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());
        } catch (Throwable) {
            $this->error('Liste des sauvegardes indisponible.');
        } finally {
            app(BackupOperationLogger::class)->record($result, ['operation' => 'list', 'started_at' => $started, 'source' => 'backup disk', 'target' => 'console']);
        }

        return self::FAILURE;
    }
}
