<?php

namespace App\Models\Concerns;

use App\Services\AuditTrailService;

/**
 * Opt-in audit trail coverage: any model using this trait writes an
 * immutable audit record for every create, update, and delete (FR-12.4).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            app(AuditTrailService::class)->record('created', $model, null, $model->getAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $before = array_intersect_key($model->getOriginal(), $changes);
            app(AuditTrailService::class)->record('updated', $model, $before, $changes);
        });

        static::deleted(function ($model) {
            app(AuditTrailService::class)->record('deleted', $model, $model->getOriginal(), null);
        });
    }

    /**
     * Record a named domain action (approval, closure, escalation, export…)
     * beyond the basic CRUD events.
     */
    public function auditAction(string $action, ?array $before = null, ?array $after = null): void
    {
        app(AuditTrailService::class)->record($action, $this, $before, $after);
    }
}
