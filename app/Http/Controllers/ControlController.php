<?php

namespace App\Http\Controllers;

use App\Http\Requests\ControlRequest;
use App\Models\BusinessProcess;
use App\Models\Control;
use App\Models\ControlCategory;
use App\Models\ControlStakeholder;
use App\Models\ImprovementAction;
use App\Models\Investigation;
use App\Models\InvestigationFinding;
use App\Models\OrganisationUnit;
use App\Models\User;
use App\Services\ControlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ControlController extends Controller
{
    public function __construct(private ControlService $controlService) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Control::class);

        $query = Control::query()
            ->where('is_template', false)
            ->with(['category', 'process', 'unit', 'owner'])
            ->withCount('entityInstances')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('control_ref', 'like', "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->nature, fn ($q, $n) => $q->where('nature', $n))
            ->when($request->design, fn ($q, $d) => $q->where('design_effectiveness', $d))
            ->when($request->operating, fn ($q, $o) => $q->where('operating_effectiveness', $o))
            // Phase 13: the control-health tile drills through on this, and a
            // filter the aggregate emits but the list ignores is drift.
            ->when($request->overall, fn ($q, $o) => $q->where('overall_rating', $o))
            ->when($request->category_id, fn ($q, $c) => $q->where('category_id', $c))
            // CR2A.4: the unit-scoped register also lists controls SHARED
            // with the unit (co-owner / contributor stakeholder), badged
            // "Shared" in the UI. Consulted units see nothing extra.
            ->when($request->unit_id, fn ($q, $u) => $q->where(fn ($w) => $w
                ->where('unit_id', $u)
                ->orWhereHas('stakeholders', fn ($s) => $s
                    ->where('organisation_unit_id', $u)
                    ->whereIn('role', ControlStakeholder::SHARED_ROLES))))
            ->when($request->library_level, fn ($q, $l) => $q->where('library_level', $l))
            ->when($request->function_grouping, fn ($q, $g) => $q->where('function_grouping', $g))
            ->when($request->control_level, fn ($q, $l) => $q->where('control_level', $l))
            ->when($request->implementation_status, fn ($q, $s) => $q->where('implementation_status', $s));

        $user = $request->user();

        if ($user->hasRole('Control Owner') && ! $user->hasAnyRole(['Control Officer', 'Control Function Head', 'System Administrator', 'Executive Viewer', 'Line Manager'])) {
            $query->where('owner_id', $user->id);
        }

        $statsBase = Control::query()->where('is_template', false);

        $controls = $query->orderBy('control_ref')->paginate(15)->withQueryString();

        // Flag rows that appear in the unit filter only through a
        // stakeholder share, so the register can badge them.
        if ($request->unit_id) {
            $controls->getCollection()->transform(function (Control $control) use ($request) {
                $control->setAttribute('is_shared', (int) $control->unit_id !== (int) $request->unit_id);

                return $control;
            });
        }

        return Inertia::render('Controls/Index', [
            'controls' => $controls,
            'filters' => $request->only(['search', 'status', 'type', 'nature', 'design', 'operating', 'category_id', 'unit_id',
                'library_level', 'function_grouping', 'control_level', 'implementation_status']),
            'categories' => ControlCategory::available()->orderBy('name')->get(['id', 'name']),
            'units' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'statuses' => Control::STATUSES,
            'types' => Control::TYPES,
            'natures' => Control::NATURES,
            'designRatings' => Control::DESIGN_RATINGS,
            'operatingRatings' => Control::OPERATING_RATINGS,
            'functionGroupings' => Control::FUNCTION_GROUPINGS,
            'controlLevels' => Control::CONTROL_LEVELS,
            'implementationStatuses' => Control::IMPLEMENTATION_STATUSES,
            'stats' => [
                'total' => (clone $statsBase)->count(),
                'designAdequate' => (clone $statsBase)->where('design_effectiveness', 'Adequate')->count(),
                'designInadequate' => (clone $statsBase)->where('design_effectiveness', 'Inadequate')->count(),
                'operatingEffective' => (clone $statsBase)->where('operating_effectiveness', 'Effective')->count(),
                'operatingIneffective' => (clone $statsBase)->where('operating_effectiveness', 'Ineffective')->count(),
                'notTested' => (clone $statsBase)->where('operating_effectiveness', 'Not Tested')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Control::class);

        return Inertia::render('Controls/Create', [
            'nextRef' => Control::nextReference('CTL', false, 'control_ref'),
            ...$this->formOptions(),
        ]);
    }

    public function store(ControlRequest $request): RedirectResponse
    {
        $this->authorize('create', Control::class);

        $control = $this->controlService->create($request->validated(), $request->user());

        return redirect()->route('controls.show', $control)
            ->with('success', "Control {$control->control_ref} created as draft.");
    }

    public function show(Control $control): Response
    {
        $this->authorize('view', $control);

        $control->load([
            'category', 'process', 'unit', 'owner', 'creator', 'approver',
            'versions.author', 'risks', 'testScripts.checkItems',
            // CR2-A: the structure panel and the stakeholders panel.
            'controlEntities.controlUnit:id,code,name',
            'stakeholders.organisationUnit:id,name,head_user_id',
            'stakeholders.contact:id,name',
        ]);

        return Inertia::render('Controls/Show', [
            'control' => $control,
            'recentTests' => $control->testInstances()->with('tester')->latest('period_start')->limit(10)->get(),
            'openExceptions' => $control->exceptions()->open()->latest('date_raised')->limit(10)->get(),
            'ratings' => $control->effectivenessRatings()->where('status', 'Published')->latest('approved_at')->limit(12)->get(),
            'improvements' => ImprovementAction::where('control_id', $control->id)
                ->with(['owner:id,name'])
                ->latest()
                ->limit(10)
                ->get(),
            'assessments' => $control->assessments()->with('assessor')->limit(24)->get(),
            'designRatings' => array_values(array_diff(Control::DESIGN_RATINGS, ['Not Assessed'])),
            'operatingRatings' => array_values(array_diff(Control::OPERATING_RATINGS, ['Not Tested'])),
            'stakeholderUnits' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'stakeholderUsers' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stakeholderRoles' => array_values(array_diff(ControlStakeholder::ROLES, ['owner'])),
            // CR-04 §F.2: "has this control ever been implicated in an
            // investigation?" — a question the exception register alone
            // cannot answer, because a fraud investigation often reveals a
            // control that was never tested. The findings resolve through
            // the investigation's own visibility scope, so a confidential
            // matter contributes nothing to a control page its reader
            // could not open.
            'investigationFindings' => auth()->user()->can('view investigations')
                ? InvestigationFinding::query()
                    ->whereIn('investigation_id', Investigation::query()->select('id'))
                    ->where('control_id', $control->id)
                    ->with(['investigation:id,reference,title,status,risk_rating,is_confidential', 'improvementAction:id,reference,status'])
                    ->latest('established_on')
                    ->limit(10)
                    ->get()
                : [],
            'can' => [
                'update' => auth()->user()->can('update', $control),
                'approve' => auth()->user()->can('approve', $control),
                'retire' => auth()->user()->can('retire', $control),
                'assess' => auth()->user()->can('assess', $control),
                'manageStakeholders' => auth()->user()->can('manage control-stakeholders'),
                'viewStructure' => auth()->user()->can('view control-structure'),
            ],
        ]);
    }

    public function assess(Request $request, Control $control): RedirectResponse
    {
        $this->authorize('assess', $control);

        $data = $request->validate([
            'design_result' => ['nullable', 'required_without:operating_result', Rule::in(Control::DESIGN_RATINGS)],
            'operating_result' => ['nullable', 'required_without:design_result', Rule::in(Control::OPERATING_RATINGS)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'period_label' => ['nullable', 'string', 'max:50'],
        ]);

        $this->controlService->assess($control, $request->user(), $data);

        return back()->with('success', "Assessment recorded for {$control->control_ref}.");
    }

    public function edit(Control $control): Response
    {
        $this->authorize('update', $control);

        return Inertia::render('Controls/Edit', [
            'control' => $control,
            ...$this->formOptions(),
        ]);
    }

    public function update(ControlRequest $request, Control $control): RedirectResponse
    {
        $this->authorize('update', $control);

        $data = $request->validated();
        $reason = $data['change_reason'] ?? 'Amendment';
        unset($data['change_reason']);

        $this->controlService->amend($control, $data, $request->user(), $reason);

        return redirect()->route('controls.show', $control)
            ->with('success', "Control {$control->control_ref} amended (v{$control->current_version}).");
    }

    public function submit(Control $control): RedirectResponse
    {
        $this->authorize('update', $control);

        $this->controlService->submitForApproval($control);

        return back()->with('success', 'Control submitted for approval.');
    }

    public function approve(Request $request, Control $control): RedirectResponse
    {
        $this->authorize('approve', $control);

        $this->controlService->approve($control, $request->user());

        return back()->with('success', "Control {$control->control_ref} approved and active.");
    }

    public function reject(Request $request, Control $control): RedirectResponse
    {
        $this->authorize('approve', $control);

        $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->controlService->reject($control, $request->string('reason'));

        return back()->with('warning', 'Control returned to draft.');
    }

    public function retire(Control $control): RedirectResponse
    {
        $this->authorize('retire', $control);

        $this->controlService->retire($control);

        return back()->with('success', "Control {$control->control_ref} retired.");
    }

    public function templates(): Response
    {
        $this->authorize('create', Control::class);

        return Inertia::render('Controls/Templates', [
            'templates' => Control::withoutGlobalScope('tenant')
                ->where('is_template', true)
                ->with('category')
                ->orderBy('title')
                ->paginate(15),
        ]);
    }

    public function adopt(Request $request, int $templateId): RedirectResponse
    {
        $this->authorize('create', Control::class);

        $template = Control::withoutGlobalScope('tenant')
            ->where('is_template', true)
            ->findOrFail($templateId);

        $control = $this->controlService->adoptTemplate($template, $request->user());

        return redirect()->route('controls.edit', $control)
            ->with('success', "Template adopted as {$control->control_ref} — review and complete the details.");
    }

    private function formOptions(): array
    {
        return [
            'categories' => ControlCategory::available()->orderBy('name')->get(['id', 'name']),
            'processes' => BusinessProcess::orderBy('name')->get(['id', 'name']),
            'units' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'users' => User::tenantPicker()->get(['id', 'name']),
            'types' => Control::TYPES,
            'natures' => Control::NATURES,
            'frequencies' => Control::FREQUENCIES,
            'cosoComponents' => Control::COSO_COMPONENTS,
            'functionGroupings' => Control::FUNCTION_GROUPINGS,
            'controlLevels' => Control::CONTROL_LEVELS,
        ];
    }
}
