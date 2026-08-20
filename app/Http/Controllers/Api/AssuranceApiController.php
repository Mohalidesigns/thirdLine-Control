<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Risk;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Services\AssuranceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The internal-audit interlock (17.5).
 *
 * Third line and second line each hold half the assurance picture. This
 * surface exchanges the three things that actually need to cross:
 *
 *  - **coverage** second line has performed and third line may rely on;
 *  - **reliance decisions** in both directions, preserved with their
 *    rationale and the person who made them, so a decision recorded in
 *    ThirdLine still reads as that person's decision here;
 *  - **audit findings** that become control exceptions in this register,
 *    entering the same lifecycle as every other exception rather than a
 *    parallel one.
 *
 * Read-only where it can be, and strictly scoped to the tenant owning the
 * API key everywhere. An inbound write never creates an assurance provider
 * — an integration key must not be able to manufacture board-level comfort
 * out of nothing (see AssuranceService::applyInboundActivity).
 */
class AssuranceApiController extends Controller
{
    public function __construct(private AssuranceService $assurance) {}

    /** GET /api/v1/assurance/coverage — second-line coverage third line can rely on. */
    public function coverage(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('integration_tenant_id');

        return response()->json([
            'data' => $this->assurance->coverageForExport(
                $tenantId,
                $request->query('since'),
                $request->query('period'),
            ),
        ]);
    }

    /** GET /api/v1/assurance/gaps — significant risks carrying no independent assurance. */
    public function gaps(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('integration_tenant_id');
        $map = $this->assurance->map($tenantId, $request->query('period'));

        return response()->json([
            'data' => $map['gaps'],
            'meta' => [
                'duplication' => $map['duplication'],
                'significant_bands' => $map['config']['significant_bands'],
                'duplication_threshold' => $map['config']['duplication_threshold'],
            ],
        ]);
    }

    /**
     * POST /api/v1/assurance/activities — third line publishes its coverage
     * and its reliance decision into the map.
     */
    public function upsertActivity(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('integration_tenant_id');

        $validated = $request->validate([
            'provider_code' => ['required', 'string', 'max:40'],
            'subject_type' => ['required', 'string', 'max:30'],
            'subject_ref' => ['required_without:subject_id', 'nullable', 'string', 'max:100'],
            'subject_id' => ['required_without:subject_ref', 'nullable', 'integer'],
            'activity_name' => ['nullable', 'string', 'max:255'],
            'coverage_level' => ['nullable', 'in:none,partial,full'],
            'assurance_type' => ['nullable', 'in:reasonable,limited,agreed_upon_procedures,review,inspection,self_assessment'],
            'period_label' => ['nullable', 'string', 'max:40'],
            'last_assurance_date' => ['nullable', 'date'],
            'next_assurance_date' => ['nullable', 'date'],
            'conclusion' => ['nullable', 'in:satisfactory,needs_improvement,unsatisfactory,not_concluded'],
            'reliance_level' => ['nullable', 'in:none,limited,moderate,full'],
            'reliance_rationale' => ['nullable', 'string', 'max:2000'],
            'scope_note' => ['nullable', 'string', 'max:2000'],
            'external_ref' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $activity = $this->assurance->applyInboundActivity($tenantId, $validated);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        return response()->json([
            'data' => [
                'id' => $activity->id,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'coverage_level' => $activity->coverage_level,
                'reliance_level' => $activity->reliance_level,
                'external_ref' => $activity->external_ref,
                'synced_at' => $activity->synced_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * POST /api/v1/assurance/findings — an internal-audit finding becomes a
     * control exception here, in the same register and the same lifecycle
     * as an exception raised by a failed test. Re-posting the same
     * `external_ref` updates rather than duplicating, because an audit
     * system that retries must not double the exception count.
     */
    public function pushFinding(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('integration_tenant_id');

        $validated = $request->validate([
            'external_ref' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:8000'],
            'severity' => ['required', 'in:Critical,High,Medium,Low'],
            'control_ref' => ['nullable', 'string', 'max:60'],
            'risk_code' => ['nullable', 'string', 'max:60'],
            'target_closure_date' => ['nullable', 'date'],
        ]);

        $controlId = ($validated['control_ref'] ?? null)
            ? Control::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('control_ref', $validated['control_ref'])
                ->value('id')
            : null;

        $riskId = ($validated['risk_code'] ?? null)
            ? Risk::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('code', $validated['risk_code'])
                ->value('id')
            : null;

        $existing = ControlException::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source_type', 'Internal Audit')
            ->where('external_ref', $validated['external_ref'])
            ->first();

        $attributes = [
            'tenant_id' => $tenantId,
            'source_type' => 'Internal Audit',
            'external_ref' => $validated['external_ref'],
            'control_id' => $controlId,
            'risk_id' => $riskId,
            'title' => $validated['title'],
            'description' => ($validated['description'] ?? '')
                ."\n\nRaised by internal audit and received over the assurance interlock (reference {$validated['external_ref']}).",
            'severity' => $validated['severity'],
            'target_closure_date' => $validated['target_closure_date']
                ?? now()->addDays(match ($validated['severity']) {
                    'Critical' => 30,
                    'High' => 60,
                    default => 90,
                })->toDateString(),
        ];

        if ($existing) {
            $existing->update($attributes);
            $exception = $existing->fresh();
        } else {
            $exception = ControlException::withoutGlobalScopes()->create([
                ...$attributes,
                'reference' => ControlException::nextReference('EXC'),
                'date_raised' => now()->toDateString(),
                'status' => 'Open',
            ]);
        }

        $exception->auditAction('received-from-integration', null, [
            'external_ref' => $validated['external_ref'],
            'severity' => $exception->severity,
        ]);

        return response()->json([
            'data' => [
                'id' => $exception->id,
                'reference' => $exception->reference,
                'status' => $exception->status,
                'external_ref' => $exception->external_ref,
            ],
        ], $existing ? 200 : 201);
    }

    /**
     * GET /api/v1/third-parties — the third-party register with its current
     * due-diligence position, so internal audit can scope outsourcing work
     * without asking for a spreadsheet.
     */
    public function thirdParties(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('integration_tenant_id');

        $vendors = Vendor::withoutGlobalScopes()
            ->where('vendors.tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->with(['entity:id,legal_name', 'owner:id,name'])
            ->when($request->query('criticality'), fn ($q, $c) => $q->where('criticality', $c))
            ->when($request->query('since'), fn ($q, $s) => $q->where('vendors.updated_at', '>=', $s))
            ->orderBy('legal_name')
            ->limit(500)
            ->get();

        $current = VendorAssessment::withoutGlobalScopes()
            ->whereIn('vendor_id', $vendors->pluck('id'))
            ->whereIn('status', ['Approved', 'Expired'])
            ->orderBy('approved_at')
            ->get()
            ->groupBy('vendor_id')
            ->map(fn ($assessments) => $assessments->last());

        return response()->json([
            'data' => $vendors->map(function (Vendor $vendor) use ($current) {
                $assessment = $current->get($vendor->id);

                return [
                    'reference' => $vendor->reference,
                    'legal_name' => $vendor->legal_name,
                    'category' => $vendor->category,
                    'criticality' => $vendor->criticality,
                    'status' => $vendor->status,
                    'jurisdiction' => $vendor->jurisdiction,
                    'processing_country' => $vendor->processing_country,
                    'entity' => $vendor->entity?->legal_name,
                    'owner' => $vendor->owner?->name,
                    'data_access_classification' => $vendor->data_access_classification,
                    'annual_spend' => $vendor->annualSpendMoney()?->jsonSerialize(),
                    'regulator_notification_required' => $vendor->regulator_notification_required,
                    'regulator_notified_at' => $vendor->regulator_notified_at?->toDateString(),
                    'due_diligence' => $assessment ? [
                        'reference' => $assessment->reference,
                        'status' => $assessment->status,
                        'risk_band' => $assessment->risk_band,
                        'approved_at' => $assessment->approved_at?->toIso8601String(),
                        'expires_at' => $assessment->expires_at?->toDateString(),
                    ] : null,
                ];
            })->values(),
        ]);
    }
}
