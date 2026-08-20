<?php

namespace App\Dashboards\Sources;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\OrganisationUnit;
use App\Models\TestInstance;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The org-tree rollup (13.2): "by individual, team, department, business
 * unit, entire organisation". Counts are taken once per unit at the
 * database, then rolled UP the hierarchy in memory — a recursive query per
 * node would put the slowest page in the product on the deepest tree.
 */
class OrganisationSources extends SourceProvider
{
    /** The measures a rollup can carry. */
    private const MEASURES = [
        'controls' => 'Active controls',
        'open_exceptions' => 'Open exceptions',
        'overdue_exceptions' => 'Overdue exceptions',
        'open_tests' => 'Tests in flight',
    ];

    public function sources(): array
    {
        return [
            $this->make(
                'org_unit_rollup', 'Organisation rollup', 'Organisation', 'view dashboards',
                ['tree', 'table'],
                ['w' => 6, 'h' => 5],
                fn (User $user, array $config) => $this->rollup($config),
                ['measure', 'root_id'],
            ),
        ];
    }

    private function rollup(array $config): array
    {
        $measure = array_key_exists($config['measure'] ?? '', self::MEASURES)
            ? $config['measure']
            : 'open_exceptions';

        $units = OrganisationUnit::query()
            ->orderBy('name')
            ->get(['id', 'parent_id', 'code', 'name', 'type']);

        if ($units->isEmpty()) {
            return ['kind' => 'tree', 'tree' => [], 'empty' => true, 'total' => 0];
        }

        $own = $this->ownCounts($measure);
        $children = $units->whereNotNull('parent_id')->groupBy('parent_id');
        $rootId = $config['root_id'] ?? null;

        $roots = $rootId
            ? $units->where('id', (int) $rootId)
            : $units->whereNull('parent_id');

        $tree = $this->build($roots, $children, $own, $measure);
        $total = array_sum(array_column($tree, 'value'));

        return [
            'kind' => 'tree',
            'tree' => $this->withPercentages($tree, $total),
            'total' => $total,
            'unit' => 'count',
            'measure' => $measure,
            'measures' => self::MEASURES,
            'caption' => self::MEASURES[$measure].' rolled up the organisation hierarchy',
            'empty' => $total === 0,
        ];
    }

    /**
     * One grouped query per measure — never one per unit. Every query goes
     * through an Eloquent model so the tenant global scope applies; a raw
     * DB::table() here would quietly count another tenant's rows.
     */
    private function ownCounts(string $measure): Collection
    {
        return match ($measure) {
            'controls' => Control::query()
                ->where('is_template', false)
                ->where('status', 'Active')
                ->select('unit_id', DB::raw('count(*) as total'))
                ->groupBy('unit_id')
                ->pluck('total', 'unit_id'),

            'overdue_exceptions' => ControlException::query()
                ->open()
                ->where('is_overdue', true)
                ->select('unit_id', DB::raw('count(*) as total'))
                ->groupBy('unit_id')
                ->pluck('total', 'unit_id'),

            'open_tests' => TestInstance::query()
                ->join('controls', 'controls.id', '=', 'test_instances.control_id')
                ->whereNull('controls.deleted_at')
                ->whereNotIn('test_instances.status', ['Reviewed', 'Closed'])
                ->select('controls.unit_id', DB::raw('count(*) as total'))
                ->groupBy('controls.unit_id')
                ->pluck('total', 'unit_id'),

            default => ControlException::query()
                ->open()
                ->select('unit_id', DB::raw('count(*) as total'))
                ->groupBy('unit_id')
                ->pluck('total', 'unit_id'),
        };
    }

    /**
     * @param  Collection<int, OrganisationUnit>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function build(Collection $nodes, Collection $children, Collection $own, string $measure, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        return $nodes->map(function (OrganisationUnit $unit) use ($children, $own, $measure, $depth) {
            $childNodes = $this->build(
                $children->get($unit->id, collect()), $children, $own, $measure, $depth + 1,
            );

            $ownValue = (int) ($own[$unit->id] ?? 0);
            $rolled = $ownValue + array_sum(array_column($childNodes, 'value'));

            return [
                'id' => $unit->id,
                'label' => $unit->name,
                'code' => $unit->code,
                'own' => $ownValue,
                'value' => $rolled,
                'children' => $childNodes,
                'drill' => $this->drillFor($measure, $unit->id),
            ];
        })->values()->all();
    }

    private function drillFor(string $measure, int $unitId): array
    {
        return match ($measure) {
            'controls' => $this->drill('controls.index', ['unit_id' => $unitId]),
            'overdue_exceptions' => $this->drill('exceptions.index', ['status' => 'open', 'unit_id' => $unitId, 'overdue' => 1]),
            'open_tests' => $this->drill('test-instances.index'),
            default => $this->drill('exceptions.index', ['status' => 'open', 'unit_id' => $unitId]),
        };
    }

    private function withPercentages(array $nodes, int $total): array
    {
        return array_map(function (array $node) use ($total) {
            $node['pct'] = $this->percent($node['value'], $total);
            $node['children'] = $this->withPercentages($node['children'], $total);

            return $node;
        }, $nodes);
    }
}
