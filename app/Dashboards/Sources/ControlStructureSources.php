<?php

namespace App\Dashboards\Sources;

use App\Models\ControlEntity;
use App\Models\ControlException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CR2-A: the control-universe datasets — the structure the three
 * sub-units put on top of the flat control library. Every query runs
 * through an Eloquent model (or joins from one) so the tenant global
 * scope applies.
 */
class ControlStructureSources extends SourceProvider
{
    public function sources(): array
    {
        return [
            $this->make(
                'structure_entities_by_rating', 'Control entities by risk rating', 'Control Structure', 'view control-structure',
                ['donut', 'bar', 'table'],
                ['w' => 4, 'h' => 4],
                fn (User $user, array $config) => $this->entitiesByRating(),
            ),

            $this->make(
                'structure_coverage', 'Structure coverage', 'Control Structure', 'view control-structure',
                ['stat'],
                ['w' => 3, 'h' => 2],
                fn (User $user, array $config) => $this->coverage(),
            ),

            $this->make(
                'structure_branch_heat', 'Branches by open exceptions', 'Control Structure', 'view control-structure',
                ['horizontal_bar', 'table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->branchHeat((int) ($config['limit'] ?? 10)),
                ['limit'],
            ),

            $this->make(
                'structure_reviews_overdue', 'Entity reviews overdue', 'Control Structure', 'view control-structure',
                ['stat'],
                ['w' => 3, 'h' => 2],
                fn (User $user, array $config) => $this->reviewsOverdue(),
            ),
        ];
    }

    private function entitiesByRating(): array
    {
        $counts = ControlEntity::query()->active()
            ->whereNotNull('risk_rating')
            ->select('risk_rating', DB::raw('count(*) as total'))
            ->groupBy('risk_rating')
            ->pluck('total', 'risk_rating');

        $unrated = ControlEntity::query()->active()->whereNull('risk_rating')->count();

        $rows = [];

        foreach (ControlEntity::RISK_RATINGS as $rating) {
            $rows[] = [$rating, (int) ($counts[$rating] ?? 0)];
        }

        $rows[] = ['Unrated', $unrated];

        $total = array_sum(array_column($rows, 1));

        if ($total === 0) {
            return $this->emptyPayload();
        }

        return [
            'kind' => 'series',
            'categories' => $this->categoriesFrom($rows),
            'series' => [[
                'key' => 'entities',
                'label' => 'Entities',
                'values' => array_column($rows, 1),
                'tones' => array_map(
                    fn (array $row) => self::SEVERITY_TONE[$row[0]] ?? 'muted',
                    $rows,
                ),
            ]],
            'total' => $total,
            'drill' => $this->drill('control-structure.index'),
        ];
    }

    /** % of active entities with at least one KEY control attached. */
    private function coverage(): array
    {
        $active = ControlEntity::query()->active()->count();

        $covered = ControlEntity::query()->active()
            ->whereHas('controls', fn ($q) => $q->where('control_entity_control.is_key', true))
            ->count();

        return [
            'kind' => 'scalar',
            'value' => $this->percent($covered, $active),
            'unit' => 'percent',
            'caption' => "{$covered} of {$active} active entities have a key control attached",
            'drill' => $this->drill('control-structure.index'),
            'empty' => $active === 0,
        ];
    }

    /** The branch heat list: open exceptions through attached controls. */
    private function branchHeat(int $limit): array
    {
        $rows = ControlException::query()->open()
            ->join('control_entity_control', 'control_entity_control.control_id', '=', 'control_exceptions.control_id')
            ->join('control_entities as branch_activity', 'branch_activity.id', '=', 'control_entity_control.control_entity_id')
            ->join('control_entities as branch', 'branch.id', '=', 'branch_activity.parent_id')
            ->where('branch.entity_kind', 'branch')
            ->where('branch.is_active', true)
            ->select('branch.id', 'branch.name', DB::raw('count(distinct control_exceptions.id) as total'))
            ->groupBy('branch.id', 'branch.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return $this->emptyPayload();
        }

        $data = $rows->map(fn ($row) => [
            $row->name,
            (int) $row->total,
            $this->drill('control-structure.entity', ['controlEntity' => $row->id]),
        ])->all();

        return [
            'kind' => 'series',
            'categories' => $this->categoriesFrom($data),
            'series' => [['key' => 'open_exceptions', 'label' => 'Open exceptions', 'values' => array_column($data, 1)]],
            'total' => array_sum(array_column($data, 1)),
        ];
    }

    private function reviewsOverdue(): array
    {
        $overdue = ControlEntity::query()->active()
            ->whereNotNull('next_review_due_at')
            ->whereDate('next_review_due_at', '<', now())
            ->count();

        return [
            'kind' => 'scalar',
            'value' => $overdue,
            'unit' => 'count',
            'caption' => 'Control entities past their review date',
            'drill' => $this->drill('control-structure.index'),
            'empty' => $overdue === 0,
        ];
    }
}
