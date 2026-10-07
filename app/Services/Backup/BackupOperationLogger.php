<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BackupOperationLogger
{
    public function record(string $status, array $context = []): void
    {
        $this->write('Backup operation', array_replace([
            'operation_id' => (string) Str::uuid(), 'backup_id' => null,
            'operation' => 'create', 'started_at' => now()->utc()->toIso8601String(),
            'finished_at' => now()->utc()->toIso8601String(), 'result' => $status,
            'source' => 'configured database and storage', 'target' => 'backup disk',
            'operator' => 'console', 'key_id' => config('backup.key_id'),
            'warnings' => [], 'error' => $status === 'failed' ? 'Operation failed; inspect prerequisites.' : null,
        ], $context));
    }

    public function restore(RestoreReport $report): void
    {
        // Report fields contain validated identifiers/paths, controlled states and errors only.
        // No PDO exceptions, credentials, dump contents or process output.
        $data = $report->toArray();
        $this->write('Restore operation', array_replace([
            'operation' => 'restore', 'source' => 'encrypted archive',
            'target' => ['database' => $data['target_database'], 'storage' => $data['target_storage']],
            'operator' => 'console', 'key_id' => config('backup.key_id'),
        ], $data));
    }

    private function write(string $message, array $data): void
    {
        $secrets = [];
        $collect = function (array $values) use (&$collect, &$secrets): void {
            foreach ($values as $key => $value) {
                if (is_array($value)) {
                    $collect($value);
                } elseif (is_string($value) && $value !== '' && preg_match('/password|secret|encryption_key|^key$/i', (string) $key)) {
                    $secrets[] = $value;
                }
            }
        };
        $collect(config()->all());
        usort($secrets, fn ($a, $b) => strlen($b) <=> strlen($a));
        $clean = function ($value) use (&$clean, $secrets) {
            if (is_array($value)) {
                return array_map($clean, $value);
            }

            return is_string($value) ? str_replace($secrets, '[REDACTED]', $value) : $value;
        };
        Log::channel('backup_operations')->info($message, $clean($data));
    }
}
