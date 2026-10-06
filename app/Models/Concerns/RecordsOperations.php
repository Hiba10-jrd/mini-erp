<?php

namespace App\Models\Concerns;

use App\Services\AuditTrailService;
use Illuminate\Database\Eloquent\Model;

/** Explicitly applied only to domains without an existing business history. */
trait RecordsOperations
{
    public static function bootRecordsOperations(): void
    {
        static::created(function (Model $model): void {
            app(AuditTrailService::class)->record($model, 'created', [], $model->getAttributes());
        });
        static::updated(function (Model $model): void {
            $changes = $model->getChanges();
            unset($changes['updated_at'], $changes['remember_token']);
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($model->getRawOriginal(), $changes);
            app(AuditTrailService::class)->record($model, 'updated', $old, $changes,
                array_key_exists('password', $changes) ? ['credentials_changed' => true] : []);
        });
        static::deleted(function (Model $model): void {
            app(AuditTrailService::class)->record($model, 'deleted', $model->getAttributes());
        });
    }
}
