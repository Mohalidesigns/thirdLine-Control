<?php

namespace App\Http\Controllers;

use App\Http\Requests\GhgDataPointRequest;
use App\Http\Requests\MaterialityAssessmentRequest;
use App\Models\Control;
use App\Models\Entity;
use App\Models\GhgDataPoint;
use App\Models\MaterialityAssessment;
use App\Models\Regulator;
use App\Models\SustainabilityFiling;
use App\Models\SustainabilityFilingStage;
use App\Models\SustainabilityTopic;
use App\Models\User;
use App\Services\SustainabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SustainabilityController extends Controller
{
    public function __construct(private SustainabilityService $sustainability) {}

    /** Topic register and the period's materiality determinations. */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MaterialityAssessment::class);

        $period = $request->period ?: (string) now()->year;

        return Inertia::render('Sustainability/Index', [
            'topics' => SustainabilityTopic::query()
                ->active()
                ->when($request->pillar, fn ($q, $p) => $q->where('pillar', $p))
                ->orderBy('pillar')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(),
            'assessments' => MaterialityAssessment::query()
                ->where('period_label', $period)
                ->when($request->entity_id, fn ($q, $e) => $q->where('entity_id', $e))
                ->with(['topic:id,code,title,pillar,verification_status', 'assessor:id,name', 'approver:id,name', 'entity:id,legal_name'])
                ->orderBy('topic_id')
                ->get(),
            'readiness' => $this->sustainability->readiness($period, $request->entity_id),
            'filters' => ['period' => $period, 'pillar' => $request->pillar, 'entity_id' => $request->entity_id],
            'pillars' => collect(SustainabilityTopic::PILLARS)
                ->map(fn ($p) => ['value' => $p, 'label' => SustainabilityTopic::PILLAR_LABELS[$p]])
                ->all(),
            'bases' => MaterialityAssessment::BASES,
            'entities' => Entity::query()->orderBy('legal_name')->get(['id', 'legal_name']),
            'stats' => $this->sustainability->stats($period),
            'can' => [
                'manage' => $request->user()->can('manage sustainability'),
                'approve' => $request->user()->can('approve materiality'),
            ],
        ]);
    }

    public function storeMateriality(MaterialityAssessmentRequest $request): RedirectResponse
    {
        $this->authorize('create', MaterialityAssessment::class);

        $assessment = MaterialityAssessment::create([
            ...$request->validated(),
            'status' => 'Draft',
        ]);

        $this->sustainability->submitMateriality($assessment, $request->user(), []);

        return back()->with('success', 'Materiality determination submitted for approval by a second person.');
    }

    public function approveMateriality(Request $request, MaterialityAssessment $assessment): RedirectResponse
    {
        $this->authorize('approve', $assessment);

        $this->sustainability->approveMateriality($assessment, $request->user());

        return back()->with('success', 'Materiality determination approved.');
    }

    // ── GHG inventory ────────────────────────────────────────────────

    public function ghg(Request $request): Response
    {
        $this->authorize('viewAny', GhgDataPoint::class);

        $period = $request->period ?: (string) now()->year;

        $points = GhgDataPoint::query()
            ->where('period_label', $period)
            ->when($request->scope, fn ($q, $s) => $q->where('scope', $s))
            ->when($request->entity_id, fn ($q, $e) => $q->where('entity_id', $e))
            ->with(['entity:id,legal_name', 'control:id,control_ref,title', 'preparer:id,name', 'verifier:id,name'])
            ->orderBy('scope')
            ->orderBy('category')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Sustainability/Ghg', [
            'points' => $points->through(fn (GhgDataPoint $point) => [
                ...$point->toArray(),
                'lineage_gaps' => $point->lineageGaps(),
                'is_assurable' => $point->isAssurable(),
            ]),
            'inventory' => $this->sustainability->inventory($period, $request->entity_id),
            'filters' => ['period' => $period, 'scope' => $request->scope, 'entity_id' => $request->entity_id],
            'entities' => Entity::query()->orderBy('legal_name')->get(['id', 'legal_name']),
            'controls' => Control::query()->active()->orderBy('control_ref')->limit(300)->get(['id', 'control_ref', 'title']),
            'options' => [
                'scopes' => GhgDataPoint::SCOPES,
                'data_qualities' => GhgDataPoint::DATA_QUALITIES,
                'methodologies' => GhgDataPoint::METHODOLOGIES,
            ],
            'can' => [
                'prepare' => $request->user()->can('prepare ghg-data'),
                'verify' => $request->user()->can('verify ghg-data'),
                'manage' => $request->user()->can('manage sustainability'),
            ],
        ]);
    }

    public function storeDataPoint(GhgDataPointRequest $request): RedirectResponse
    {
        $this->authorize('create', GhgDataPoint::class);

        $point = GhgDataPoint::create([...$request->validated(), 'status' => 'Draft']);
        $this->sustainability->prepareDataPoint($point, $request->user(), []);

        return back()->with('success', 'Figure prepared. A second person must verify it before it can be reported.');
    }

    public function updateDataPoint(GhgDataPointRequest $request, GhgDataPoint $point): RedirectResponse
    {
        $this->authorize('update', $point);

        $this->sustainability->prepareDataPoint($point, $request->user(), $request->validated());

        return back()->with('success', 'Figure updated and returned to Prepared.');
    }

    public function verifyDataPoint(Request $request, GhgDataPoint $point): RedirectResponse
    {
        $this->authorize('verify', $point);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->sustainability->verifyDataPoint($point, $request->user(), $validated['note'] ?? null);

        return back()->with('success', 'Figure verified.');
    }

    public function deferDataPoint(Request $request, GhgDataPoint $point): RedirectResponse
    {
        $this->authorize('defer', $point);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->sustainability->deferDataPoint($point, $validated['reason']);

        return back()->with('success', 'Scope 3 figure deferred under the transition reliefs.');
    }

    // ── Filing tracker ───────────────────────────────────────────────

    public function filings(Request $request): Response
    {
        $this->authorize('viewAny', MaterialityAssessment::class);

        return Inertia::render('Sustainability/Filings', [
            'filings' => SustainabilityFiling::query()
                ->with(['entity:id,legal_name', 'owner:id,name', 'regulator:id,code,name', 'stages'])
                ->orderByDesc('fiscal_year_start')
                ->get()
                ->map(fn (SustainabilityFiling $filing) => [
                    ...$filing->toArray(),
                    'reporting_class_label' => $filing->reportingClassLabel(),
                    'stages' => $filing->stages->map(fn (SustainabilityFilingStage $stage) => [
                        ...$stage->toArray(),
                        'days_to_due' => $stage->daysToDue(),
                    ]),
                ]),
            'entities' => Entity::query()->orderBy('legal_name')->get(['id', 'legal_name', 'fiscal_year_end']),
            'regulators' => Regulator::query()->orderBy('code')->get(['id', 'code', 'name']),
            'owners' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // The shipped schedule and adoption years are surfaced so an
            // officer can see exactly what a computed deadline came from —
            // and that it is unverified until they say otherwise (R10).
            'schedule' => $this->sustainability->stageSchedule($request->user()->tenant_id),
            'suggestedAdoptionYears' => SustainabilityService::DEFAULT_ADOPTION_YEARS,
            'reportingClasses' => collect(SustainabilityFiling::REPORTING_CLASSES)
                ->map(fn ($c) => ['value' => $c, 'label' => SustainabilityFiling::REPORTING_CLASS_LABELS[$c]])
                ->all(),
            'can' => [
                'manage' => $request->user()->can('manage sustainability-filings'),
            ],
        ]);
    }

    public function storeFiling(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('manage sustainability-filings'), 403);

        $validated = $request->validate([
            'entity_id' => ['nullable', 'exists:entities,id'],
            'regulator_id' => ['nullable', 'exists:regulators,id'],
            'reporting_class' => ['required', 'in:'.implode(',', SustainabilityFiling::REPORTING_CLASSES)],
            'adoption_year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'fiscal_year_start' => ['required', 'date'],
            'period_label' => ['required', 'string', 'max:40'],
            'owner_id' => ['nullable', 'tenant_user'],
        ]);

        $filing = $this->sustainability->createFiling(
            [...$validated, 'tenant_id' => $request->user()->tenant_id],
            $request->user(),
        );

        return back()->with('success', "Filing {$filing->reference} planned with {$filing->stages->count()} stage deadlines.");
    }

    public function submitStage(Request $request, SustainabilityFiling $filing, SustainabilityFilingStage $stage): RedirectResponse
    {
        abort_unless($request->user()->can('manage sustainability-filings'), 403);
        abort_unless($stage->filing_id === $filing->id, 404);

        $validated = $request->validate([
            'submission_reference' => ['nullable', 'string', 'max:255'],
            'evidence_id' => ['nullable', 'exists:evidence,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->sustainability->submitStage($stage, $request->user(), $validated);

        return back()->with('success', "{$stage->label} recorded as filed.");
    }

    /**
     * Confirm that a filing's computed deadlines have been checked against
     * the regulator's own published document. Until this happens the filing
     * is excluded from generated submissions (R10).
     */
    public function verifyFiling(Request $request, SustainabilityFiling $filing): RedirectResponse
    {
        abort_unless($request->user()->can('manage sustainability-filings'), 403);

        $validated = $request->validate([
            'verification_note' => ['required', 'string', 'max:2000'],
        ]);

        $filing->update([
            'verification_status' => 'verified',
            'verification_note' => $validated['verification_note'],
        ]);

        $filing->auditAction('verified', ['verification_status' => 'unverified'], [
            'verification_status' => 'verified',
            'by' => $request->user()->id,
            'note' => $validated['verification_note'],
        ]);

        return back()->with('success', 'Filing deadlines confirmed against the regulator\'s published schedule.');
    }
}
