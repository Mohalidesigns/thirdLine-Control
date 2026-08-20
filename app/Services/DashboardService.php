<?php

namespace App\Services;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\EffectivenessRating;
use App\Models\ExceptionEscalation;
use App\Models\Risk;
use App\Models\TestInstance;
use App\Support\SqlDialect;
use Illuminate\Support\Facades\DB;

/**
 * Executive dashboard aggregates (FR-10.1—10.5). All metrics aggregate at
 * the database — no PHP-side counting over hydrated collections.
 */
class DashboardService
{
    public function metrics(array $filters = []): array
    {
        // FR-10.6 requires seven filters: period, unit, process, control
        // owner, severity, status and risk (DEF-015).
        $exceptions = ControlException::query()
            ->when($filters['unit_id'] ?? null, fn ($q, $v) => $q->where('unit_id', $v))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['owner_id'] ?? null, fn ($q, $v) => $q->where('owner_id', $v))
            ->when($filters['process_id'] ?? null, fn ($q, $v) => $q
                ->whereHas('control', fn ($c) => $c->where('process_id', $v)))
            ->when($filters['risk_id'] ?? null, fn ($q, $v) => $q
                ->whereHas('control.risks', fn ($r) => $r->where('risks.id', $v)))
            ->when($filters['period'] ?? null, function ($q, $v) {
                // 'YYYY' scopes a year; 'YYYY-MM' scopes a month — the
                // quarter a board pack covers is three month filters or a
                // year export sliced downstream.
                [$year, $month] = array_pad(explode('-', (string) $v), 2, null);

                return $q->whereYear('date_raised', (int) $year)
                    ->when($month, fn ($qq) => $qq->whereMonth('date_raised', (int) $month));
            });

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

        // The overdue comparison is spelled per driver: this aggregate runs on
        // MySQL in production and SQLite under test, and curdate() exists in
        // only one of them.
        $today = SqlDialect::today();

        $testTotals = TestInstance::query()
            ->selectRaw("
                count(*) as total,
                sum(case when status in ('Reviewed','Closed') then 1 else 0 end) as completed,
                sum(case when status in ('In Progress','Submitted','Reopened') then 1 else 0 end) as in_progress,
                sum(case when status not in ('Reviewed','Closed') and due_date < {$today} then 1 else 0 end) as overdue
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
            // CR-01: the departmental escalation loop, aggregated at the
            // database like everything else here.
            'exceptionManager' => [
                'issued' => ExceptionEscalation::count(),
                'awaitingResponse' => ExceptionEscalation::awaitingResponse()->count(),
                'overdue' => ExceptionEscalation::responseOverdue()->count(),
                'awaitingReview' => ExceptionEscalation::where('status', 'Responded')->count(),
                'closedThisPeriod' => ExceptionEscalation::where('status', 'Closed')
                    ->where('closed_at', '>=', now()->startOfMonth())->count(),
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
