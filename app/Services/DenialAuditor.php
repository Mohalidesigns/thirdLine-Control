<?php

namespace App\Services;

use App\Models\AuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Records DENIED authorisation attempts (DEF-005 / TC-17-05). The refusal
 * itself is enforced elsewhere; this writes the record of it, so an
 * examiner can answer "did anyone attempt this outside their authority?" —
 * an attempted breach of the FR-12.3 segregation rules is precisely the
 * event a second-line platform exists to surface.
 */
class DenialAuditor
{
    /** Never lets audit logging break the 403 it is recording. */
    public function record(Request $request): void
    {
        try {
            $entity = collect($request->route()?->parameters() ?? [])
                ->first(fn ($parameter) => $parameter instanceof Model);

            AuditTrail::create([
                'tenant_id' => auth()->user()?->tenant_id ?? $entity?->tenant_id,
                'user_id' => auth()->id(),
                'entity_type' => $entity?->getMorphClass() ?? ($request->route()?->getName() ?? 'route'),
                'entity_id' => $entity?->getKey() ?? 0,
                'action' => 'denied',
                'after' => [
                    'method' => $request->method(),
                    'path' => $request->path(),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::error('Denied-attempt audit write failed', [
                'path' => $request->path(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
