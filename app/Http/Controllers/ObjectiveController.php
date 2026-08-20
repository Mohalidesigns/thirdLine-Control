<?php

namespace App\Http\Controllers;

use App\Http\Requests\ObjectiveRequest;
use App\Models\Entity;
use App\Models\Initiative;
use App\Models\Metric;
use App\Models\Objective;
use App\Models\ObjectiveMetric;
use App\Models\User;
use App\Services\LinkageService;
use App\Services\ObjectiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ObjectiveController extends Controller
{
    public function __construct(
        private ObjectiveService $objectives,
        private LinkageService $linkage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Objective::class);

        $objectives = Objective::query()
            ->with(['owner:id,name', 'entity:id,legal_name', 'parent:id,code,title'])
            ->withCount(['initiatives', 'measures'])
            ->when($request->perspective, fn ($q, $p) => $q->where('perspective', $p))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->period, fn ($q, $p) => $q->where('period', $p))
            ->when($request->entity_id, fn ($q, $e) => $q->where('entity_id', $e))
            ->when($request->owner_id, fn ($q, $o) => $q->where('owner_id', $o))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")))
            ->orderBy('perspective')
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Objectives/Index', [
            'objectives' => $objectives->through(fn (Objective $objective) => [
                ...$objective->toArray(),
                'perspective_label' => $objective->perspectiveLabel(),
                'band' => $this->objectives->band($objective->progress),
                'days_to_target' => $objective->daysToTarget(),
            ]),
            'filters' => $request->only(['perspective', 'status', 'period', 'entity_id', 'owner_id', 'search']),
            'perspectives' => collect(Objective::PERSPECTIVES)
                ->map(fn ($p) => ['value' => $p, 'label' => Objective::PERSPECTIVE_LABELS[$p]])
                ->all(),
            'statuses' => Objective::STATUSES,
            'periods' => $this->objectives->periods(),
            'entities' => Entity::query()->orderBy('legal_name')->get(['id', 'legal_name']),
            'owners' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'parents' => Objective::query()->orderBy('code')->get(['id', 'code', 'title']),
            'metrics' => Metric::query()->active()->orderBy('code')->get(['id', 'code', 'name', 'unit']),
            'stats' => [
                'active' => Objective::open()->count(),
                'at_risk' => Objective::where('status', 'At Risk')->count(),
                'achieved' => Objective::where('status', 'Achieved')->count(),
                'initiatives' => Initiative::live()->count(),
            ],
            'can' => [
                'manage' => $request->user()->can('manage objectives'),
                'approve' => $request->user()->can('approve objectives'),
                'manage_initiatives' => $request->user()->can('manage initiatives'),
            ],
        ]);
    }

    public function store(ObjectiveRequest $request): RedirectResponse
    {
        $this->authorize('create', Objective::class);

        $data = $request->validated();
        $measures = $data['measures'] ?? [];
        unset($data['measures']);

        $objective = Objective::create([
            ...$data,
            'status' => 'Draft',
            'created_by' => $request->user()->id,
        ]);

        $this->syncMeasures($objective, $measures);

        return redirect()->route('objectives.show', $objective)
            ->with('success', "Objective {$objective->code} drafted — it joins the scorecard once a second person approves it.");
    }

    public function show(Request $request, Objective $objective): Response
    {
        $this->authorize('view', $objective);

        $objective->load([
            'owner:id,name', 'entity:id,legal_name', 'parent:id,code,title',
            'children:id,code,title,status,progress,parent_objective_id',
            'measures.metric:id,code,name,unit',
            'initiatives.owner:id,name', 'initiatives.milestones',
        ]);

        return Inertia::render('Objectives/Show', [
            'objective' => [
                ...$objective->toArray(),
                'perspective_label' => $objective->perspectiveLabel(),
                'band' => $this->objectives->band($objective->progress),
                'days_to_target' => $objective->daysToTarget(),
            ],
            'breakdown' => $this->objectives->breakdown($objective),
            'links' => $this->linkage->neighbours('objective', $objective->id),
            'metrics' => Metric::query()->active()->orderBy('code')->get(['id', 'code', 'name', 'unit']),
            'owners' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => Objective::STATUSES,
            'initiativeStatuses' => Initiative::STATUSES,
            'can' => [
                'manage' => $request->user()->can('update', $objective),
                'approve' => $request->user()->can('approve', $objective),
                'report' => $request->user()->can('reportProgress', $objective),
                'manage_initiatives' => $request->user()->can('create', Initiative::class),
            ],
        ]);
    }

    public function update(ObjectiveRequest $request, Objective $objective): RedirectResponse
    {
        $this->authorize('update', $objective);

        $data = $request->validated();
        $measures = $data['measures'] ?? null;
        unset($data['measures']);

        $objective->update($data);

        if ($measures !== null) {
            $this->syncMeasures($objective, $measures);
        }

        $this->objectives->rollUp($objective->fresh());

        return back()->with('success', "Objective {$objective->code} updated.");
    }

    /**
     * Activate a drafted objective. The policy refuses the drafter, so an
     * objective cannot enter the board's scorecard on one person's say-so
     * (R2).
     */
    public function approve(Request $request, Objective $objective): RedirectResponse
    {
        $this->authorize('approve', $objective);

        $objective->update(['status' => 'Active']);
        $objective->auditAction('approved', ['status' => 'Draft'], [
            'status' => 'Active',
            'approved_by' => $request->user()->id,
        ]);

        return back()->with('success', "Objective {$objective->code} is live on the scorecard.");
    }

    /** An owner reporting where their objective stands. */
    public function progress(Request $request, Objective $objective): RedirectResponse
    {
        $this->authorize('reportProgress', $objective);

        $validated = $request->validate([
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            'status' => ['required', 'in:'.implode(',', Objective::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = ['progress' => (int) $objective->progress, 'status' => $objective->status];

        $objective->update([
            'progress' => $validated['progress'],
            'status' => $validated['status'],
            'progress_mode' => 'manual',
            'progress_calculated_at' => now(),
        ]);

        $objective->auditAction('progress-reported', $before, [
            ...$validated,
            'by' => $request->user()->id,
        ]);

        return back()->with('success', 'Progress recorded.');
    }

    /** Switch back to the derived figure and recompute it now. */
    public function recalculate(Request $request, Objective $objective): RedirectResponse
    {
        $this->authorize('reportProgress', $objective);

        $objective->update(['progress_mode' => 'auto']);
        $progress = $this->objectives->rollUp($objective->fresh());

        return back()->with('success', $progress === null
            ? 'Returned to roll-up — nothing measures this objective yet, so it reads as not measured rather than 0%.'
            : "Rolled up from measures and initiatives — {$progress}%.");
    }

    public function storeMeasure(Request $request, Objective $objective): RedirectResponse
    {
        $this->authorize('update', $objective);

        $validated = $request->validate([
            'metric_id' => ['required', 'exists:metrics,id'],
            'baseline_value' => ['nullable', 'numeric'],
            'target_value' => ['nullable', 'numeric'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $metric = Metric::findOrFail($validated['metric_id']);
        $this->objectives->addMeasure($objective, $metric, $validated);

        return back()->with('success', "{$metric->code} added as a measure.");
    }

    public function destroyMeasure(Objective $objective, ObjectiveMetric $measure): RedirectResponse
    {
        $this->authorize('update', $objective);
        abort_unless($measure->objective_id === $objective->id, 404);

        $this->objectives->removeMeasure($measure);

        return back()->with('success', 'Measure removed.');
    }

    /** @param array<int, array<string, mixed>> $measures */
    private function syncMeasures(Objective $objective, array $measures): void
    {
        $keep = [];

        foreach ($measures as $measure) {
            $metric = Metric::find($measure['metric_id']);

            if (! $metric) {
                continue;
            }

            $this->objectives->addMeasure($objective, $metric, $measure);
            $keep[] = $metric->id;
        }

        $objective->measures()->whereNotIn('metric_id', $keep ?: [0])->get()
            ->each(fn (ObjectiveMetric $measure) => $this->objectives->removeMeasure($measure));
    }
}
