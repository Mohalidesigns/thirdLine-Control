<?php

namespace App\Services;

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
    public function __construct(private AuditTrailService $audit) {}

    /** Never lets audit logging break the 403 it is recording. */
    public function record(Request $request): void
    {
        try {
            $entity = collect($request->route()?->parameters() ?? [])
                ->first(fn ($parameter) => $parameter instanceof Model);

            $this->audit->recordEvent(
                'denied',
                $entity?->getMorphClass() ?? ($request->route()?->getName() ?? 'route'),
                'Access denied: '.strtoupper($request->method()).' /'.ltrim($request->path(), '/'),
                ['method' => $request->method(), 'path' => $request->path()],
                array_filter([
                    'tenant_id' => auth()->user()?->tenant_id ?? $entity?->tenant_id,
                    'entity_id' => $entity?->getKey(),
                    'status_code' => 403,
                ], fn ($v) => $v !== null),
            );
        } catch (\Throwable $e) {
            Log::error('Denied-attempt audit write failed', [
                'path' => $request->path(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
