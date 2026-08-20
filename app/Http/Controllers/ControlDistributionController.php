<?php

namespace App\Http\Controllers;

use App\Http\Requests\DistributionRequest;
use App\Models\Control;
use App\Models\ControlDistribution;
use App\Models\DistributionTask;
use App\Models\OrganisationUnit;
use App\Models\User;
use App\Services\DistributionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlDistributionController extends Controller
{
    public function __construct(private DistributionService $distributionService) {}

    /** Corporater-style distribution overview: org tree, stat tiles, progress. */
    public function index(Request $request)
    {
        $this->authorize('viewAny', ControlDistribution::class);

        $controlId = $request->integer('control_id') ?: null;
        $overview = $this->distributionService->overview($controlId);

        return Inertia::render('Controls/Distribution/Index', [
            'stats' => $overview['stats'],
            'distributions' => $overview['distributions'],
            'entities' => OrganisationUnit::orderBy('name')->get(['id', 'name', 'parent_id', 'type', 'head_user_id']),
            'groupControls' => Control::groupLevel()
                ->where('is_distributable', true)
                ->orderBy('control_ref')
                ->get(['id', 'control_ref', 'title', 'status']),
            'owners' => User::tenantPicker()->get(['id', 'name']),
            'filters' => $request->only(['control_id']),
        ]);
    }

    public function show(ControlDistribution $distribution)
    {
        $this->authorize('view', $distribution);

        return Inertia::render('Controls/Distribution/Show', [
            'distribution' => $distribution->load([
                'control:id,control_ref,title,description,frequency,function_grouping,control_level',
                'entity:id,name,type',
                'childControl:id,control_ref,title,status,implementation_status,implementation_progress,owner_id',
                'childControl.owner:id,name',
                'assignedOwner:id,name',
                'distributor:id,name',
                'declineApprover:id,name',
                'tasks.owner:id,name',
                'tasks.completer:id,name',
            ]),
            'can' => [
                'acknowledge' => auth()->user()->can('acknowledge', $distribution),
                'progressTask' => auth()->user()->can('progressTask', $distribution),
                'requestDecline' => auth()->user()->can('requestDecline', $distribution),
                'decideDecline' => auth()->user()->can('decideDecline', $distribution),
            ],
        ]);
    }

    public function store(DistributionRequest $request, Control $control)
    {
        $this->authorize('distribute', ControlDistribution::class);

        $created = $this->distributionService->distribute(
            $control,
            $request->validated('entity_ids'),
            $request->user(),
            $request->only(['due_at', 'owner_ids', 'tasks']),
        );

        return redirect()
            ->route('distributions.index', ['control_id' => $control->id])
            ->with('success', "{$control->control_ref} distributed to {$created->count()} entities.");
    }

    public function acknowledge(ControlDistribution $distribution)
    {
        $this->authorize('acknowledge', $distribution);

        $this->distributionService->acknowledge($distribution, auth()->user());

        return back()->with('success', 'Distribution acknowledged.');
    }

    public function updateTask(Request $request, ControlDistribution $distribution, DistributionTask $task)
    {
        $this->authorize('progressTask', $distribution);

        abort_unless($task->distribution_id === $distribution->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'in:Open,In Progress,Complete,Blocked'],
            'blocker_reason' => ['required_if:status,Blocked', 'nullable', 'string'],
        ]);

        $this->distributionService->updateTask($task, $validated, $request->user());

        return back()->with('success', 'Task updated.');
    }

    public function requestDecline(Request $request, ControlDistribution $distribution)
    {
        $this->authorize('requestDecline', $distribution);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:10']]);

        $this->distributionService->requestDecline($distribution, $validated['reason'], $request->user());

        return back()->with('success', 'Decline requested — awaiting Control Function Head approval.');
    }

    public function decideDecline(Request $request, ControlDistribution $distribution)
    {
        $this->authorize('decideDecline', $distribution);

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string'],
        ]);

        $validated['decision'] === 'approve'
            ? $this->distributionService->approveDecline($distribution, $request->user())
            : $this->distributionService->rejectDecline($distribution, $request->user(), $validated['note'] ?? null);

        return back()->with('success', $validated['decision'] === 'approve'
            ? 'Decline approved — the control now reports as a coverage gap for this entity.'
            : 'Decline rejected — the distribution remains assigned.');
    }

    /** Preview a group-control change before propagating it (9.1). */
    public function propagationPreview(Request $request, Control $control)
    {
        $this->authorize('distribute', ControlDistribution::class);

        return response()->json(
            $this->distributionService->previewPropagation($control, $request->input('changes', [])),
        );
    }

    public function propagate(Request $request, Control $control)
    {
        $this->authorize('distribute', ControlDistribution::class);

        $validated = $request->validate([
            'changes' => ['required', 'array'],
            'mode' => ['required', 'in:propagate,notify_only'],
        ]);

        $summary = $this->distributionService->propagate(
            $control, $validated['changes'], $validated['mode'], $request->user(),
        );

        return back()->with('success',
            "Change applied to {$summary['updated']} entity controls; {$summary['preserved']} local adaptations preserved.");
    }
}
