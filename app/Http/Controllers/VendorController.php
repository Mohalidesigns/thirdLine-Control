<?php

namespace App\Http\Controllers;

use App\Http\Requests\VendorAssessmentRequest;
use App\Http\Requests\VendorContractRequest;
use App\Http\Requests\VendorRequest;
use App\Models\CsaCampaign;
use App\Models\Entity;
use App\Models\RegulatoryObligation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorContract;
use App\Models\VendorFinding;
use App\Models\VendorScreening;
use App\Services\LinkageService;
use App\Services\VendorService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendorController extends Controller
{
    public function __construct(
        private VendorService $vendors,
        private LinkageService $linkage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vendor::class);

        $vendors = Vendor::query()
            ->with(['owner:id,name', 'entity:id,legal_name'])
            ->withCount(['findings as open_findings_count' => fn ($q) => $q->open()])
            ->when($request->criticality, fn ($q, $c) => $q->where('criticality', $c))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->when($request->entity_id, fn ($q, $e) => $q->where('entity_id', $e))
            ->when($request->boolean('personal_data_only'), fn ($q) => $q
                ->whereIn('data_access_classification', Vendor::PERSONAL_DATA_CLASSIFICATIONS))
            ->when($request->boolean('review_overdue'), fn ($q) => $q
                ->whereDoesntHave('assessments', fn ($a) => $a->where('status', 'Approved')))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('legal_name', 'like', "%{$s}%")
                ->orWhere('trading_name', 'like', "%{$s}%")
                ->orWhere('reference', 'like', "%{$s}%")))
            ->orderBy('legal_name')
            ->paginate(15)
            ->withQueryString();

        // Current assessment per listed vendor in one query rather than one
        // per row (R6, no N+1).
        $current = VendorAssessment::query()
            ->whereIn('vendor_id', $vendors->pluck('id'))
            ->whereIn('status', ['Approved', 'Expired'])
            ->orderBy('approved_at')
            ->get(['id', 'vendor_id', 'reference', 'status', 'risk_band', 'expires_at'])
            ->groupBy('vendor_id')
            ->map(fn ($assessments) => $assessments->last());

        return Inertia::render('Vendors/Index', [
            'vendors' => $vendors->through(fn (Vendor $vendor) => [
                ...$vendor->toArray(),
                'annual_spend' => $vendor->annualSpendMoney()?->jsonSerialize(),
                'current_assessment' => $current->get($vendor->id),
                'notification_outstanding' => $vendor->notificationOutstanding(),
                'touches_personal_data' => $vendor->touchesPersonalData(),
            ]),
            'filters' => $request->only([
                'criticality', 'status', 'category', 'entity_id', 'personal_data_only', 'review_overdue', 'search',
            ]),
            'criticalities' => Vendor::CRITICALITIES,
            'statuses' => Vendor::STATUSES,
            'classifications' => Vendor::DATA_CLASSIFICATIONS,
            'categories' => Vendor::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'entities' => Entity::query()->orderBy('legal_name')->get(['id', 'legal_name']),
            'owners' => User::tenantPicker()->get(['id', 'name']),
            'obligations' => RegulatoryObligation::query()
                ->orderBy('obligation_ref')
                ->limit(200)
                ->get(['id', 'obligation_ref', 'title', 'verification_status']),
            'stats' => $this->vendors->stats(),
            'can' => [
                'manage' => $request->user()->can('manage vendors'),
                'assess' => $request->user()->can('assess vendors'),
                'screen' => $request->user()->can('screen vendors'),
            ],
        ]);
    }

    public function store(VendorRequest $request): RedirectResponse
    {
        $this->authorize('create', Vendor::class);

        $vendor = Vendor::create([
            ...$request->validated(),
            'reference' => Vendor::nextReference('TP', false),
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('vendors.show', $vendor)
            ->with('success', "{$vendor->legal_name} added to the third-party register as {$vendor->reference}.");
    }

    public function show(Request $request, Vendor $vendor): Response
    {
        $this->authorize('view', $vendor);

        $vendor->load([
            'owner:id,name', 'entity:id,legal_name',
            'notificationObligation:id,obligation_ref,title,verification_status',
            'assessments.assessor:id,name', 'assessments.approver:id,name',
            'findings.owner:id,name', 'contracts', 'screenings.clearer:id,name',
        ]);

        return Inertia::render('Vendors/Show', [
            'vendor' => [
                ...$vendor->toArray(),
                'annual_spend' => $vendor->annualSpendMoney()?->jsonSerialize(),
                'notification_outstanding' => $vendor->notificationOutstanding(),
                'touches_personal_data' => $vendor->touchesPersonalData(),
            ],
            'assessments' => $vendor->assessments->map(fn (VendorAssessment $assessment) => [
                ...$assessment->toArray(),
                'days_to_expiry' => $assessment->daysToExpiry(),
            ]),
            'contracts' => $vendor->contracts->map(fn (VendorContract $contract) => [
                ...$contract->toArray(),
                'value' => $contract->valueMoney()?->jsonSerialize(),
                'days_to_expiry' => $contract->daysToExpiry(),
                'notice_deadline' => $contract->noticeDeadline(),
            ]),
            'links' => $this->linkage->neighbours('vendor', $vendor->id),
            'campaigns' => CsaCampaign::query()->orderByDesc('id')->limit(50)->get(['id', 'reference', 'name']),
            'obligations' => RegulatoryObligation::query()
                ->orderBy('obligation_ref')
                ->limit(200)
                ->get(['id', 'obligation_ref', 'title', 'verification_status']),
            'owners' => User::tenantPicker()->get(['id', 'name']),
            'entities' => Entity::query()->orderBy('legal_name')->get(['id', 'legal_name']),
            'options' => [
                'assessment_types' => VendorAssessment::TYPES,
                'bands' => VendorAssessment::BANDS,
                'severities' => VendorFinding::SEVERITIES,
                'finding_statuses' => VendorFinding::STATUSES,
                'screening_types' => VendorScreening::TYPES,
                'screening_results' => VendorScreening::RESULTS,
                'contract_statuses' => VendorContract::STATUSES,
                'criticalities' => Vendor::CRITICALITIES,
                'statuses' => Vendor::STATUSES,
                'classifications' => Vendor::DATA_CLASSIFICATIONS,
            ],
            'can' => [
                'manage' => $request->user()->can('update', $vendor),
                'assess' => $request->user()->can('assess', $vendor),
                'approve' => $request->user()->can('approve vendor-assessments'),
                'screen' => $request->user()->can('screen', $vendor),
                'notify' => $request->user()->can('notifyRegulator', $vendor),
                'contracts' => $request->user()->can('manageContracts', $vendor),
            ],
        ]);
    }

    public function update(VendorRequest $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('update', $vendor);

        $vendor->update($request->validated());

        return back()->with('success', "{$vendor->reference} updated.");
    }

    /** Concentration risk across the whole estate (17.2). */
    public function concentration(Request $request): Response
    {
        $this->authorize('viewAny', Vendor::class);

        return Inertia::render('Vendors/Concentration', [
            'concentration' => $this->vendors->concentration(
                $request->user()->tenant_id,
                $request->base_currency,
                $request->entity_id,
            ),
            'filters' => $request->only(['base_currency', 'entity_id']),
            'entities' => Entity::query()->orderBy('legal_name')->get(['id', 'legal_name']),
            'currencies' => Money::SUPPORTED,
        ]);
    }

    // ── Assessments ──────────────────────────────────────────────────

    public function storeAssessment(VendorAssessmentRequest $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('assess', $vendor);

        $assessment = VendorAssessment::create([
            ...$request->validated(),
            'tenant_id' => $vendor->tenant_id,
            'vendor_id' => $vendor->id,
            'reference' => VendorAssessment::nextReference('TPA'),
            'status' => 'Draft',
        ]);

        return back()->with('success', "Assessment {$assessment->reference} opened.");
    }

    public function submitAssessment(VendorAssessmentRequest $request, Vendor $vendor, VendorAssessment $assessment): RedirectResponse
    {
        $this->authorize('submit', $assessment);
        abort_unless($assessment->vendor_id === $vendor->id, 404);

        $this->vendors->submitAssessment($assessment, $request->user(), $request->validated());

        return back()->with('success', "Assessment {$assessment->reference} submitted for approval by a second person.");
    }

    public function approveAssessment(Request $request, Vendor $vendor, VendorAssessment $assessment): RedirectResponse
    {
        $this->authorize('approve', $assessment);
        abort_unless($assessment->vendor_id === $vendor->id, 404);

        $approved = $this->vendors->approveAssessment($assessment, $request->user());

        return back()->with('success', "Assessment approved — next review due {$approved->expires_at?->toDateString()}.");
    }

    public function rejectAssessment(Request $request, Vendor $vendor, VendorAssessment $assessment): RedirectResponse
    {
        $this->authorize('approve', $assessment);
        abort_unless($assessment->vendor_id === $vendor->id, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->vendors->rejectAssessment($assessment, $request->user(), $validated['reason']);

        return back()->with('success', 'Assessment returned to the assessor.');
    }

    // ── Findings ─────────────────────────────────────────────────────

    public function storeFinding(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('assess', $vendor);

        $validated = $request->validate([
            'assessment_id' => ['nullable', 'exists:vendor_assessments,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'severity' => ['required', 'in:'.implode(',', VendorFinding::SEVERITIES)],
            'owner_id' => ['nullable', 'tenant_user'],
            'due_date' => ['nullable', 'date'],
        ]);

        $finding = $this->vendors->raiseFinding($vendor, $validated, $request->user());

        return back()->with('success', "Finding {$finding->reference} raised.");
    }

    public function updateFinding(Request $request, Vendor $vendor, VendorFinding $finding): RedirectResponse
    {
        $this->authorize('assess', $vendor);
        abort_unless($finding->vendor_id === $vendor->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'in:'.implode(',', VendorFinding::STATUSES)],
            'resolution' => ['nullable', 'string', 'max:4000'],
        ]);

        $closing = in_array($validated['status'], ['Closed', 'Accepted'], true);

        $finding->update([
            ...$validated,
            'closed_at' => $closing ? now() : null,
            'closed_by' => $closing ? $request->user()->id : null,
        ]);

        return back()->with('success', "Finding {$finding->reference} updated.");
    }

    // ── Screening ────────────────────────────────────────────────────

    public function storeScreening(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('screen', $vendor);

        $validated = $request->validate([
            'screening_type' => ['required', 'in:'.implode(',', VendorScreening::TYPES)],
            'result' => ['required', 'in:'.implode(',', VendorScreening::RESULTS)],
            'data_source_id' => ['nullable', 'exists:data_sources,id'],
            'summary' => ['nullable', 'string', 'max:4000'],
            'hits' => ['nullable', 'array', 'max:100'],
            'hits.*.name' => ['required', 'string', 'max:255'],
            'hits.*.source' => ['nullable', 'string', 'max:255'],
            'hits.*.detail' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->vendors->recordScreening($vendor, $validated);

        return back()->with('success', 'Screening recorded. Any match stays open until a named person dispositions it.');
    }

    public function dispositionScreening(Request $request, Vendor $vendor, VendorScreening $screening): RedirectResponse
    {
        $this->authorize('screen', $vendor);
        abort_unless($screening->vendor_id === $vendor->id, 404);

        $validated = $request->validate([
            'disposition' => ['required', 'string', 'max:4000'],
            'raise_finding' => ['nullable', 'boolean'],
            'severity' => ['nullable', 'in:'.implode(',', VendorFinding::SEVERITIES)],
        ]);

        $this->vendors->dispositionScreening($screening, $request->user(), $validated);

        return back()->with('success', 'Screening dispositioned.');
    }

    // ── Regulator notification ───────────────────────────────────────

    /**
     * Record that the material-outsourcing notification has been filed.
     * The obligation itself lives in the Phase 8 register with its own
     * citation and verification status; this records the filing.
     */
    public function recordNotification(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('notifyRegulator', $vendor);

        $validated = $request->validate([
            'regulator_notified_at' => ['required', 'date', 'before_or_equal:today'],
            'regulator_notification_ref' => ['required', 'string', 'max:255'],
            'notification_obligation_id' => ['nullable', 'exists:regulatory_obligations,id'],
        ]);

        $vendor->update($validated);
        $vendor->auditAction('regulator-notified', null, [
            ...$validated,
            'by' => $request->user()->id,
        ]);

        return back()->with('success', 'Notification recorded against the relationship.');
    }

    // ── Contracts ────────────────────────────────────────────────────

    public function storeContract(VendorContractRequest $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('manageContracts', $vendor);

        $contract = VendorContract::create([
            ...$request->validated(),
            'tenant_id' => $vendor->tenant_id,
            'vendor_id' => $vendor->id,
            'reference' => VendorContract::nextReference('TPC'),
        ]);

        return back()->with('success', "Contract {$contract->reference} registered.");
    }

    public function updateContract(VendorContractRequest $request, Vendor $vendor, VendorContract $contract): RedirectResponse
    {
        $this->authorize('manageContracts', $vendor);
        abort_unless($contract->vendor_id === $vendor->id, 404);

        $contract->update($request->validated());

        return back()->with('success', "Contract {$contract->reference} updated.");
    }
}
