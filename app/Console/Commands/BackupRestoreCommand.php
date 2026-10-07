<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupOperationLogger;
use App\Services\Backup\RestoreManager;
use Illuminate\Console\Command;
use Throwable;

class BackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore {backup} {--target-database=} {--target-storage=} {--confirm=}';

    protected $description = 'Restaurer uniquement vers une base et un storage isolés, sans bascule production';

    public function handle(RestoreManager $manager): int
    {
        foreach (['target-database', 'target-storage', 'confirm'] as $option) {
            if (! is_string($this->option($option)) || $this->option($option) === '') {
                $this->error('Paramètre --'.$option.' obligatoire.');
                try {
                    app(BackupOperationLogger::class)->record('failed', ['operation' => 'restore', 'source' => 'backup disk', 'target' => 'isolated restore', 'error' => 'Required restore option missing.']);
                } catch (Throwable) {
                    // Preserve the safe prerequisite error even if logging is unavailable.
                }

                return self::FAILURE;
            }
        }
        try {
            $approve = $this->input->isInteractive() ? function (array $plan): bool {
                $this->line('Backup ID : '.$plan['manifest']['backup_id']);
                $this->line('Database cible : '.$plan['database']);
                $this->line('Storage cible : '.$plan['storage']);

                return $this->confirm('Confirmer la restauration isolée vers cette cible ?', false);
            } : null;
            $report = $manager->restore((string) $this->argument('backup'), $this->option('target-database'), $this->option('target-storage'), $this->option('confirm'), $approve)->toArray();
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($report['result'] === 'SUCCESS') {
                $this->info('SUCCESS : restauration isolée vérifiée.');

                return self::SUCCESS;
            }
            $this->error($report['result'].' : '.$report['error']);
        } catch (Throwable) {
            $this->error('FAILED : restauration isolée impossible.');
        }

        return self::FAILURE;
    }
}
