<?php

namespace App\Http\Controllers;

use App\Http\Requests\ObligationRequest;
use App\Models\ObligationInstance;
use App\Models\OrganisationUnit;
use App\Models\Regulator;
use App\Models\RegulatoryObligation;
use App\Models\User;
use App\Services\ObligationService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ObligationController extends Controller
{
    public function __construct(private ObligationService $obligations) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RegulatoryObligation::class);

        $query = RegulatoryObligation::query()
            ->with(['regulator:id,code,name,jurisdiction', 'framework:id,code'])
            ->withCount('assignments')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('obligation_ref', 'like', "%{$s}%")))
            ->when($request->regulator_id, fn ($q, $r) => $q->where('regulator_id', $r))
            ->when($request->obligation_type, fn ($q, $t) => $q->where('obligation_type', $t))
            ->when($request->frequency, fn ($q, $f) => $q->where('frequency', $f))
            ->when($request->verification_status, fn ($q, $v) => $q->where('verification_status', $v))
            ->when($request->boolean('active_only'), fn ($q) => $q->active());

        return Inertia::render('Obligations/Index', [
            'obligations' => $query->orderBy('obligation_ref')->paginate(20)->withQueryString(),
            'filters' => $request->only(['search', 'regulator_id', 'obligation_type', 'frequency', 'verification_status', 'active_only']),
            'regulators' => Regulator::active()->orderBy('name')->get(['id', 'code', 'name']),
            'types' => RegulatoryObligation::TYPES,
            'frequencies' => RegulatoryObligation::FREQUENCIES,
            'verificationStatuses' => ['verified', 'unverified', 'draft'],
            'stats' => [
                'total' => RegulatoryObligation::query()->active()->count(),
                'verified' => RegulatoryObligation::query()->active()->verified()->count(),
                'assigned' => RegulatoryObligation::query()->has('assignments')->count(),
                'overdue' => ObligationInstance::query()->overdue()->count(),
            ],
            'exposure' => $this->obligations->exposureByCurrency(),
            'can' => [
                'manage' => $request->user()->can('manage obligations'),
                'assign' => $request->user()->can('assign obligations'),
            ],
        ]);
    }

    /**
     * The compliance calendar. Instances for the requested window, plus a
     * per-day filing-density strip so crunch periods are visible at a glance.
     */
    public function calendar(Request $request): Response
    {
        $this->authorize('viewAny', ObligationInstance::class);

        $view = in_array($request->view, ['month', 'quarter', 'year'], true) ? $request->view : 'quarter';
        $anchor = $request->date ? Carbon::parse($request->date) : now();

        [$from, $to] = match ($view) {
            'month' => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
            'year' => [$anchor->copy()->startOfYear(), $anchor->copy()->endOfYear()],
            default => [$anchor->copy()->startOfQuarter(), $anchor->copy()->endOfQuarter()],
        };

        $instances = ObligationInstance::query()
            ->with([
                'obligation:id,obligation_ref,title,regulator_id,obligation_type,verification_status,penalty_currency',
                'obligation.regulator:id,code,name',
                'assignment.entity:id,name',
                'assignment.owner:id,name',
            ])
            ->dueBetween($from->toDateTimeString(), $to->toDateTimeString())
            ->when($request->regulator_id, fn ($q, $r) => $q->whereHas('obligation', fn ($w) => $w->where('regulator_id', $r)))
            ->when($request->entity_id, fn ($q, $e) => $q->whereHas('assignment', fn ($w) => $w->where('entity_id', $e)))
            ->when($request->owner_id, fn ($q, $o) => $q->whereHas('assignment', fn ($w) => $w->where('owner_id', $o)))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('due_at')
            ->get();

        return Inertia::render('Obligations/Calendar', [
            'view' => $view,
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'anchor' => $anchor->toDateString()],
            'instances' => $instances,
            'density' => $instances->groupBy(fn ($i) => $i->due_at->toDateString())->map->count(),
            'filters' => $request->only(['regulator_id', 'entity_id', 'owner_id', 'status', 'date', 'view']),
            'regulators' => Regulator::active()->orderBy('name')->get(['id', 'name']),
            'entities' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'owners' => User::tenantPicker()->get(['id', 'name']),
            'statuses' => ObligationInstance::STATUSES,
            'exposure' => $this->obligations->exposureByCurrency(),
        ]);
    }

    public function show(Request $request, RegulatoryObligation $obligation): Response
    {
        $this->authorize('view', $obligation);

        $obligation->load(['regulator', 'framework:id,code,name', 'requirement:id,ref_code,title']);

        return Inertia::render('Obligations/Show', [
            'obligation' => $obligation,
            'assignments' => $obligation->assignments()
                ->with(['entity:id,name,entity_type', 'owner:id,name', 'reviewer:id,name', 'approver:id,name'])
                ->get(),
            'instances' => $obligation->instances()
                ->with(['assignment.entity:id,name', 'submitter:id,name'])
                ->orderByDesc('due_at')->limit(24)->get(),
            'entities' => OrganisationUnit::orderBy('name')->get(['id', 'name', 'entity_type', 'licence_categories']),
            'users' => User::tenantPicker()->get(['id', 'name']),
            'can' => [
                'manage' => $request->user()->can('update', $obligation),
                'assign' => $request->user()->can('assign', $obligation),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', RegulatoryObligation::class);

        return Inertia::render('Obligations/Create', $this->formOptions());
    }

    public function store(ObligationRequest $request): RedirectResponse
    {
        $this->authorize('create', RegulatoryObligation::class);

        $obligation = RegulatoryObligation::create([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
        ]);

        return redirect()->route('obligations.show', $obligation)
            ->with('success', "Obligation {$obligation->obligation_ref} created.");
    }

    public function edit(RegulatoryObligation $obligation): Response
    {
        $this->authorize('update', $obligation);

        return Inertia::render('Obligations/Edit', [
            'obligation' => $obligation,
            ...$this->formOptions(),
        ]);
    }

    public function update(ObligationRequest $request, RegulatoryObligation $obligation): RedirectResponse
    {
        $this->authorize('update', $obligation);

        $obligation->update($request->validated());

        return redirect()->route('obligations.show', $obligation)
            ->with('success', "Obligation {$obligation->obligation_ref} updated.");
    }

    /**
     * Which shipped obligations bind a given entity, per its regulatory
     * profile. Drives the "assign everything applicable" flow.
     */
    public function applicability(Request $request, OrganisationUnit $entity): Response
    {
        $this->authorize('viewAny', RegulatoryObligation::class);

        $applicable = $this->obligations->applicableObligations($entity);
        $assigned = $entity->obligationAssignments()->pluck('obligation_id')->all();

        return Inertia::render('Obligations/Applicability', [
            'entity' => $entity->only(['id', 'name', 'entity_type', 'licence_categories', 'jurisdiction']),
            'obligations' => $applicable->map(fn (RegulatoryObligation $o) => [
                'id' => $o->id,
                'obligation_ref' => $o->obligation_ref,
                'title' => $o->title,
                'regulator' => $o->regulator?->name,
                'obligation_type' => $o->obligation_type,
                'frequency' => $o->frequency,
                'verification_status' => $o->verification_status,
                'is_assigned' => in_array($o->id, $assigned, true),
            ])->values(),
            'users' => User::tenantPicker()->get(['id', 'name']),
            'can' => ['assign' => $request->user()->can('assign obligations')],
        ]);
    }

    private function formOptions(): array
    {
        return [
            'regulators' => Regulator::active()->orderBy('name')->get(['id', 'code', 'name', 'jurisdiction']),
            'types' => RegulatoryObligation::TYPES,
            'triggerTypes' => RegulatoryObligation::TRIGGER_TYPES,
            'frequencies' => RegulatoryObligation::FREQUENCIES,
            'penaltyBases' => RegulatoryObligation::PENALTY_BASES,
            'currencies' => Money::SUPPORTED,
            'verificationStatuses' => ['verified', 'unverified', 'draft'],
        ];
    }
}
