<?php

namespace App\Services;

use App\Models\AuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AuditTrailService
{
    /**
     * Record an action against an entity. Never lets a logging failure
     * break the business operation it is recording.
     */
    public function record(string $action, Model $entity, ?array $before = null, ?array $after = null): void
    {
        try {
            AuditTrail::create([
                'tenant_id' => $entity->tenant_id ?? auth()->user()?->tenant_id,
                'user_id' => auth()->id(),
                'entity_type' => $entity->getMorphClass(),
                'entity_id' => $entity->getKey(),
                'action' => $action,
                'before' => $before,
                'after' => $after,
                'ip_address' => request()?->ip(),
                'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit trail write failed', [
                'action' => $action,
                'entity' => $entity->getMorphClass().'#'.$entity->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
