<?php

namespace App\Http\Controllers;

use App\Http\Requests\RiskRequest;
use App\Models\BusinessProcess;
use App\Models\Control;
use App\Models\Framework;
use App\Models\OrganisationUnit;
use App\Models\Risk;
use App\Models\RiskAppetite;
use App\Models\RiskAssessmentScale;
use App\Models\RiskCategory;
use App\Models\RiskTreatment;
use App\Models\User;
use App\Services\AppetiteService;
use App\Services\HeatmapService;
use App\Services\LinkageService;
use App\Services\ResidualRiskService;
use App\Services\RiskAssessmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RiskController extends Controller
{
    public function __construct(
        private ResidualRiskService $residualRiskService,
        private RiskAssessmentService $assessmentService,
        private HeatmapService $heatmapService,
        private AppetiteService $appetiteService,
        private LinkageService $linkageService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Risk::class);

        $query = Risk::query()
            ->with([
                'owner:id,name',
                'riskCategory:id,name,code',
                'entity:id,name',
                'controls:id,control_ref,title,status',
            ])
            ->withCount(['treatments as open_treatments_count' => fn ($q) => $q->open()])
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->when($request->risk_category_id, fn ($q, $c) => $q->where('risk_category_id', $c))
            ->when($request->entity_id, fn ($q, $e) => $q->where('entity_id', $e))
            ->when($request->owner_id, fn ($q, $o) => $q->where('risk_owner_id', $o))
            ->when($request->band, fn ($q, $b) => $q->where('current_rating_band', $b))
            ->when($request->boolean('breach_only'), fn ($q) => $q->where('appetite_breached', true))
            ->when($request->boolean('emerging_only'), fn ($q) => $q->where('is_emerging', true));

        return Inertia::render('Risks/Index', [
            'risks' => $query->orderBy('code')->paginate(15)->withQueryString(),
            'filters' => $request->only([
                'search', 'category', 'risk_category_id', 'entity_id', 'owner_id',
                'band', 'breach_only', 'emerging_only',
            ]),
            'categories' => Risk::query()->select('category')->whereNotNull('category')->distinct()->pluck('category'),
            'riskCategories' => RiskCategory::query()->orderBy('sort_order')->get(['id', 'code', 'name', 'parent_id']),
            'entities' => OrganisationUnit::query()->orderBy('name')->get(['id', 'name']),
            'owners' => User::tenantPicker()->get(['id', 'name']),
            'bands' => Risk::RATING_BANDS,
            'stats' => [
                'total' => Risk::active()->count(),
                'breaching' => Risk::active()->breachingAppetite()->count(),
                'emerging' => Risk::active()->where('is_emerging', true)->count(),
                'untreated' => Risk::active()->whereDoesntHave('treatments', fn ($q) => $q->open())->count(),
            ],
        ]);
    }

    public function store(RiskRequest $request): RedirectResponse
    {
        $this->authorize('create', Risk::class);

        $data = $request->validated();
        $data['inherent_rating'] = $data['inherent_likelihood'] * $data['inherent_impact'];
        $data['code'] = Risk::nextReference('RSK', false, 'code');

        $risk = Risk::create($data);
        $this->assessmentService->recomputeResidual($risk);

        return redirect()->route('risks.show', $risk)->with('success', "Risk {$risk->code} created.");
    }

    public function show(Request $request, Risk $risk): Response
    {
        $this->authorize('view', $risk);

        $risk->load([
            'owner:id,name', 'secondLineReviewer:id,name', 'riskCategory:id,name,code',
            'entity:id,name', 'process:id,name', 'appetite', 'parent:id,code,title',
            'controls.owner:id,name',
            'assessments.assessor:id,name', 'assessments.approver:id,name',
            'treatments.owner:id,name', 'treatments.verifier:id,name', 'treatments.milestones.owner:id,name',
        ]);

        $tenantId = $risk->tenant_id;
        $appetite = $this->appetiteService->applicableFor($risk);

        return Inertia::render('Risks/Show', [
            'risk' => $risk,
            'scales' => [
                'likelihood' => $this->heatmapService->axis($tenantId, 'likelihood'),
                'impact' => $this->heatmapService->axis($tenantId, 'impact'),
                'bands' => $this->heatmapService->bandLegend($tenantId),
                'dimensions' => RiskAssessmentScale::DIMENSIONS,
            ],
            'appetite' => $appetite ? [
                'id' => $appetite->id,
                'statement' => $appetite->statement,
                'appetite_level' => $appetite->appetite_level,
                'tolerance_upper' => $appetite->tolerance_upper,
                'capacity' => $appetite->capacity,
                'position' => $appetite->positionFor($risk->currentScore()),
            ] : null,
            'targetGap' => $risk->targetGap(),
            'links' => $this->linkageService->neighbours('risk', $risk->id),
            'mappableControls' => Control::active()
                ->where('is_template', false)
                ->whereDoesntHave('risks', fn ($q) => $q->where('risks.id', $risk->id))
                ->orderBy('control_ref')
                ->get(['id', 'control_ref', 'title']),
            'users' => User::tenantPicker()->get(['id', 'name']),
            'riskCategories' => RiskCategory::query()->orderBy('sort_order')->get(['id', 'code', 'name']),
            'entities' => OrganisationUnit::query()->orderBy('name')->get(['id', 'name']),
            'processes' => BusinessProcess::query()->orderBy('name')->get(['id', 'name']),
            'appetites' => RiskAppetite::query()->active()->get(['id', 'statement']),
            'can' => [
                'assess' => $request->user()->can('assess', $risk),
                'review' => $request->user()->can('review', $risk),
                'treat' => $request->user()->can('create', RiskTreatment::class),
                'link' => $request->user()->can('manage linkage'),
            ],
        ]);
    }

    public function update(RiskRequest $request, Risk $risk): RedirectResponse
    {
        $this->authorize('update', $risk);

        $data = $request->validated();
        $data['inherent_rating'] = $data['inherent_likelihood'] * $data['inherent_impact'];

        $risk->update($data);
        $this->assessmentService->recomputeResidual($risk);

        return back()->with('success', "Risk {$risk->code} updated.");
    }

    /** Map a control to this risk (FR-2.2). */
    public function attachControl(Request $request, Risk $risk): RedirectResponse
    {
        $this->authorize('map', $risk);

        $request->validate([
            'control_id' => ['required', 'exists:controls,id'],
            'contribution_weight' => ['nullable', 'numeric', 'between:0.1,10'],
        ]);

        $risk->controls()->syncWithoutDetaching([
            $request->integer('control_id') => [
                'contribution_weight' => $request->input('contribution_weight', 1),
                'mapped_by' => $request->user()->id,
                'mapped_at' => now(),
            ],
        ]);

        $this->assessmentService->recomputeResidual($risk);

        return back()->with('success', 'Control mapped to risk.');
    }

    public function detachControl(Request $request, Risk $risk, Control $control): RedirectResponse
    {
        $this->authorize('map', $risk);

        $risk->controls()->detach($control->id);
        $this->assessmentService->recomputeResidual($risk);

        return back()->with('success', 'Mapping removed.');
    }

    /** Control gaps and orphan controls (FR-2.5). */
    public function gaps(): Response
    {
        $this->authorize('viewAny', Risk::class);

        return Inertia::render('Risks/Gaps', [
            'controlGaps' => Risk::controlGaps()->with('owner')->orderByDesc('inherent_rating')->get(),
            'orphanControls' => Control::orphans()
                ->where('status', '!=', 'Retired')
                ->with('owner')
                ->orderBy('control_ref')
                ->get(),
        ]);
    }

    /** Heatmap (10.2) — inherent, residual, target, and the movement view. */
    public function heatmap(Request $request): Response
    {
        $this->authorize('viewAny', Risk::class);

        $filters = $request->only([
            'category_id', 'entity_id', 'owner_id', 'process_id', 'framework_id', 'breach_only',
        ]);

        return Inertia::render('Risks/Heatmap', [
            'heatmap' => $this->heatmapService->build(
                $request->user()->tenant_id,
                $request->input('view', 'residual'),
                $filters,
            ),
            'filters' => [...$filters, 'view' => $request->input('view', 'residual')],
            'views' => HeatmapService::VIEWS,
            'riskCategories' => RiskCategory::query()->orderBy('sort_order')->get(['id', 'code', 'name']),
            'entities' => OrganisationUnit::query()->orderBy('name')->get(['id', 'name']),
            'processes' => BusinessProcess::query()->orderBy('name')->get(['id', 'name']),
            'owners' => User::tenantPicker()->get(['id', 'name']),
            'frameworks' => Framework::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** The risks behind one heatmap cell — the click-through. */
    public function heatmapCell(Request $request): Response
    {
        $this->authorize('viewAny', Risk::class);

        $validated = $request->validate([
            'likelihood' => ['required', 'integer', 'min:1'],
            'impact' => ['required', 'integer', 'min:1'],
            'view' => ['nullable', 'string'],
        ]);

        return Inertia::render('Risks/CellDrilldown', [
            'risks' => $this->heatmapService->cellRisks(
                $request->user()->tenant_id,
                $validated['view'] ?? 'residual',
                $validated['likelihood'],
                $validated['impact'],
                $request->only(['category_id', 'entity_id', 'owner_id', 'process_id', 'framework_id', 'breach_only']),
            ),
            'cell' => $validated + ['view' => $validated['view'] ?? 'residual'],
        ]);
    }
}
