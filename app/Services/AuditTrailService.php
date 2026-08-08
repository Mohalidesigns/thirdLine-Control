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
     *
     * A model may declare auditsAnonymously(): the event is still written
     * (R3) but carries no user, IP or agent — anonymous survey responses
     * must never be de-anonymisable, even through the audit trail (9.4).
     *
     * A model may also declare auditRedacts(): those attributes are
     * replaced with a marker in the before/after payloads. The event is
     * still recorded in full — only the secret itself is withheld, because
     * a connection string or an API key has no business being copied into
     * a second table (12.1, R9).
     */
    public function record(string $action, Model $entity, ?array $before = null, ?array $after = null): void
    {
        try {
            $anonymous = method_exists($entity, 'auditsAnonymously') && $entity->auditsAnonymously();

            $redact = method_exists($entity, 'auditRedacts') ? $entity->auditRedacts() : [];
            $before = $this->redact($before, $redact);
            $after = $this->redact($after, $redact);

            AuditTrail::create([
                'tenant_id' => $entity->tenant_id ?? auth()->user()?->tenant_id,
                'user_id' => $anonymous ? null : auth()->id(),
                'entity_type' => $entity->getMorphClass(),
                'entity_id' => $entity->getKey(),
                'action' => $action,
                'before' => $before,
                'after' => $after,
                'ip_address' => $anonymous ? null : request()?->ip(),
                'user_agent' => $anonymous ? null : substr((string) request()?->userAgent(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit trail write failed', [
                'action' => $action,
                'entity' => $entity->getMorphClass().'#'.$entity->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function redact(?array $payload, array $keys): ?array
    {
        if ($payload === null || $keys === []) {
            return $payload;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }

        return $payload;
    }
}
