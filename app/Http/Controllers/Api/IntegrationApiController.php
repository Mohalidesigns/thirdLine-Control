<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\EffectivenessRating;
use App\Models\IntegrationSyncLog;
use App\Models\SpeakUpMetadataAccessLog;
use App\Services\IntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/v1 — the SecondLine side of the ThirdLine/NexusRisk contract
 * (BRD §8.4). Authenticated by per-tenant API key; every query is scoped
 * to the authenticated config's tenant.
 */
class IntegrationApiController extends Controller
{
    public function __construct(private IntegrationService $integrationService) {}

    /** GET /api/v1/controls?since={ts} — pull control definitions (FR-11.1). */
    public function controls(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('integration_tenant_id');

        $controls = Control::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_template', false)
            ->when($request->query('since'), fn ($q, $since) => $q->where('updated_at', '>=', $since))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (Control $control) => [
                'tenant_id' => $control->tenant_id,
                'external_ref' => $control->external_ref,
                'source_system' => 'SecondLine',
                'version_no' => $control->current_version,
                'last_approved_at' => $control->approved_at?->toIso8601String(),
                'control' => $control->only([
                    'control_ref', 'title', 'description', 'objective', 'type',
                    'nature', 'frequency', 'is_key_control', 'status',
                ]),
            ]);

        return response()->json(['data' => $controls]);
    }

    /** POST /api/v1/controls — inbound definition from ThirdLine (FR-11.3). */
    public function upsertControl(Request $request): JsonResponse
    {
        $config = $request->attributes->get('integration_config');

        if ($config->sync_direction === 'Push') {
            return response()->json(['message' => 'This tenant is configured as SecondLine-master; inbound control writes are disabled.'], 409);
        }

        // validate() returns only the keys named in the rules, so every
        // control.* attribute must be listed or it is silently stripped
        // before ingestControl() ever sees it (DEF-016).
        $validated = $request->validate([
            'external_ref' => ['required', 'string', 'max:255'],
            'last_approved_at' => ['nullable', 'date'],
            'control' => ['required', 'array'],
            'control.title' => ['required', 'string', 'max:255'],
            'control.description' => ['nullable', 'string'],
            'control.objective' => ['nullable', 'string'],
            'control.type' => ['nullable', Rule::in(['Preventive', 'Detective', 'Corrective'])],
            'control.nature' => ['nullable', Rule::in(['Manual', 'Automated', 'Hybrid', 'IT-Dependent Manual'])],
            'control.frequency' => ['nullable', Rule::in(['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Semi-annual', 'Annual', 'Event-driven'])],
            'control.is_key_control' => ['nullable', 'boolean'],
        ]);

        // Idempotency: a repeated delivery with the same key returns the prior result.
        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey) {
            $prior = IntegrationSyncLog::withoutGlobalScopes()
                ->where('tenant_id', $config->tenant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('direction', 'inbound')
                ->where('status', 'Success')
                ->exists();

            if ($prior) {
                return response()->json(['action' => 'duplicate', 'message' => 'Already processed.'], 200);
            }
        }

        return response()->json($this->integrationService->ingestControl($config, $validated), 201);
    }

    /** GET /api/v1/test-results — completed tests with ratings (FR-11.2). */
    public function testResults(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('integration_tenant_id');

        $ratings = EffectivenessRating::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'Published')
            ->with(['control:id,external_ref,control_ref', 'testInstance:id,reference,period_label,status,reviewed_at'])
            ->when($request->query('since'), fn ($q, $since) => $q->where('approved_at', '>=', $since))
            ->orderByDesc('approved_at')
            ->limit(500)
            ->get()
            ->map(fn (EffectivenessRating $rating) => [
                'tenant_id' => $rating->tenant_id,
                'external_ref' => $rating->control?->external_ref,
                'source_system' => 'SecondLine',
                'reference' => $rating->testInstance?->reference,
                'period_label' => $rating->period_label,
                'design_effectiveness' => $rating->design_effectiveness,
                'operating_effectiveness' => $rating->operating_effectiveness,
                'overall_rating' => $rating->overall_rating,
                'approved_at' => $rating->approved_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $ratings]);
    }

    /** GET /api/v1/exceptions?status=open — for audit reliance (FR-11.2). */
    public function exceptions(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('integration_tenant_id');

        $exceptions = ControlException::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when(
                $request->query('status', 'open') === 'open',
                fn ($q) => $q->whereIn('status', ControlException::OPEN_STATUSES),
                fn ($q) => $q->where('status', $request->query('status')),
            )
            ->with('control:id,external_ref,control_ref')
            ->orderByDesc('date_raised')
            ->limit(500)
            ->get()
            ->map(fn (ControlException $exception) => [
                'tenant_id' => $exception->tenant_id,
                'external_ref' => $exception->control?->external_ref,
                'source_system' => 'SecondLine',
                'reference' => $exception->reference,
                'title' => $exception->title,
                'severity' => $exception->severity,
                'status' => $exception->status,
                'age_days' => $exception->age_days,
                'is_overdue' => $exception->is_overdue,
                'date_raised' => $exception->date_raised?->toDateString(),
            ]);

        return response()->json(['data' => $exceptions]);
    }

    /**
     * GET /api/v1/speak-up/metadata-access-log?since={ts} — every
     * break-glass event on Speak Up reporter metadata (CR), read out so
     * internal audit can verify the reveal control independently.
     *
     * Deliberately Tier 1 only: who requested, who approved, the reason
     * code, the justification and which FIELD NAMES were revealed — never
     * a metadata value and never a reporter identity. The integration key
     * carries no reveal-level scope, so no caller of this API can widen
     * the payload.
     */
    public function speakUpMetadataAccessLog(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('integration_tenant_id');

        $entries = SpeakUpMetadataAccessLog::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['report:id,case_ref', 'requester:id,name', 'approver:id,name'])
            ->when($request->query('since'), fn ($q, $since) => $q->where('occurred_at', '>=', $since))
            ->orderByDesc('occurred_at')
            ->limit(500)
            ->get()
            ->map(fn (SpeakUpMetadataAccessLog $entry) => [
                'tenant_id' => $entry->tenant_id,
                'source_system' => 'SecondLine',
                'case_ref' => $entry->report?->case_ref,
                'action' => $entry->action,
                'requested_by' => $entry->requester?->name,
                'approved_by' => $entry->approver?->name,
                'reason_code' => $entry->reason_code,
                'justification' => $entry->justification,
                'fields_revealed' => $entry->fields_revealed,
                'occurred_at' => $entry->occurred_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $entries]);
    }
}
