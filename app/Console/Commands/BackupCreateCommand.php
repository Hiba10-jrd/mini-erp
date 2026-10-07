<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManager;
use Illuminate\Console\Command;
use Throwable;

class BackupCreateCommand extends Command
{
    protected $signature = 'backup:create';

    protected $description = 'Créer et vérifier une sauvegarde chiffrée privée';

    public function handle(BackupManager $manager): int
    {
        try {
            $path = $manager->create();
            $this->info('Sauvegarde chiffrée créée et vérifiée : '.basename($path));

            return self::SUCCESS;
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());
        } catch (Throwable) {
            $this->error('Échec de préparation de la sauvegarde.');
        }

        return self::FAILURE;
    }
}
