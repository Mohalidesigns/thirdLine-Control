<?php

namespace App\Services;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\EffectivenessRating;
use App\Models\Risk;
use App\Models\TestInstance;
use Illuminate\Support\Facades\DB;

/**
 * Executive dashboard aggregates (FR-10.1—10.5). All metrics aggregate at
 * the database — no PHP-side counting over hydrated collections.
 */
class DashboardService
{
    public function metrics(array $filters = []): array
    {
        $exceptions = ControlException::query()
            ->when($filters['unit_id'] ?? null, fn ($q, $v) => $q->where('unit_id', $v))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v));

        $openStatuses = ControlException::OPEN_STATUSES;

        $bySeverity = (clone $exceptions)
            ->whereIn('status', $openStatuses)
            ->select('severity', DB::raw('count(*) as total'))
            ->groupBy('severity')
            ->pluck('total', 'severity');

        $ageing = (clone $exceptions)
            ->whereIn('status', $openStatuses)
            ->selectRaw('
                sum(case when age_days between 0 and 30 then 1 else 0 end) as bucket_0_30,
                sum(case when age_days between 31 and 60 then 1 else 0 end) as bucket_31_60,
                sum(case when age_days between 61 and 90 then 1 else 0 end) as bucket_61_90,
                sum(case when age_days > 90 then 1 else 0 end) as bucket_90_plus
            ')
            ->first();

        $testTotals = TestInstance::query()
            ->selectRaw("
                count(*) as total,
                sum(case when status in ('Reviewed','Closed') then 1 else 0 end) as completed,
                sum(case when status in ('In Progress','Submitted','Reopened') then 1 else 0 end) as in_progress,
                sum(case when status not in ('Reviewed','Closed') and due_date < curdate() then 1 else 0 end) as overdue
            ")
            ->first();

        $effectiveness = EffectivenessRating::query()
            ->where('status', 'Published')
            ->whereIn('id', function ($sub) {
                $sub->selectRaw('max(id)')
                    ->from('effectiveness_ratings')
                    ->whereNull('deleted_at')
                    ->groupBy('control_id');
            })
            ->select('overall_rating', DB::raw('count(*) as total'))
            ->groupBy('overall_rating')
            ->pluck('total', 'overall_rating');

        $totalRisks = Risk::where('status', 'active')->count();
        $riskGaps = Risk::controlGaps()->count();

        return [
            'exceptions' => [
                'open' => (clone $exceptions)->whereIn('status', $openStatuses)->count(),
                'closed' => (clone $exceptions)->where('status', 'Verified-Closed')->count(),
                'overdue' => (clone $exceptions)->whereIn('status', $openStatuses)->where('is_overdue', true)->count(),
                'pendingVerification' => (clone $exceptions)->where('status', 'Remediated')->count(),
                'unresolvedCriticalHigh' => (clone $exceptions)->whereIn('status', $openStatuses)->whereIn('severity', ['Critical', 'High'])->count(),
                'bySeverity' => [
                    'Critical' => (int) ($bySeverity['Critical'] ?? 0),
                    'High' => (int) ($bySeverity['High'] ?? 0),
                    'Medium' => (int) ($bySeverity['Medium'] ?? 0),
                    'Low' => (int) ($bySeverity['Low'] ?? 0),
                ],
                'ageing' => [
                    '0-30' => (int) ($ageing->bucket_0_30 ?? 0),
                    '31-60' => (int) ($ageing->bucket_31_60 ?? 0),
                    '61-90' => (int) ($ageing->bucket_61_90 ?? 0),
                    '90+' => (int) ($ageing->bucket_90_plus ?? 0),
                ],
            ],
            'testing' => [
                'total' => (int) ($testTotals->total ?? 0),
                'completed' => (int) ($testTotals->completed ?? 0),
                'inProgress' => (int) ($testTotals->in_progress ?? 0),
                'overdue' => (int) ($testTotals->overdue ?? 0),
                'completionRate' => ($testTotals->total ?? 0) > 0
                    ? round(($testTotals->completed / $testTotals->total) * 100, 1)
                    : 0,
            ],
            'effectiveness' => [
                'Effective' => (int) ($effectiveness['Effective'] ?? 0),
                'Partially Effective' => (int) ($effectiveness['Partially Effective'] ?? 0),
                'Ineffective' => (int) ($effectiveness['Ineffective'] ?? 0),
                'Not Tested' => (int) ($effectiveness['Not Tested'] ?? 0),
            ],
            'controls' => [
                'active' => Control::active()->where('is_template', false)->count(),
                'gaps' => $riskGaps,
                'orphans' => Control::orphans()->where('status', '!=', 'Retired')->count(),
            ],
            'universe' => [
                'totalRisks' => $totalRisks,
                'highRisks' => Risk::where('status', 'active')->where('inherent_rating', '>=', 15)->count(),
                'coveragePct' => $totalRisks > 0
                    ? round((($totalRisks - $riskGaps) / $totalRisks) * 100)
                    : 0,
            ],
            'recentTests' => TestInstance::query()
                ->with(['control:id,title,control_ref', 'tester:id,name'])
                ->latest('updated_at')
                ->limit(5)
                ->get(['id', 'reference', 'control_id', 'assigned_tester_id', 'status', 'period_label']),
            'upcomingTests' => TestInstance::query()
                ->with(['control:id,title,control_ref', 'tester:id,name'])
                ->where('status', 'Scheduled')
                ->orderBy('due_date')
                ->limit(5)
                ->get(['id', 'reference', 'control_id', 'assigned_tester_id', 'status', 'period_label', 'due_date']),
        ];
    }
}
