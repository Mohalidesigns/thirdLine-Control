<?php

namespace App\Services;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\EffectivenessRating;
use App\Models\Entity;
use App\Models\Incident;
use App\Models\ObligationInstance;
use App\Models\Risk;
use App\Models\User;
use App\Support\TenantCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Group-level consolidation across legal entities (16.1). Every aggregate
 * runs at the database, standalone per entity (a parent's own numbers
 * exclude units claimed by a descendant entity, so nothing is counted
 * twice), then weighted by the entity's consolidation method for the
 * group position. What a user sees is exactly the set of entities they
 * were granted — group totals are computed over that set, so a hidden
 * subsidiary's numbers cannot be inferred from the total (R2).
 */
class ConsolidationService
{
    public const CACHE_SUFFIX = 'consolidation:rollup';

    /** Seconds a computed rollup stays cached (invalidated on entity writes). */
    public const CACHE_TTL = 600;

    public function rollup(User $user): array
    {
        $all = TenantCache::remember(
            $user->tenant_id,
            self::CACHE_SUFFIX,
            self::CACHE_TTL,
            fn () => $this->computeAll(),
        );

        $granted = $user->accessibleEntityIds();

        $visible = array_values(array_filter($all, fn ($row) => in_array($row['id'], $granted, true)));

        $group = $this->groupPosition($visible);

        return [
            'entities' => $this->withBenchmarks($visible, $group),
            'group' => $group,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeAll(): array
    {
        $entities = Entity::query()
            ->where('status', '!=', 'divested')
            ->orderBy('legal_name')
            ->get();

        $ownUnits = $this->standaloneUnitSets($entities);
        $allUnitIds = array_values(array_unique(array_merge(...array_values($ownUnits) ?: [[]])));

        $stats = $this->unitStatistics($allUnitIds);

        return $entities->map(function (Entity $entity) use ($ownUnits, $stats) {
            $units = $ownUnits[$entity->id];

            $sum = fn (string $key) => array_sum(array_map(
                fn (int $unit) => $stats[$unit][$key] ?? 0, $units
            ));

            $activeControls = $sum('active_controls');
            $rated = $sum('rated_controls');
            $effective = $sum('effective_controls');

            return [
                'id' => $entity->id,
                'reference' => $entity->reference,
                'legal_name' => $entity->legal_name,
                'entity_type' => $entity->entity_type,
                'jurisdiction' => $entity->jurisdiction,
                'data_residency_country' => $entity->data_residency_country,
                'consolidation_method' => $entity->consolidation_method,
                'ownership_percentage' => (float) $entity->ownership_percentage,
                'weight' => $entity->consolidationWeight(),
                'kpis' => [
                    'active_controls' => $activeControls,
                    'effective_pct' => $rated > 0 ? round($effective / $rated * 100, 1) : null,
                    'open_exceptions' => $sum('open_exceptions'),
                    'overdue_obligations' => $sum('overdue_obligations'),
                    'open_incidents' => $sum('open_incidents'),
                    'active_risks' => $sum('active_risks'),
                ],
            ];
        })->all();
    }

    /**
     * Weighted group position over the visible entities. Counts scale by
     * the consolidation weight (full 100%, proportional/equity by
     * ownership, none excluded); the effectiveness figure is weighted by
     * each entity's rated-control base.
     *
     * @param  array<int, array<string, mixed>>  $entities
     */
    private function groupPosition(array $entities): array
    {
        $kpis = ['active_controls', 'open_exceptions', 'overdue_obligations', 'open_incidents', 'active_risks'];
        $group = array_fill_keys($kpis, 0.0);
        $effWeighted = 0.0;
        $effBase = 0.0;

        foreach ($entities as $row) {
            if ($row['weight'] <= 0) {
                continue;
            }

            foreach ($kpis as $kpi) {
                $group[$kpi] += $row['kpis'][$kpi] * $row['weight'];
            }

            if ($row['kpis']['effective_pct'] !== null) {
                $base = $row['kpis']['active_controls'] * $row['weight'];
                $effWeighted += $row['kpis']['effective_pct'] * $base;
                $effBase += $base;
            }
        }

        $included = count(array_filter($entities, fn ($row) => $row['weight'] > 0));

        return [
            'entity_count' => count($entities),
            'consolidated_count' => $included,
            'kpis' => [
                ...array_map(fn ($value) => round($value, 1), $group),
                'effective_pct' => $effBase > 0 ? round($effWeighted / $effBase, 1) : null,
            ],
            'averages' => $included > 0
                ? array_map(fn ($value) => round($value / $included, 1), $group)
                : array_fill_keys($kpis, null),
        ];
    }

    /**
     * Per-entity deltas against the group average (16.1 benchmarking).
     *
     * @param  array<int, array<string, mixed>>  $entities
     */
    private function withBenchmarks(array $entities, array $group): array
    {
        return array_map(function ($row) use ($group) {
            $row['benchmark'] = collect($group['averages'])
                ->map(fn ($avg, $kpi) => $avg === null ? null : round($row['kpis'][$kpi] - $avg, 1))
                ->all();

            return $row;
        }, $entities);
    }

    /**
     * Each entity's standalone organisation-unit set: its anchored subtree
     * minus any subtree anchored by another entity nested inside it.
     *
     * @param  Collection<int, Entity>  $entities
     * @return array<int, array<int, int>>
     */
    private function standaloneUnitSets($entities): array
    {
        $subtrees = $entities->mapWithKeys(
            fn (Entity $entity) => [$entity->id => $entity->unitSubtreeIds()]
        )->all();

        $anchors = $entities->pluck('organisation_unit_id', 'id')->all();

        $sets = [];

        foreach ($subtrees as $id => $units) {
            $claimed = [];

            foreach ($subtrees as $otherId => $otherUnits) {
                $anchor = $anchors[$otherId] ?? null;

                if ($otherId !== $id && $anchor && in_array($anchor, $units, true)) {
                    $claimed = array_merge($claimed, $otherUnits);
                }
            }

            $sets[$id] = array_values(array_diff($units, $claimed));
        }

        return $sets;
    }

    /**
     * One grouped query per domain area, keyed by organisation unit.
     *
     * @param  array<int, int>  $unitIds
     * @return array<int, array<string, int>>
     */
    private function unitStatistics(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        $stats = [];

        $merge = function ($rows, string $key) use (&$stats) {
            foreach ($rows as $unitId => $value) {
                $stats[$unitId][$key] = (int) $value;
            }
        };

        $merge(Control::query()
            ->whereIn('unit_id', $unitIds)
            ->where('is_template', false)
            ->where('status', 'Active')
            ->select('unit_id', DB::raw('count(*) as total'))
            ->groupBy('unit_id')->pluck('total', 'unit_id'), 'active_controls');

        // Latest published rating per control, bucketed effective/rated.
        $ratings = EffectivenessRating::query()
            ->where('effectiveness_ratings.status', 'Published')
            ->whereIn('effectiveness_ratings.id', function ($sub) {
                $sub->selectRaw('max(id)')
                    ->from('effectiveness_ratings')
                    ->whereNull('deleted_at')
                    ->groupBy('control_id');
            })
            ->join('controls', 'controls.id', '=', 'effectiveness_ratings.control_id')
            ->whereIn('controls.unit_id', $unitIds)
            ->selectRaw("
                controls.unit_id as unit_id,
                count(*) as rated,
                sum(case when effectiveness_ratings.operating_effectiveness = 'Effective' then 1 else 0 end) as effective
            ")
            ->groupBy('controls.unit_id')
            ->get();

        foreach ($ratings as $row) {
            $stats[$row->unit_id]['rated_controls'] = (int) $row->rated;
            $stats[$row->unit_id]['effective_controls'] = (int) $row->effective;
        }

        $merge(ControlException::query()
            ->whereIn('unit_id', $unitIds)
            ->whereIn('status', ControlException::OPEN_STATUSES)
            ->select('unit_id', DB::raw('count(*) as total'))
            ->groupBy('unit_id')->pluck('total', 'unit_id'), 'open_exceptions');

        $merge(ObligationInstance::query()
            ->where('obligation_instances.status', 'Overdue')
            ->join('obligation_assignments', 'obligation_assignments.id', '=', 'obligation_instances.assignment_id')
            ->whereIn('obligation_assignments.entity_id', $unitIds)
            ->select('obligation_assignments.entity_id', DB::raw('count(*) as total'))
            ->groupBy('obligation_assignments.entity_id')
            ->pluck('total', 'obligation_assignments.entity_id'), 'overdue_obligations');

        $merge(Incident::query()
            ->whereIn('entity_id', $unitIds)
            ->whereIn('status', Incident::OPEN_STATUSES)
            ->select('entity_id', DB::raw('count(*) as total'))
            ->groupBy('entity_id')->pluck('total', 'entity_id'), 'open_incidents');

        $merge(Risk::query()
            ->whereIn('entity_id', $unitIds)
            ->where('status', 'active')
            ->select('entity_id', DB::raw('count(*) as total'))
            ->groupBy('entity_id')->pluck('total', 'entity_id'), 'active_risks');

        return $stats;
    }
}
