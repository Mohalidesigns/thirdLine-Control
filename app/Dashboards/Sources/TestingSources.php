<?php

namespace App\Dashboards\Sources;

use App\Models\TestInstance;
use App\Models\User;
use App\Support\SqlDialect;
use Illuminate\Support\Facades\DB;

/**
 * Control testing datasets — the pipeline the second line lives in.
 */
class TestingSources extends SourceProvider
{
    public function sources(): array
    {
        return [
            $this->make(
                'testing_pipeline', 'Testing pipeline', 'Testing', 'view tests',
                ['stacked_bar', 'bar', 'donut', 'table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->pipeline(),
            ),

            $this->make(
                'overdue_tests', 'Overdue tests', 'Testing', 'view tests',
                ['stat'],
                ['w' => 3, 'h' => 2],
                fn (User $user, array $config) => $this->overdue(),
            ),

            $this->make(
                'testing_completion_rate', 'Testing completion', 'Testing', 'view tests',
                ['gauge', 'progress', 'stat'],
                ['w' => 3, 'h' => 3],
                fn (User $user, array $config) => $this->completionRate(),
            ),

            $this->make(
                'test_completion_trend', 'Testing trend', 'Testing', 'view tests',
                ['line', 'bar', 'table'],
                ['w' => 8, 'h' => 4],
                fn (User $user, array $config) => $this->trend((int) ($config['months'] ?? 12)),
                ['months'],
            ),

            $this->make(
                'my_tests', 'My tests due', 'Testing', 'view tests',
                ['table', 'stat'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->myTests($user),
                selfScoped: true,
            ),
        ];
    }

    private function pipeline(): array
    {
        $counts = TestInstance::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $categories = [];
        $values = [];

        foreach (TestInstance::STATUSES as $status) {
            $categories[] = [
                'key' => $status,
                'label' => $status,
                'drill' => $this->drill('test-instances.index', ['status' => $status]),
            ];
            $values[] = (int) ($counts[$status] ?? 0);
        }

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => 'pipeline', 'label' => 'Test instances', 'slot' => 1, 'values' => $values]],
            'total' => array_sum($values),
            'unit' => 'count',
            'drill' => $this->drill('test-instances.index'),
            'empty' => array_sum($values) === 0,
        ];
    }

    private function overdue(): array
    {
        $today = SqlDialect::today();

        $overdue = TestInstance::query()
            ->whereNotIn('status', ['Reviewed', 'Closed'])
            ->whereRaw("due_date < {$today}")
            ->count();

        $dueThisWeek = TestInstance::query()
            ->whereNotIn('status', ['Reviewed', 'Closed'])
            ->whereBetween('due_date', [now()->toDateString(), now()->addWeek()->toDateString()])
            ->count();

        return [
            'kind' => 'scalar',
            'value' => $overdue,
            'unit' => 'count',
            'tone' => $overdue > 0 ? 'critical' : 'good',
            'caption' => "{$dueThisWeek} more fall due within seven days",
            'drill' => $this->drill('test-instances.index', ['status' => 'Scheduled']),
        ];
    }

    private function completionRate(): array
    {
        $totals = TestInstance::query()
            ->selectRaw("
                count(*) as total,
                sum(case when status in ('Reviewed','Closed') then 1 else 0 end) as completed
            ")
            ->first();

        $total = (int) ($totals->total ?? 0);
        $completed = (int) ($totals->completed ?? 0);

        return [
            'kind' => 'scalar',
            'value' => $this->percent($completed, $total),
            'unit' => 'percent',
            'caption' => "{$completed} of {$total} test instances complete",
            'drill' => $this->drill('test-instances.index'),
            'empty' => $total === 0,
        ];
    }

    /** Instances due per month against instances actually completed. */
    private function trend(int $months): array
    {
        $months = min(24, max(3, $months));
        $buckets = $this->monthBuckets($months);
        $from = now()->startOfMonth()->subMonths($months - 1)->toDateString();

        $due = TestInstance::query()
            ->selectRaw(SqlDialect::month('due_date').' as bucket, count(*) as total')
            ->whereNotNull('due_date')
            ->where('due_date', '>=', $from)
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $completed = TestInstance::query()
            ->selectRaw(SqlDialect::month('reviewed_at').' as bucket, count(*) as total')
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '>=', $from)
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return [
            'kind' => 'series',
            'categories' => array_map(fn (string $label, string $key) => [
                'key' => $key, 'label' => $label, 'drill' => null,
            ], array_values($buckets), array_keys($buckets)),
            'series' => [
                ['key' => 'due', 'label' => 'Due', 'slot' => 1,
                    'values' => array_map(fn ($k) => (int) ($due[$k] ?? 0), array_keys($buckets))],
                ['key' => 'completed', 'label' => 'Completed', 'slot' => 3,
                    'values' => array_map(fn ($k) => (int) ($completed[$k] ?? 0), array_keys($buckets))],
            ],
            'unit' => 'count',
        ];
    }

    private function myTests(User $user): array
    {
        $instances = TestInstance::query()
            ->with('control:id,control_ref,title')
            ->where('assigned_tester_id', $user->id)
            ->whereNotIn('status', ['Reviewed', 'Closed'])
            ->orderBy('due_date')
            ->limit(10)
            ->get(['id', 'reference', 'control_id', 'status', 'due_date', 'period_label']);

        return [
            'kind' => 'rows',
            'columns' => [
                ['key' => 'reference', 'label' => 'Test'],
                ['key' => 'control', 'label' => 'Control'],
                ['key' => 'due', 'label' => 'Due'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $instances->map(fn (TestInstance $instance) => [
                'cells' => [
                    $instance->reference,
                    $instance->control?->control_ref.' — '.$instance->control?->title,
                    $instance->due_date?->toDateString(),
                    $instance->status,
                ],
                'tone' => $instance->due_date?->isPast() ? 'critical' : null,
                'drill' => $this->drill('test-instances.show', ['test_instance' => $instance->id]),
            ])->all(),
            'total' => $instances->count(),
            'drill' => $this->drill('test-instances.index', ['view' => 'mine']),
            'empty' => $instances->isEmpty(),
        ];
    }
}
