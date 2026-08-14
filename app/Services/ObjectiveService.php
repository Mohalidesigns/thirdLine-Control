<?php

namespace App\Services;

use App\Models\Initiative;
use App\Models\InitiativeMilestone;
use App\Models\Metric;
use App\Models\MetricValue;
use App\Models\Objective;
use App\Models\ObjectiveMetric;
use Illuminate\Support\Collection;

/**
 * Strategy and performance alignment (17.1).
 *
 * The roll-up is the whole point of this service: an objective's progress
 * is derived from its measures (Phase 10 metrics), the initiatives
 * delivering it and its child objectives, each carrying its own weight.
 * None of the weights, bands or thresholds are coded — they are columns,
 * so a tenant that scores its scorecard differently changes data, not PHP
 * (R1).
 *
 * A leaf's own measures and initiatives count alongside its children's
 * roll-ups rather than being replaced by them, so a parent objective that
 * also carries a KRI of its own is not silently ignoring it.
 */
class ObjectiveService
{
    /** Progress bands used by the scorecard's RAG colouring. */
    public const BANDS = [
        ['from' => 90, 'label' => 'On track', 'colour' => '#0ca30c'],
        ['from' => 70, 'label' => 'Monitor', 'colour' => '#fab219'],
        ['from' => 40, 'label' => 'Behind', 'colour' => '#ec835a'],
        ['from' => 0, 'label' => 'At risk', 'colour' => '#d03b3b'],
    ];

    public function __construct(private LinkageService $linkage) {}

    // ── Roll-up ──────────────────────────────────────────────────────

    /**
     * Recompute one objective's progress from its measures, initiatives and
     * children, depth-first so a parent always reads freshly computed
     * children. Returns the percentage that was written (or, in manual
     * mode, the asserted number — the derived figure is still available
     * from breakdown()).
     */
    public function rollUp(Objective $objective, array &$seen = []): ?int
    {
        // A cycle in the parent chain would otherwise recurse forever. The
        // form request forbids one; this is the belt to that brace.
        if (isset($seen[$objective->id])) {
            return $objective->progress === null ? null : (int) $objective->progress;
        }

        $seen[$objective->id] = true;

        $contributions = $this->contributions($objective, $seen);
        $derived = $this->weightedAverage($contributions);

        if ($objective->progress_mode === 'manual') {
            return $objective->progress === null ? null : (int) $objective->progress;
        }

        // A null derived figure is written through as null: nothing measures
        // this objective, and "not measured" is the honest answer. Writing 0
        // would put it at the bottom of the board's scorecard for the crime
        // of not having been instrumented yet.
        $objective->forceFill([
            'progress' => $derived,
            'progress_calculated_at' => now(),
        ])->saveQuietly();

        return $derived;
    }

    /** Recompute every objective in a tenant, leaves before parents. */
    public function rollUpAll(?int $tenantId = null): int
    {
        $count = 0;
        $seen = [];

        Objective::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('deleted_at')
            ->orderByDesc('parent_objective_id')
            ->get()
            ->each(function (Objective $objective) use (&$count, &$seen) {
                $this->rollUp($objective, $seen);
                $count++;
            });

        return $count;
    }

    /**
     * The audit trail of a progress figure: every measure, initiative and
     * child with its weight and its own contribution. This is what the
     * objective page shows under the headline number, because a board that
     * cannot see how a number was built has to take it on faith.
     *
     * @return array{contributions: array<int, array<string, mixed>>, derived: int|null, mode: string, progress: int}
     */
    public function breakdown(Objective $objective): array
    {
        $contributions = $this->contributions($objective);

        return [
            'contributions' => $contributions,
            'derived' => $this->weightedAverage($contributions),
            'mode' => $objective->progress_mode,
            'progress' => $objective->progress === null ? null : (int) $objective->progress,
        ];
    }

    /**
     * @param  array<int, bool>  $seen
     * @return array<int, array<string, mixed>>
     */
    private function contributions(Objective $objective, array &$seen = []): array
    {
        $contributions = [];

        foreach ($this->measureContributions($objective) as $contribution) {
            $contributions[] = $contribution;
        }

        foreach ($objective->initiatives()->live()->get() as $initiative) {
            $contributions[] = [
                'type' => 'initiative',
                'id' => $initiative->id,
                'ref' => $initiative->reference,
                'label' => $initiative->title,
                'weight' => max(1, (int) $initiative->weight),
                'progress' => $this->initiativeProgress($initiative),
            ];
        }

        foreach ($objective->children()->open()->get() as $child) {
            $contributions[] = [
                'type' => 'objective',
                'id' => $child->id,
                'ref' => $child->code,
                'label' => $child->title,
                'weight' => max(1, (int) $child->weight),
                'progress' => $this->rollUp($child, $seen),
            ];
        }

        return $contributions;
    }

    /**
     * Measure contributions in one query for the readings rather than one
     * per measure (R6, no N+1).
     *
     * @return array<int, array<string, mixed>>
     */
    private function measureContributions(Objective $objective): array
    {
        $measures = $objective->measures()->with('metric:id,code,name,unit')->get();

        if ($measures->isEmpty()) {
            return [];
        }

        $latest = $this->latestReadings($measures->pluck('metric_id')->all());

        return $measures->map(function (ObjectiveMetric $measure) use ($latest) {
            $reading = $latest[$measure->metric_id] ?? null;

            return [
                'type' => 'metric',
                'id' => $measure->metric_id,
                'ref' => $measure->metric?->code,
                'label' => $measure->metric?->name,
                'weight' => max(1, (int) $measure->weight),
                'baseline' => $measure->baseline_value,
                'target' => $measure->target_value,
                'latest' => $reading,
                'unit' => $measure->metric?->unit,
                'progress' => $measure->achievement($reading),
            ];
        })->all();
    }

    /**
     * Latest reading per metric, one query for the set.
     *
     * @param  array<int, int>  $metricIds
     * @return array<int, float>
     */
    private function latestReadings(array $metricIds): array
    {
        if ($metricIds === []) {
            return [];
        }

        return MetricValue::withoutGlobalScopes()
            ->whereIn('metric_id', $metricIds)
            ->orderBy('period_start')
            ->get(['metric_id', 'value'])
            ->groupBy('metric_id')
            ->map(fn (Collection $values) => (float) $values->last()->value)
            ->all();
    }

    /**
     * An initiative in 'auto' mode takes its progress from its milestones,
     * weighted; in 'manual' mode the owner's figure stands.
     */
    public function initiativeProgress(Initiative $initiative): int
    {
        if ($initiative->progress_mode === 'manual') {
            return (int) $initiative->progress;
        }

        $milestones = $initiative->milestones()->get();

        if ($milestones->isEmpty()) {
            return (int) $initiative->progress;
        }

        $total = $milestones->sum(fn (InitiativeMilestone $milestone) => max(1, (int) $milestone->weight));
        $done = $milestones
            ->filter(fn (InitiativeMilestone $milestone) => $milestone->isComplete())
            ->sum(fn (InitiativeMilestone $milestone) => max(1, (int) $milestone->weight));

        $progress = $total > 0 ? (int) round($done / $total * 100) : 0;

        if ($progress !== (int) $initiative->progress) {
            $initiative->forceFill(['progress' => $progress])->saveQuietly();
        }

        return $progress;
    }

    /**
     * Weighted mean of the contributions that carry a number. A measure
     * with no target and no reading is excluded rather than counted as
     * zero — an unmeasured objective is unknown, not failing.
     *
     * @param  array<int, array<string, mixed>>  $contributions
     */
    private function weightedAverage(array $contributions): ?int
    {
        $weight = 0;
        $sum = 0;

        foreach ($contributions as $contribution) {
            if ($contribution['progress'] === null) {
                continue;
            }

            $weight += $contribution['weight'];
            $sum += $contribution['progress'] * $contribution['weight'];
        }

        return $weight > 0 ? (int) round($sum / $weight) : null;
    }

    // ── Views ────────────────────────────────────────────────────────

    /**
     * The strategy map: perspectives as rows, objectives as nodes, parent
     * edges plus the risks that threaten each objective and whether the
     * controls over those risks are effective (17.1).
     *
     * @return array<string, mixed>
     */
    public function strategyMap(?string $period = null, ?int $entityId = null): array
    {
        $objectives = Objective::query()
            ->with(['owner:id,name', 'entity:id,legal_name'])
            ->when($period, fn ($q, $p) => $q->where('period', $p))
            ->when($entityId, fn ($q, $e) => $q->where('entity_id', $e))
            ->orderBy('perspective')
            ->orderBy('code')
            ->get();

        $threats = $this->threatsFor($objectives);

        $rows = collect(Objective::PERSPECTIVES)->map(fn (string $perspective) => [
            'key' => $perspective,
            'label' => Objective::PERSPECTIVE_LABELS[$perspective],
            'objectives' => $objectives
                ->where('perspective', $perspective)
                ->map(fn (Objective $objective) => [
                    'id' => $objective->id,
                    'code' => $objective->code,
                    'title' => $objective->title,
                    'status' => $objective->status,
                    'progress' => $objective->progress === null ? null : (int) $objective->progress,
                    'band' => $this->band($objective->progress),
                    'parent_objective_id' => $objective->parent_objective_id,
                    'owner' => $objective->owner?->name,
                    'entity' => $objective->entity?->legal_name,
                    'threats' => $threats[$objective->id] ?? ['risks' => 0, 'ineffective_controls' => 0],
                ])
                ->values()
                ->all(),
        ])->all();

        return [
            'rows' => $rows,
            'edges' => $objectives
                ->filter(fn (Objective $objective) => $objective->parent_objective_id !== null)
                ->map(fn (Objective $objective) => [
                    'from' => $objective->parent_objective_id,
                    'to' => $objective->id,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Risks linked to each objective and how many of the controls over
     * those risks are rated ineffective — the "what threatens this
     * objective, and are we covered" question, answered in two queries
     * over the linkage graph rather than one per objective.
     *
     * @param  Collection<int, Objective>  $objectives
     * @return array<int, array{risks: int, ineffective_controls: int}>
     */
    private function threatsFor(Collection $objectives): array
    {
        if ($objectives->isEmpty()) {
            return [];
        }

        $riskIdsByObjective = $this->linkage->neighbourIds(
            'objective', $objectives->pluck('id')->all(), 'risk'
        );

        $allRiskIds = collect($riskIdsByObjective)->flatten()->unique()->values()->all();
        $ineffectiveByRisk = $this->linkage->ineffectiveControlCounts($allRiskIds);

        $threats = [];

        foreach ($objectives as $objective) {
            $riskIds = $riskIdsByObjective[$objective->id] ?? [];

            $threats[$objective->id] = [
                'risks' => count($riskIds),
                'ineffective_controls' => array_sum(array_map(
                    fn (int $riskId) => $ineffectiveByRisk[$riskId] ?? 0,
                    $riskIds,
                )),
            ];
        }

        return $threats;
    }

    /**
     * The balanced scorecard: one block per perspective with its weighted
     * position, its objectives and their measures.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scorecard(?string $period = null, ?int $entityId = null): array
    {
        $objectives = Objective::query()
            ->with(['owner:id,name', 'measures.metric:id,code,name,unit'])
            ->when($period, fn ($q, $p) => $q->where('period', $p))
            ->when($entityId, fn ($q, $e) => $q->where('entity_id', $e))
            ->orderBy('code')
            ->get();

        $readings = $this->latestReadings(
            $objectives->flatMap(fn (Objective $objective) => $objective->measures->pluck('metric_id'))
                ->unique()
                ->values()
                ->all()
        );

        return collect(Objective::PERSPECTIVES)->map(function (string $perspective) use ($objectives, $readings) {
            $inPerspective = $objectives->where('perspective', $perspective);

            // Only measured objectives carry weight in the perspective's
            // position. An unmeasured one is excluded rather than dragging
            // the perspective down as a zero.
            $measured = $inPerspective->filter(fn (Objective $objective) => $objective->progress !== null);

            $weight = $measured->sum(fn (Objective $objective) => max(1, (int) $objective->weight));
            $sum = $measured->sum(
                fn (Objective $objective) => (int) $objective->progress * max(1, (int) $objective->weight)
            );
            $progress = $weight > 0 ? (int) round($sum / $weight) : null;

            return [
                'key' => $perspective,
                'label' => Objective::PERSPECTIVE_LABELS[$perspective],
                'progress' => $progress,
                'band' => $progress === null ? null : $this->band($progress),
                'unmeasured' => $inPerspective->count() - $measured->count(),
                'objectives' => $inPerspective->map(fn (Objective $objective) => [
                    'id' => $objective->id,
                    'code' => $objective->code,
                    'title' => $objective->title,
                    'status' => $objective->status,
                    'progress' => $objective->progress === null ? null : (int) $objective->progress,
                    'band' => $this->band($objective->progress),
                    'weight' => (int) $objective->weight,
                    'owner' => $objective->owner?->name,
                    'target_date' => $objective->target_date?->toDateString(),
                    'measures' => $objective->measures->map(fn (ObjectiveMetric $measure) => [
                        'code' => $measure->metric?->code,
                        'name' => $measure->metric?->name,
                        'unit' => $measure->metric?->unit,
                        'baseline' => $measure->baseline_value,
                        'target' => $measure->target_value,
                        'latest' => $readings[$measure->metric_id] ?? null,
                        'achievement' => $measure->achievement($readings[$measure->metric_id] ?? null),
                    ])->values()->all(),
                ])->values()->all(),
            ];
        })->all();
    }

    /**
     * The RAG band for a progress figure. A null figure has no band — it is
     * not measured, which is neither on track nor at risk.
     *
     * @return array{label: string, colour: string}
     */
    public function band(?int $progress): array
    {
        if ($progress === null) {
            return ['label' => 'Not measured', 'colour' => '#a0aec0'];
        }

        foreach (self::BANDS as $band) {
            if ($progress >= $band['from']) {
                return ['label' => $band['label'], 'colour' => $band['colour']];
            }
        }

        return ['label' => 'At risk', 'colour' => '#d03b3b'];
    }

    /** Distinct planning periods in use, for the period filter. */
    public function periods(): array
    {
        return Objective::query()
            ->whereNotNull('period')
            ->distinct()
            ->orderByDesc('period')
            ->pluck('period')
            ->all();
    }

    /** Attach a metric to an objective as one of its measures. */
    public function addMeasure(Objective $objective, Metric $metric, array $data): ObjectiveMetric
    {
        $measure = ObjectiveMetric::updateOrCreate(
            ['objective_id' => $objective->id, 'metric_id' => $metric->id],
            [
                'tenant_id' => $objective->tenant_id,
                'baseline_value' => $data['baseline_value'] ?? null,
                'target_value' => $data['target_value'] ?? null,
                'weight' => $data['weight'] ?? 1,
                'note' => $data['note'] ?? null,
            ],
        );

        // The measure is also an edge in the linkage graph, so the metric
        // page shows the objective it serves without a second lookup.
        $this->linkage->link('objective', $objective->id, 'metric', $metric->id, 'relates_to', null, 'Scorecard measure.');

        $this->rollUp($objective->fresh());

        return $measure;
    }

    public function removeMeasure(ObjectiveMetric $measure): void
    {
        $objective = $measure->objective;
        $measure->auditAction('measure-removed', $measure->getAttributes(), null);
        $measure->delete();

        if ($objective) {
            $this->rollUp($objective->fresh());
        }
    }
}
