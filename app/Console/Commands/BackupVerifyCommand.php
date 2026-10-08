<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupOperationLogger;
use App\Services\Backup\BackupVerificationService;
use Illuminate\Console\Command;
use Throwable;

class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify {backup : Nom du fichier ou chemin sur le disque de sauvegarde}';

    protected $description = 'Vérifier intégralement une archive chiffrée sans restaurer';

    public function handle(BackupManager $manager, BackupVerificationService $verification): int
    {
        $started = now()->utc()->toIso8601String();
        $manifest = null;
        try {
            $path = $manager->resolveBackup((string) $this->argument('backup'));
            $manifest = $verification->verify($path, $manager->validateConfiguration(false));
            $this->info('VALID');

            return self::SUCCESS;
        } catch (BackupException $exception) {
            $this->error('INVALID : '.$exception->getMessage());
        } catch (Throwable) {
            $this->error('INVALID : vérification impossible.');
        } finally {
            app(BackupOperationLogger::class)->record($manifest ? 'succeeded' : 'failed', [
                'operation' => 'verify', 'started_at' => $started,
                'backup_id' => $manifest['backup_id'] ?? null, 'source' => 'backup disk', 'target' => 'verification only',
            ]);
        }

        return self::FAILURE;
    }
}
