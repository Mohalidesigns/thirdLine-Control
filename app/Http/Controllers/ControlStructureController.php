<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachControlsRequest;
use App\Http\Requests\ControlEntityRequest;
use App\Http\Requests\ControlStakeholderRequest;
use App\Http\Requests\ControlUnitRequest;
use App\Models\BusinessProcess;
use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlException;
use App\Models\ControlStakeholder;
use App\Models\ControlUnit;
use App\Models\OrganisationUnit;
use App\Models\TestInstance;
use App\Models\User;
use App\Services\ControlStructureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CR2-A: the front door of the product for a control function — the
 * three sub-units and the control universe under them. Thin: every rule
 * lives in ControlStructureService.
 */
class ControlStructureController extends Controller
{
    public function __construct(private ControlStructureService $service) {}

    public function index(): Response
    {
        $this->authorize('viewAny', ControlUnit::class);

        return Inertia::render('ControlStructure/Index', [
            'units' => ControlUnit::query()->active()
                ->with('head:id,name')
                ->orderBy('sequence')->orderBy('name')
                ->get(),
            'counts' => $this->service->unitCounts(),
            'domains' => ControlUnit::DOMAINS,
            'can' => [
                'manage' => auth()->user()->can('manage control-structure'),
            ],
        ]);
    }

    public function unit(Request $request, ControlUnit $controlUnit): Response
    {
        $this->authorize('view', $controlUnit);

        $entities = ControlEntity::query()->active()
            ->where('control_unit_id', $controlUnit->id)
            ->whereNull('parent_id')
            ->with(['owner:id,name', 'organisationUnit:id,code,name,head_user_id', 'organisationUnit.head:id,name'])
            ->withCount(['controls', 'children'])
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('sequence')->orderBy('name')
            ->paginate(25)->withQueryString();

        return Inertia::render('ControlStructure/Unit', [
            'unit' => $controlUnit->load('head:id,name'),
            'entities' => $entities,
            // CR-03: control function count and 30-day completion rate per
            // desk or branch. One grouped query per metric, never one per
            // entity — at the client's branch count the alternative is a
            // few hundred queries to draw one table.
            'functionCounts' => $this->functionCountsFor($entities->pluck('id')->all()),
            'templates' => $controlUnit->isBranchDomain()
                ? ControlEntity::query()->templates()
                    ->where('control_unit_id', $controlUnit->id)
                    ->orderBy('sequence')->orderBy('name')
                    ->get(['id', 'name', 'sequence', 'is_active'])
                : [],
            'filters' => $request->only(['search']),
            'kinds' => ControlEntity::KINDS,
            'riskRatings' => ControlEntity::RISK_RATINGS,
            'reviewFrequencies' => ControlEntity::REVIEW_FREQUENCIES,
            'organisationUnits' => OrganisationUnit::orderBy('name')->get(['id', 'name', 'type']),
            'businessProcesses' => BusinessProcess::orderBy('name')->get(['id', 'name']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'manage' => auth()->user()->can('manage control-structure'),
            ],
        ]);
    }

    /**
     * CR-03 §E.2: how many control functions each entity executes and how
     * much of the last 30 days' work it completed.
     *
     * @param  array<int, int>  $entityIds
     * @return array<int, array{functions: int, tasks: int, completed: int, overdue: int, completion_rate: float}>
     */
    private function functionCountsFor(array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        $functions = DB::table('control_entity_control')
            ->join('controls', 'controls.id', '=', 'control_entity_control.control_id')
            ->whereIn('control_entity_control.control_entity_id', $entityIds)
            ->where('controls.is_control_function', true)
            ->whereNull('controls.deleted_at')
            ->groupBy('control_entity_control.control_entity_id')
            ->select('control_entity_control.control_entity_id as entity_id', DB::raw('count(*) as total'))
            ->pluck('total', 'entity_id');

        $tasks = DB::table('test_instances')
            ->whereIn('control_entity_id', $entityIds)
            ->whereNull('deleted_at')
            // Overlap, not containment — a monthly task that opened last
            // month is part of the last 30 days' work.
            ->whereDate('period_end', '>=', now()->subDays(30)->toDateString())
            ->whereDate('period_start', '<=', now()->toDateString())
            ->groupBy('control_entity_id')
            ->select(
                'control_entity_id as entity_id',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when status in ('Reviewed', 'Closed') then 1 else 0 end) as completed"),
                DB::raw("sum(case when status not in ('Reviewed', 'Closed') and due_date is not null and due_date < '".now()->toDateString()."' then 1 else 0 end) as overdue"),
            )
            ->get()
            ->keyBy('entity_id');

        $counts = [];

        foreach ($entityIds as $entityId) {
            $row = $tasks->get($entityId);
            $total = (int) ($row->total ?? 0);
            $completed = (int) ($row->completed ?? 0);

            $counts[$entityId] = [
                'functions' => (int) ($functions[$entityId] ?? 0),
                'tasks' => $total,
                'completed' => $completed,
                'overdue' => (int) ($row->overdue ?? 0),
                'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0.0,
            ];
        }

        return $counts;
    }

    public function entity(ControlEntity $controlEntity): Response
    {
        $this->authorize('view', $controlEntity);

        $controlEntity->load([
            'controlUnit:id,code,name,domain',
            'parent:id,name',
            'children' => fn ($q) => $q->active()->withCount('controls')->orderBy('sequence')->orderBy('name'),
            'organisationUnit:id,code,name,head_user_id',
            'organisationUnit.head:id,name',
            'businessProcess:id,name',
            'owner:id,name',
            'controls' => fn ($q) => $q->with(['unit:id,name', 'owner:id,name'])->orderBy('control_ref'),
        ]);

        $attachedIds = $controlEntity->controls->pluck('id');

        return Inertia::render('ControlStructure/Entity', [
            'entity' => $controlEntity,
            'exceptions' => ControlException::query()
                ->whereIn('control_id', $attachedIds)
                ->with('control:id,control_ref,title')
                ->orderByDesc('date_raised')
                ->limit(20)
                ->get(),
            'tests' => TestInstance::query()
                ->whereIn('control_id', $attachedIds)
                ->with(['control:id,control_ref,title', 'tester:id,name'])
                ->latest('period_start')
                ->limit(20)
                ->get(),
            'availableControls' => Control::query()
                ->where('is_template', false)
                ->whereNotIn('id', $attachedIds)
                ->with('unit:id,name')
                ->orderBy('control_ref')
                ->get(['id', 'control_ref', 'title', 'unit_id', 'is_key_control']),
            'can' => [
                'manage' => auth()->user()->can('manage control-structure'),
                'attach' => auth()->user()->can('attach control-entities'),
            ],
        ]);
    }

    // ── Writes: units ────────────────────────────────────────────────

    public function storeUnit(ControlUnitRequest $request): RedirectResponse
    {
        $this->authorize('create', ControlUnit::class);

        $unit = $this->service->createUnit($request->validated(), $request->user());

        return back()->with('success', "Control unit {$unit->name} created.");
    }

    public function updateUnit(ControlUnitRequest $request, ControlUnit $controlUnit): RedirectResponse
    {
        $this->authorize('update', $controlUnit);

        $this->service->updateUnit($controlUnit, $request->validated());

        return back()->with('success', "Control unit {$controlUnit->name} updated.");
    }

    // ── Writes: entities ─────────────────────────────────────────────

    public function storeEntity(ControlEntityRequest $request): RedirectResponse
    {
        $this->authorize('create', ControlEntity::class);

        $entity = $this->service->createEntity($request->validated(), $request->user());

        return back()->with('success', "{$entity->reference} — {$entity->name} added to the register.");
    }

    public function updateEntity(ControlEntityRequest $request, ControlEntity $controlEntity): RedirectResponse
    {
        $this->authorize('update', $controlEntity);

        $this->service->updateEntity($controlEntity, $request->validated());

        return back()->with('success', "{$controlEntity->reference} updated.");
    }

    public function deactivateEntity(ControlEntity $controlEntity): RedirectResponse
    {
        $this->authorize('update', $controlEntity);

        $this->service->deactivateEntity($controlEntity, auth()->user());

        return back()->with('success', "{$controlEntity->reference} deactivated.");
    }

    // ── Writes: control attachment ───────────────────────────────────

    public function attachControls(AttachControlsRequest $request, ControlEntity $controlEntity): RedirectResponse
    {
        $this->authorize('attach', $controlEntity);

        $count = $this->service->attachControls($controlEntity, $request->validated('attachments'), $request->user());

        return back()->with('success', "{$count} control(s) attached to {$controlEntity->name}.");
    }

    public function detachControl(ControlEntity $controlEntity, Control $control): RedirectResponse
    {
        $this->authorize('attach', $controlEntity);

        $this->service->detachControl($controlEntity, $control, auth()->user());

        return back()->with('success', "{$control->control_ref} detached from {$controlEntity->name}.");
    }

    // ── Writes: cross-functional stakeholders ────────────────────────

    public function addStakeholder(ControlStakeholderRequest $request, Control $control): RedirectResponse
    {
        $stakeholder = $this->service->addStakeholder($control, $request->validated(), $request->user());

        return back()->with('success', "{$stakeholder->organisationUnit?->name} added as ".str_replace('_', '-', $stakeholder->role).'.');
    }

    public function removeStakeholder(Control $control, ControlStakeholder $stakeholder): RedirectResponse
    {
        abort_unless($stakeholder->control_id === $control->id, 404);

        $this->service->removeStakeholder($stakeholder, auth()->user());

        return back()->with('success', 'Stakeholder removed.');
    }
}
