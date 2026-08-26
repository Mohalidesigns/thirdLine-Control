<?php

namespace App\Dashboards\Sources;

use App\Models\Control;
use App\Models\TestInstance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CR-03 §E.2/§E.4: the datasets behind the control-task tiles and the
 * five shipped reports. Every query runs through an Eloquent model, or
 * joins from one, so the tenant global scope applies.
 *
 * All of them filter on controls.is_control_function — an ordinary
 * library control's test is not a departmental checklist task, and
 * mixing the two would make the completion rate meaningless.
 */
class ControlFunctionSources extends SourceProvider
{
    private const PERMISSION = 'view control-functions';

    public function sources(): array
    {
        return [
            $this->make(
                'control_tasks_due_today', 'Control tasks due today', 'Control Functions', self::PERMISSION,
                ['stat'],
                ['w' => 3, 'h' => 2],
                fn (User $user, array $config) => $this->dueToday(),
            ),

            $this->make(
                'control_tasks_overdue', 'Control tasks overdue', 'Control Functions', self::PERMISSION,
                ['stat'],
                ['w' => 3, 'h' => 2],
                fn (User $user, array $config) => $this->overdue(),
            ),

            $this->make(
                'control_task_completion_by_unit', 'Control task completion by sub-unit', 'Control Functions', self::PERMISSION,
                ['bar', 'horizontal_bar', 'table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->completionByUnit((int) ($config['days'] ?? 30)),
                ['days'],
            ),

            $this->make(
                'control_tasks_overdue_by_unit', 'Overdue control tasks by sub-unit', 'Control Functions', self::PERMISSION,
                ['horizontal_bar', 'bar', 'table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->overdueByUnit(),
            ),

            $this->make(
                'control_functions_by_frequency', 'Control functions by frequency', 'Control Functions', self::PERMISSION,
                ['donut', 'bar', 'table'],
                ['w' => 4, 'h' => 4],
                fn (User $user, array $config) => $this->byFrequency(),
            ),

            $this->make(
                'branch_control_scorecard', 'Branch control scorecard', 'Control Functions', self::PERMISSION,
                ['horizontal_bar', 'table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->branchScorecard((int) ($config['limit'] ?? 15)),
                ['limit'],
            ),
        ];
    }

    private function taskQuery()
    {
        return TestInstance::query()
            ->whereHas('control', fn ($q) => $q->where('is_control_function', true));
    }

    private function dueToday(): array
    {
        $due = $this->taskQuery()
            ->whereNotIn('status', ['Reviewed', 'Closed'])
            ->whereDate('due_date', now()->toDateString())
            ->count();

        return [
            'kind' => 'scalar',
            'value' => $due,
            'unit' => 'count',
            'caption' => 'Checklist tasks the control function owes today',
            'drill' => $this->drill('control-functions.index'),
            'empty' => $due === 0,
        ];
    }

    private function overdue(): array
    {
        $overdue = $this->taskQuery()->overdue()->count();

        return [
            'kind' => 'scalar',
            'value' => $overdue,
            'unit' => 'count',
            // The whole point of the change request in one caption: a
            // Daily function not performed yesterday is visibly late now.
            'caption' => 'Checklist tasks past their due date',
            'drill' => $this->drill('control-functions.index'),
            'empty' => $overdue === 0,
        ];
    }

    private function completionByUnit(int $days): array
    {
        $rows = $this->unitAggregate(now()->subDays($days)->toDateString());

        if ($rows->isEmpty()) {
            return $this->emptyPayload();
        }

        $data = $rows->map(fn ($row) => [
            $row->unit_name ?? 'Unassigned',
            $this->percent((int) $row->completed, (int) $row->total),
        ])->all();

        return [
            'kind' => 'series',
            'categories' => $this->categoriesFrom($data),
            'series' => [[
                'key' => 'completion',
                'label' => 'Completion %',
                'values' => array_column($data, 1),
                // Below 80% is a control the bank cannot evidence.
                'tones' => array_map(fn ($row) => $row[1] >= 95 ? 'good' : ($row[1] >= 80 ? 'warning' : 'critical'), $data),
            ]],
            'total' => count($data),
            'unit' => 'percent',
            'drill' => $this->drill('control-functions.compliance'),
        ];
    }

    private function overdueByUnit(): array
    {
        $rows = DB::table('test_instances')
            ->join('controls', 'controls.id', '=', 'test_instances.control_id')
            ->leftJoin('control_units', 'control_units.id', '=', 'controls.control_unit_id')
            ->where('test_instances.tenant_id', auth()->user()?->tenant_id)
            ->where('controls.is_control_function', true)
            ->whereNull('test_instances.deleted_at')
            ->whereNotIn('test_instances.status', ['Reviewed', 'Closed'])
            ->whereNotNull('test_instances.due_date')
            ->whereDate('test_instances.due_date', '<', now()->toDateString())
            ->groupBy('control_units.name')
            ->select('control_units.name as unit_name', DB::raw('count(*) as total'))
            ->orderByDesc('total')
            ->get();

        if ($rows->isEmpty()) {
            return $this->emptyPayload();
        }

        $data = $rows->map(fn ($row) => [$row->unit_name ?? 'Unassigned', (int) $row->total])->all();

        return [
            'kind' => 'series',
            'categories' => $this->categoriesFrom($data),
            'series' => [['key' => 'overdue', 'label' => 'Overdue tasks', 'values' => array_column($data, 1)]],
            'total' => array_sum(array_column($data, 1)),
            'drill' => $this->drill('control-functions.index'),
        ];
    }

    private function byFrequency(): array
    {
        $rows = Control::query()
            ->controlFunctions()
            ->where('controls.status', 'Active')
            ->leftJoin('control_frequencies', 'control_frequencies.id', '=', 'controls.frequency_id')
            ->groupBy('control_frequencies.label', 'control_frequencies.sequence')
            ->orderBy('control_frequencies.sequence')
            ->select('control_frequencies.label', DB::raw('count(*) as total'))
            ->get();

        if ($rows->isEmpty()) {
            return $this->emptyPayload();
        }

        $data = $rows->map(fn ($row) => [$row->label ?? 'Unspecified', (int) $row->total])->all();

        return [
            'kind' => 'series',
            'categories' => $this->categoriesFrom($data),
            'series' => [['key' => 'functions', 'label' => 'Functions', 'values' => array_column($data, 1)]],
            'total' => array_sum(array_column($data, 1)),
            'drill' => $this->drill('control-functions.index'),
        ];
    }

    /** §E.4 report 4: completion rate per branch, ranked worst first. */
    private function branchScorecard(int $limit): array
    {
        $rows = DB::table('test_instances')
            ->join('controls', 'controls.id', '=', 'test_instances.control_id')
            ->join('control_entities', 'control_entities.id', '=', 'test_instances.control_entity_id')
            ->where('test_instances.tenant_id', auth()->user()?->tenant_id)
            ->where('controls.is_control_function', true)
            ->where('control_entities.entity_kind', 'branch')
            ->whereNull('test_instances.deleted_at')
            // Overlap rather than containment: a monthly task whose period
            // opened before the window is still part of it.
            ->whereDate('test_instances.period_end', '>=', now()->subDays(30)->toDateString())
            ->whereDate('test_instances.period_start', '<=', now()->toDateString())
            ->groupBy('control_entities.id', 'control_entities.name')
            ->select(
                'control_entities.id',
                'control_entities.name',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when test_instances.status in ('Reviewed', 'Closed') then 1 else 0 end) as completed"),
            )
            ->get();

        if ($rows->isEmpty()) {
            return $this->emptyPayload();
        }

        $data = $rows
            ->map(fn ($row) => [
                $row->name,
                $this->percent((int) $row->completed, (int) $row->total),
                $this->drill('control-structure.entity', ['controlEntity' => $row->id]),
            ])
            // Worst first: a scorecard nobody reads to the bottom must put
            // the branch in trouble at the top.
            ->sortBy(1)
            ->take($limit)
            ->values()
            ->all();

        return [
            'kind' => 'series',
            'categories' => $this->categoriesFrom($data),
            'series' => [[
                'key' => 'completion',
                'label' => 'Completion %',
                'values' => array_column($data, 1),
                'tones' => array_map(fn ($row) => $row[1] >= 95 ? 'good' : ($row[1] >= 80 ? 'warning' : 'critical'), $data),
            ]],
            'total' => count($data),
            'unit' => 'percent',
        ];
    }

    private function unitAggregate(string $since)
    {
        return DB::table('test_instances')
            ->join('controls', 'controls.id', '=', 'test_instances.control_id')
            ->leftJoin('control_units', 'control_units.id', '=', 'controls.control_unit_id')
            ->where('test_instances.tenant_id', auth()->user()?->tenant_id)
            ->where('controls.is_control_function', true)
            ->whereNull('test_instances.deleted_at')
            ->whereDate('test_instances.period_end', '>=', $since)
            ->whereDate('test_instances.period_start', '<=', now()->toDateString())
            ->groupBy('control_units.name')
            ->select(
                'control_units.name as unit_name',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when test_instances.status in ('Reviewed', 'Closed') then 1 else 0 end) as completed"),
            )
            ->get();
    }
}
