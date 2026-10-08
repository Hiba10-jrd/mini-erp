<?php

namespace App\Services\Backup;

use DateTimeImmutable;
use Throwable;

class BackupRetentionService
{
    public function __construct(private BackupWorkspace $workspace, private BackupVerificationService $verification) {}

    /** Called only by create after publication and authenticated verification, under backup.lock. */
    public function afterSuccessfulBackup(string $current, string $root): array
    {
        $this->workspace->assertSafePath($root);
        $this->workspace->assertSafePath($current);
        if (dirname($current) !== $root || ! str_ends_with($current, '.erpbackup')) {
            throw new BackupException('Archive courante invalide pour la rétention.');
        }
        // Reauthenticate the gate: a failed or corrupted current backup can never authorize deletion.
        $this->verification->verify($current, $root);
        $counts = [];
        foreach (['daily', 'weekly', 'monthly'] as $tier) {
            $value = config('backup.retention.'.$tier);
            if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < 0 || $value > 10000) {
                throw new BackupException('Configuration de rétention invalide.');
            }
            $counts[$tier] = (int) $value;
        }
        $lockPath = rtrim(config('filesystems.disks.'.config('backup.disk').'.root'), '/\\').'/.restore.lock';
        $this->workspace->assertSafePath($lockPath);
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new BackupException('Verrou de rétention inaccessible.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return ['kept' => ['*' => 'restore en cours'], 'deleted' => []];
            }
            $valid = $kept = $deleted = [];
            foreach (glob($root.'/*.erpbackup') ?: [] as $path) {
                try {
                    $this->workspace->assertSafePath($path);
                    $manifest = $this->verification->verify($path, $root);
                    $valid[$path] = $manifest['created_at'];
                } catch (Throwable) {
                    $kept[$path] = 'non vérifiable : conservation prudente';
                }
            }
            arsort($valid, SORT_STRING);
            if ($valid !== []) {
                $kept[array_key_first($valid)] = 'dernière archive valide';
            }
            $kept[$current] = 'backup courant vérifié';
            foreach (['daily' => 'Y-m-d', 'weekly' => 'o-W', 'monthly' => 'Y-m'] as $tier => $format) {
                $buckets = [];
                foreach ($valid as $path => $date) {
                    $bucket = (new DateTimeImmutable($date))->format($format);
                    if (! isset($buckets[$bucket]) && count($buckets) < $counts[$tier]) {
                        $buckets[$bucket] = true;
                        $kept[$path] ??= $tier;
                    }
                }
            }
            foreach ($valid as $path => $date) {
                // Any sidecar presence, including an unsafe symlink, protects rather than deletes.
                if (file_exists($path.'.protected') || is_link($path.'.protected')) {
                    $kept[$path] = 'protégée';
                }
                if (! isset($kept[$path])) {
                    $this->workspace->assertSafePath($path);
                    if (! unlink($path)) {
                        throw new BackupException('Suppression de rétention impossible.');
                    }
                    $deleted[] = $path;
                }
            }
            foreach (glob($root.'/*.part') ?: [] as $path) {
                $kept[$path] = 'archive temporaire';
            }

            return compact('kept', 'deleted');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
