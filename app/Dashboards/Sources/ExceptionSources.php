<?php

namespace App\Dashboards\Sources;

use App\Models\ControlException;
use App\Models\User;
use App\Support\SqlDialect;
use Illuminate\Support\Facades\DB;

/**
 * Exception datasets. Ageing is an ordered scale — the buckets have a
 * natural order — so it takes an ordinal ramp rather than eight identities.
 */
class ExceptionSources extends SourceProvider
{
    private const AGE_BUCKETS = ['0-30', '31-60', '61-90', '90+'];

    public function sources(): array
    {
        return [
            $this->make(
                'open_exceptions', 'Open exceptions', 'Exceptions', 'view exceptions',
                ['stat'],
                ['w' => 3, 'h' => 2],
                fn (User $user, array $config) => $this->openCount($config),
                ['unit_id'],
            ),

            $this->make(
                'exceptions_by_severity', 'Open exceptions by severity', 'Exceptions', 'view exceptions',
                ['donut', 'bar', 'table'],
                ['w' => 4, 'h' => 4],
                fn (User $user, array $config) => $this->bySeverity($config),
                ['unit_id'],
            ),

            $this->make(
                'exception_ageing', 'Exception ageing', 'Exceptions', 'view exceptions',
                ['bar', 'stacked_bar', 'table'],
                ['w' => 5, 'h' => 4],
                fn (User $user, array $config) => $this->ageing($config),
                ['unit_id'],
            ),

            $this->make(
                'exception_trend', 'Raised against closed', 'Exceptions', 'view exceptions',
                ['line', 'bar', 'table'],
                ['w' => 8, 'h' => 4],
                fn (User $user, array $config) => $this->trend((int) ($config['months'] ?? 12)),
                ['months'],
            ),

            $this->make(
                'exceptions_by_unit', 'Open exceptions by unit', 'Exceptions', 'view exceptions',
                ['horizontal_bar', 'table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->byUnit((int) ($config['limit'] ?? 8)),
                ['limit'],
            ),

            $this->make(
                'my_exceptions', 'My exceptions', 'Exceptions', 'view exceptions',
                ['table', 'stat'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->mine($user),
                selfScoped: true,
            ),
        ];
    }

    private function openCount(array $config): array
    {
        $unitId = $config['unit_id'] ?? null;

        $open = $this->openQuery($config)->count();
        $overdue = $this->openQuery($config)->where('is_overdue', true)->count();

        return [
            'kind' => 'scalar',
            'value' => $open,
            'unit' => 'count',
            'tone' => $overdue > 0 ? 'serious' : null,
            'caption' => "{$overdue} past their target closure date",
            'drill' => $this->drill('exceptions.index', array_filter(['status' => 'open', 'unit_id' => $unitId])),
        ];
    }

    private function bySeverity(array $config): array
    {
        $unitId = $config['unit_id'] ?? null;

        $counts = $this->openQuery($config)
            ->select('severity', DB::raw('count(*) as total'))
            ->groupBy('severity')
            ->pluck('total', 'severity');

        $categories = [];
        $values = [];
        $tones = [];

        foreach (ControlException::SEVERITIES as $severity) {
            $categories[] = [
                'key' => $severity,
                'label' => $severity,
                'drill' => $this->drill('exceptions.index', array_filter([
                    'status' => 'open',
                    'severity' => $severity,
                    'unit_id' => $unitId,
                ])),
            ];
            $values[] = (int) ($counts[$severity] ?? 0);
            $tones[] = self::SEVERITY_TONE[$severity];
        }

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => 'severity', 'label' => 'Open exceptions', 'tones' => $tones, 'values' => $values]],
            'total' => array_sum($values),
            'unit' => 'count',
            'drill' => $this->drill('exceptions.index', array_filter(['status' => 'open', 'unit_id' => $unitId])),
            'empty' => array_sum($values) === 0,
        ];
    }

    private function ageing(array $config): array
    {
        $row = $this->openQuery($config)
            ->selectRaw('
                sum(case when age_days between 0 and 30 then 1 else 0 end) as b1,
                sum(case when age_days between 31 and 60 then 1 else 0 end) as b2,
                sum(case when age_days between 61 and 90 then 1 else 0 end) as b3,
                sum(case when age_days > 90 then 1 else 0 end) as b4
            ')
            ->first();

        $values = [
            (int) ($row->b1 ?? 0), (int) ($row->b2 ?? 0),
            (int) ($row->b3 ?? 0), (int) ($row->b4 ?? 0),
        ];

        return [
            'kind' => 'series',
            'categories' => array_map(fn (string $bucket) => [
                'key' => $bucket,
                'label' => $bucket.' days',
                'drill' => $this->drill('exceptions.index', array_filter([
                    'status' => 'open',
                    'unit_id' => $config['unit_id'] ?? null,
                ])),
            ], self::AGE_BUCKETS),
            'series' => [[
                'key' => 'ageing',
                'label' => 'Open exceptions',
                'ordinal' => true,
                'values' => $values,
            ]],
            'total' => array_sum($values),
            'unit' => 'count',
            'empty' => array_sum($values) === 0,
        ];
    }

    private function trend(int $months): array
    {
        $months = min(24, max(3, $months));
        $buckets = $this->monthBuckets($months);
        $from = now()->startOfMonth()->subMonths($months - 1)->toDateString();

        $raised = ControlException::query()
            ->selectRaw(SqlDialect::month('date_raised').' as bucket, count(*) as total')
            ->where('date_raised', '>=', $from)
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $closed = ControlException::query()
            ->selectRaw(SqlDialect::month('verified_at').' as bucket, count(*) as total')
            ->whereNotNull('verified_at')
            ->where('verified_at', '>=', $from)
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return [
            'kind' => 'series',
            'categories' => array_map(fn (string $label, string $key) => [
                'key' => $key, 'label' => $label, 'drill' => null,
            ], array_values($buckets), array_keys($buckets)),
            'series' => [
                ['key' => 'raised', 'label' => 'Raised', 'slot' => 2,
                    'values' => array_map(fn ($k) => (int) ($raised[$k] ?? 0), array_keys($buckets))],
                ['key' => 'closed', 'label' => 'Closed', 'slot' => 3,
                    'values' => array_map(fn ($k) => (int) ($closed[$k] ?? 0), array_keys($buckets))],
            ],
            'unit' => 'count',
        ];
    }

    private function byUnit(int $limit): array
    {
        $limit = min(20, max(3, $limit));

        $rows = ControlException::query()
            ->open()
            ->leftJoin('organisation_units', 'organisation_units.id', '=', 'control_exceptions.unit_id')
            ->select([
                'control_exceptions.unit_id',
                DB::raw("coalesce(organisation_units.name, 'Unassigned') as unit_name"),
                DB::raw('count(*) as total'),
            ])
            ->groupBy('control_exceptions.unit_id', 'organisation_units.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        return [
            'kind' => 'series',
            'categories' => $rows->map(fn ($row) => [
                'key' => $row->unit_name,
                'label' => $row->unit_name,
                'drill' => $this->drill('exceptions.index', array_filter([
                    'status' => 'open',
                    'unit_id' => $row->unit_id,
                ])),
            ])->all(),
            'series' => [[
                'key' => 'open',
                'label' => 'Open exceptions',
                'slot' => 1,
                'values' => $rows->map(fn ($row) => (int) $row->total)->all(),
            ]],
            'total' => (int) $rows->sum('total'),
            'unit' => 'count',
            'empty' => $rows->isEmpty(),
        ];
    }

    private function mine(User $user): array
    {
        $exceptions = ControlException::query()
            ->open()
            ->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('responsible_party_id', $user->id))
            ->orderByRaw("case severity when 'Critical' then 1 when 'High' then 2 when 'Medium' then 3 else 4 end")
            ->orderBy('target_closure_date')
            ->limit(10)
            ->get(['id', 'reference', 'title', 'severity', 'status', 'target_closure_date', 'is_overdue']);

        return [
            'kind' => 'rows',
            'columns' => [
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'title', 'label' => 'Exception'],
                ['key' => 'severity', 'label' => 'Severity'],
                ['key' => 'due', 'label' => 'Target closure'],
            ],
            'rows' => $exceptions->map(fn (ControlException $exception) => [
                'cells' => [
                    $exception->reference,
                    $exception->title,
                    $exception->severity,
                    $exception->target_closure_date?->toDateString(),
                ],
                'tone' => $exception->is_overdue ? 'critical' : self::SEVERITY_TONE[$exception->severity] ?? null,
                'drill' => $this->drill('exceptions.show', ['exception' => $exception->id]),
            ])->all(),
            'total' => $exceptions->count(),
            'drill' => $this->drill('exceptions.index', ['status' => 'open', 'view' => 'mine']),
            'empty' => $exceptions->isEmpty(),
        ];
    }

    private function openQuery(array $config)
    {
        return ControlException::query()
            ->open()
            ->when($config['unit_id'] ?? null, fn ($q, $u) => $q->where('unit_id', $u));
    }
}
