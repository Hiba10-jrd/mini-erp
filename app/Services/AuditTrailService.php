<?php

namespace App\Services;

use App\Models\OperationHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditTrailService
{
    public function sanitize(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (preg_match('/password|token|secret|session|two.?factor|2fa|mfa|totp|recovery.?codes|api.?key|private.?key|authorization|binary|content|receipt_path|image_path|logo_path|^path$/i', (string) $key)) {
                continue;
            }
            if (is_string($value) && in_array(substr(ltrim($value), 0, 1), ['{', '['], true)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    public function record(Model $subject, string $action, array $old = [], array $new = [], array $metadata = []): OperationHistory
    {
        return OperationHistory::create([
            'user_id' => Auth::id(), 'action' => $action,
            'subject_type' => Str::kebab(class_basename($subject)), 'subject_id' => $subject->getKey(),
            'old_values' => $this->sanitize($old), 'new_values' => $this->sanitize($new),
            'metadata' => $this->sanitize($metadata),
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : mb_substr((string) request()->userAgent(), 0, 500),
        ]);
    }
}
