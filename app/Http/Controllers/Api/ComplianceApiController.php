<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Framework;
use App\Models\ObligationInstance;
use App\Services\FrameworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1 — the Phase 8 read surface of the ThirdLine/NexusRisk contract.
 *
 * Internal audit places reliance on second-line work, so it needs to see
 * framework coverage and the regulatory filing calendar without asking for
 * a spreadsheet. Read-only, scoped to the tenant that owns the API key, and
 * every record carries its verification status so nothing unverified is
 * mistaken for something filed.
 */
class ComplianceApiController extends Controller
{
    public function __construct(private FrameworkService $frameworks) {}

    /** GET /api/v1/frameworks/coverage — headline coverage per framework. */
    public function frameworkCoverage(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('integration_tenant_id');

        $frameworks = Framework::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->when($request->query('jurisdiction'), fn ($q, $j) => $q->where('jurisdiction', $j))
            ->orderBy('code')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $frameworks->map(fn (Framework $framework) => $this->frameworks->coverage($framework))->values(),
        ]);
    }

    /** GET /api/v1/obligations/instances?since={ts}&status={status} — the filing calendar. */
    public function obligationInstances(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('integration_tenant_id');

        $instances = ObligationInstance::withoutGlobalScopes()
            ->where('obligation_instances.tenant_id', $tenantId)
            ->with(['obligation.regulator:id,code,name', 'assignment.entity:id,name', 'assignment.owner:id,name'])
            ->when($request->query('since'), fn ($q, $since) => $q->where('obligation_instances.updated_at', '>=', $since))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('due_before'), fn ($q, $d) => $q->where('due_at', '<=', $d))
            ->orderBy('due_at')
            ->limit(500)
            ->get()
            ->map(fn (ObligationInstance $instance) => [
                'id' => $instance->id,
                'obligation_ref' => $instance->obligation?->obligation_ref,
                'title' => $instance->obligation?->title,
                'regulator' => $instance->obligation?->regulator?->code,
                'entity' => $instance->assignment?->entity?->name,
                'owner' => $instance->assignment?->owner?->name,
                'period_label' => $instance->period_label,
                'due_at' => $instance->due_at?->toIso8601String(),
                'status' => $instance->status,
                'days_overdue' => $instance->days_overdue,
                'submission_reference' => $instance->submission_reference,
                'penalty_exposure' => $instance->penaltyExposureMoney()?->jsonSerialize(),
                // R10 travels with the record: a consumer must be able to see
                // that an obligation has not been verified against its source.
                'verification_status' => $instance->obligation?->verification_status,
            ]);

        return response()->json(['data' => $instances]);
    }
}
