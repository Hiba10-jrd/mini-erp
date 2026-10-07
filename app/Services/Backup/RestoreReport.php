<?php

namespace App\Services\Backup;

use Illuminate\Support\Str;

class RestoreReport
{
    private array $data;

    public function __construct()
    {
        $this->data = [
            'operation_id' => (string) Str::uuid(), 'backup_id' => null,
            'started_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'finished_at' => null,
            'target_database' => null, 'target_storage' => null,
            'database_status' => 'NOT_STARTED', 'files_status' => 'NOT_STARTED',
            'quarantine_status' => 'NOT_STARTED', 'verification_status' => 'NOT_STARTED',
            'warnings' => [], 'result' => 'FAILED', 'error' => null,
        ];
    }

    public function set(string $field, mixed $value): void
    {
        if (array_key_exists($field, $this->data)) {
            $this->data[$field] = $value;
        }
    }

    public function finish(string $result, ?string $error = null): void
    {
        $this->data['result'] = $result;
        $this->data['error'] = $error;
        $this->data['finished_at'] = now()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
