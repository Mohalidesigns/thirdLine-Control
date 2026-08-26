<?php

namespace App\Services;

use App\Models\ConsequenceAction;
use App\Models\Investigation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The investigation dashboard (CR-04 §E.4).
 *
 * Three things are true of every widget here and none of them is optional:
 *
 *   1. Aggregation happens in SQL. The register is never pulled into
 *      memory to be filtered (R6).
 *   2. Visibility is applied BEFORE aggregation, by the model's
 *      `visibility` global scope, so a confidential investigation never
 *      contributes to a count a user should not see. A number that leaks
 *      the existence of a case is a leak.
 *   3. No widget returns a subject name, staff ID or account number.
 *      "Top cases by loss" returns reference and title, and only for
 *      investigations the viewer could already open.
 *
 * Every figure is period-scoped with a previous-period comparison, because
 * "14 fraud cases" means nothing without "and 9 last quarter".
 */
class InvestigationDashboardService
{
    /** Ageing buckets, in days since the case was reported. */
    public const AGEING_BUCKETS = [
        '0-30' => [0, 30],
        '31-60' => [31, 60],
        '61-90' => [61, 90],
        '90+' => [91, null],
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, array $filters = []): array
    {
        [$from, $to, $label] = $this->period($filters);
        [$previousFrom, $previousTo] = $this->previousPeriod($from, $to);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $label],
            'kpis' => $this->kpis($user, $from, $to, $previousFrom, $previousTo),
            'trend' => $this->trend($user, $to),
            'risk_distribution' => $this->riskDistribution($user, $from, $to),
            'financials' => $this->financials($user, $from, $to, $previousFrom, $previousTo),
            'consequences' => $this->consequences($user, $from, $to),
            'by_category' => $this->byCategory($user, $from, $to),
            'by_control_entity' => $this->byControlEntity($user, $from, $to),
            'ageing' => $this->ageing($user),
            'activity' => $this->activityFeed($user),
        ];
    }

    /**
     * The base every widget starts from. Visibility and tenancy are both
     * GLOBAL scopes on the model, so they are already in the query before
     * a single aggregate runs — which is the point: a count that sees more
     * than its reader may see is a leak, and relying on each widget to
     * remember a filter is how one of nine forgets.
     *
     * $user is still taken as a parameter rather than read from auth(),
     * so the dependency is visible at every call site rather than hidden
     * in a helper.
     */
    private function base(User $user): Builder
    {
        return Investigation::query()->active();
    }

    // ── 1. KPI tiles ─────────────────────────────────────────────────────

    private function kpis(User $user, Carbon $from, Carbon $to, Carbon $previousFrom, Carbon $previousTo): array
    {
        $opened = $this->base($user)->whereBetween('reported_date', [$from->toDateString(), $to->toDateString()])->count();
        $openedPrevious = $this->base($user)->whereBetween('reported_date', [$previousFrom->toDateString(), $previousTo->toDateString()])->count();

        $completed = $this->base($user)->whereBetween('completed_date', [$from->toDateString(), $to->toDateString()])->count();
        $completedPrevious = $this->base($user)->whereBetween('completed_date', [$previousFrom->toDateString(), $previousTo->toDateString()])->count();

        return [
            'opened' => $this->withComparison($opened, $openedPrevious),
            'completed' => $this->withComparison($completed, $completedPrevious),
            'open_now' => ['value' => $this->base($user)->open()->count(), 'previous' => null, 'change' => null],
            'suspended' => ['value' => $this->base($user)->where('status', 'suspended')->count(), 'previous' => null, 'change' => null],
            'overdue' => [
                'value' => $this->base($user)->open()
                    ->whereNotNull('target_completion_date')
                    ->whereDate('target_completion_date', '<', now()->toDateString())
                    ->count(),
                'previous' => null,
                'change' => null,
            ],
            'average_days_to_close' => $this->withComparison(
                $this->averageDaysToClose($user, $from, $to),
                $this->averageDaysToClose($user, $previousFrom, $previousTo),
            ),
        ];
    }

    /**
     * Suspended cases are excluded, not merely bucketed apart: a case
     * suspended for six months pending a police report would otherwise
     * distort this number into meaninglessness (§H.5-6).
     */
    private function averageDaysToClose(User $user, Carbon $from, Carbon $to): ?int
    {
        $rows = $this->base($user)
            ->whereNotNull('completed_date')
            ->where('status', '!=', 'suspended')
            ->whereBetween('completed_date', [$from->toDateString(), $to->toDateString()])
            ->get(['reported_date', 'completed_date']);

        if ($rows->isEmpty()) {
            return null;
        }

        return (int) round($rows->avg(fn ($row) => $row->reported_date->diffInDays($row->completed_date)));
    }

    // ── 2. Twelve-month trend ────────────────────────────────────────────

    private function trend(User $user, Carbon $to): array
    {
        $start = $to->copy()->subMonthsNoOverflow(11)->startOfMonth();

        $opened = $this->monthlyCounts($this->base($user), 'reported_date', $start, $to);
        $completed = $this->monthlyCounts($this->base($user), 'completed_date', $start, $to);

        $months = [];

        for ($cursor = $start->copy(); $cursor->lte($to); $cursor->addMonthNoOverflow()) {
            $key = $cursor->format('Y-m');
            $months[] = [
                'month' => $key,
                'label' => $cursor->format('M y'),
                'opened' => (int) ($opened[$key] ?? 0),
                'completed' => (int) ($completed[$key] ?? 0),
            ];
        }

        return $months;
    }

    /** @return array<string, int> */
    private function monthlyCounts(Builder $query, string $column, Carbon $from, Carbon $to): array
    {
        $expression = $this->monthExpression('investigations.'.$column);

        return $query
            ->whereNotNull($column)
            ->whereBetween($column, [$from->toDateString(), $to->toDateString()])
            ->selectRaw("{$expression} as period_month, count(*) as total")
            ->groupBy('period_month')
            ->pluck('total', 'period_month')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    // ── 3. Risk distribution ─────────────────────────────────────────────

    private function riskDistribution(User $user, Carbon $from, Carbon $to): array
    {
        $counts = $this->base($user)
            ->whereNotNull('risk_rating')
            ->whereBetween('reported_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('risk_rating, count(*) as total')
            ->groupBy('risk_rating')
            ->pluck('total', 'risk_rating');

        return collect(Investigation::RISK_RATINGS)
            ->map(fn (string $rating) => ['rating' => $rating, 'total' => (int) ($counts[$rating] ?? 0)])
            ->all();
    }

    // ── 4. Financials ────────────────────────────────────────────────────

    private function financials(User $user, Carbon $from, Carbon $to, Carbon $previousFrom, Carbon $previousTo): array
    {
        $window = fn (Carbon $start, Carbon $end) => $this->base($user)
            ->whereBetween('reported_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('coalesce(sum(confirmed_financial_loss), 0) as loss, coalesce(sum(amount_recovered), 0) as recovered')
            ->first();

        $current = $window($from, $to);
        $previous = $window($previousFrom, $previousTo);

        $loss = (float) ($current->loss ?? 0);
        $recovered = (float) ($current->recovered ?? 0);

        return [
            'confirmed_loss' => $this->withComparison($loss, (float) ($previous->loss ?? 0)),
            'recovered' => $this->withComparison($recovered, (float) ($previous->recovered ?? 0)),
            'net_loss' => ['value' => round($loss - $recovered, 2), 'previous' => null, 'change' => null],
            'recovery_rate' => ['value' => $loss > 0 ? (int) round($recovered / $loss * 100) : null, 'previous' => null, 'change' => null],
            'by_category' => $this->base($user)
                ->whereBetween('reported_date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('category, coalesce(sum(confirmed_financial_loss), 0) as loss, coalesce(sum(amount_recovered), 0) as recovered, count(*) as total')
                ->groupBy('category')
                ->orderByDesc('loss')
                ->get()
                ->map(fn ($row) => [
                    'category' => $row->category,
                    'loss' => (float) $row->loss,
                    'recovered' => (float) $row->recovered,
                    'total' => (int) $row->total,
                ])
                ->all(),
            // Reference and title only — never a subject, never an account.
            'top_cases' => $this->base($user)
                ->whereNotNull('confirmed_financial_loss')
                ->whereBetween('reported_date', [$from->toDateString(), $to->toDateString()])
                ->orderByDesc('confirmed_financial_loss')
                ->limit(10)
                ->get(['id', 'reference', 'title', 'category', 'confirmed_financial_loss', 'amount_recovered', 'currency'])
                ->map(fn (Investigation $investigation) => [
                    'id' => $investigation->id,
                    'reference' => $investigation->reference,
                    'title' => $investigation->title,
                    'category' => $investigation->category,
                    'loss' => (float) $investigation->confirmed_financial_loss,
                    'recovered' => (float) $investigation->amount_recovered,
                    'currency' => $investigation->currency,
                ])
                ->all(),
        ];
    }

    // ── 5. Consequences ──────────────────────────────────────────────────

    private function consequences(User $user, Carbon $from, Carbon $to): array
    {
        $visibleIds = $this->base($user)->select('investigations.id');

        $actions = fn () => ConsequenceAction::query()->whereIn('investigation_id', $visibleIds);

        $total = $actions()->count();
        $implemented = $actions()->where('status', 'implemented')->count();

        return [
            'by_type' => $actions()
                ->selectRaw('action_type, count(*) as total')
                ->groupBy('action_type')
                ->orderByDesc('total')
                ->pluck('total', 'action_type')
                ->map(fn ($count) => (int) $count)
                ->all(),
            'by_status' => collect(ConsequenceAction::STATUSES)
                ->mapWithKeys(fn (string $status) => [$status => 0])
                ->merge(
                    $actions()->selectRaw('status, count(*) as total')->groupBy('status')
                        ->pluck('total', 'status')->map(fn ($count) => (int) $count),
                )
                ->all(),
            'implementation_rate' => $total > 0 ? (int) round($implemented / $total * 100) : null,
            'overdue' => $actions()->overdue()->count(),
            // Outcomes, counted. Never the people they belong to.
            'subject_outcomes' => DB::table('investigation_subjects')
                ->whereIn('investigation_id', $visibleIds)
                ->whereNull('deleted_at')
                ->selectRaw('outcome, count(*) as total')
                ->groupBy('outcome')
                ->pluck('total', 'outcome')
                ->map(fn ($count) => (int) $count)
                ->all(),
        ];
    }

    // ── 6. By category ───────────────────────────────────────────────────

    private function byCategory(User $user, Carbon $from, Carbon $to): array
    {
        return $this->base($user)
            ->whereBetween('reported_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->pluck('total', 'category')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    // ── 7. By control entity — the §F.3 desk / branch cut ────────────────

    private function byControlEntity(User $user, Carbon $from, Carbon $to): array
    {
        return $this->base($user)
            ->join('control_entities', 'control_entities.id', '=', 'investigations.control_entity_id')
            ->whereBetween('investigations.reported_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('control_entities.id, control_entities.name, control_entities.entity_kind, '
                .'count(*) as total, coalesce(sum(investigations.confirmed_financial_loss), 0) as loss')
            ->groupBy('control_entities.id', 'control_entities.name', 'control_entities.entity_kind')
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'kind' => $row->entity_kind,
                'total' => (int) $row->total,
                'loss' => (float) $row->loss,
            ])
            ->all();
    }

    // ── 8. Ageing ────────────────────────────────────────────────────────

    /**
     * Suspended cases get their own bucket rather than ageing alongside
     * the live ones (§H.5-6): a case waiting six months on a police report
     * is not a case nobody is working on, and mixing the two makes the
     * "90+ days" number an accusation rather than a measure.
     */
    private function ageing(User $user): array
    {
        $today = now()->toDateString();
        $buckets = [];

        foreach (self::AGEING_BUCKETS as $label => [$min, $max]) {
            $query = $this->base($user)->open()->where('status', '!=', 'suspended')
                ->whereDate('reported_date', '<=', now()->subDays($min)->toDateString());

            if ($max !== null) {
                $query->whereDate('reported_date', '>', now()->subDays($max + 1)->toDateString());
            }

            $buckets[] = ['bucket' => $label, 'total' => $query->count()];
        }

        $buckets[] = [
            'bucket' => 'Suspended',
            'total' => $this->base($user)->where('status', 'suspended')->count(),
        ];

        return [
            'buckets' => $buckets,
            'sla_breaches' => $this->base($user)->open()
                ->where('status', '!=', 'suspended')
                ->whereNotNull('target_completion_date')
                ->whereDate('target_completion_date', '<', $today)
                ->count(),
        ];
    }

    // ── 9. Activity feed ─────────────────────────────────────────────────

    /**
     * Type, case reference, actor, timestamp — and deliberately NOT the
     * activity title.
     *
     * A diary title is free text written on the case file, and free text
     * can name a person: "Subject named: A. Teller" is exactly the sort of
     * line that belongs on the case and nowhere near a dashboard. Carrying
     * the type instead makes the leak structurally impossible rather than
     * dependent on how carefully each title was worded.
     */
    private function activityFeed(User $user, int $limit = 15): array
    {
        return DB::table('investigation_activities')
            ->join('investigations', 'investigations.id', '=', 'investigation_activities.investigation_id')
            ->leftJoin('users', 'users.id', '=', 'investigation_activities.performed_by')
            ->whereIn('investigation_activities.investigation_id', $this->base($user)->select('investigations.id'))
            // A confidential-view entry is oversight of the case, not news
            // for the feed — and putting it here would advertise who is
            // reading what.
            ->where('investigation_activities.activity_type', '!=', 'confidential_view')
            ->orderByDesc('investigation_activities.activity_date')
            ->limit($limit)
            ->get([
                'investigation_activities.id',
                'investigation_activities.activity_type',
                'investigation_activities.activity_date',
                'investigations.id as investigation_id',
                'investigations.reference',
                'users.name as performed_by',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    // ── CSV export ───────────────────────────────────────────────────────

    /**
     * The dashboard as a spreadsheet. Same visibility rules, same absence
     * of PII — an export is not a back door.
     *
     * @return array<int, array<int, string|int|float|null>>
     */
    public function exportRows(User $user, array $filters = []): array
    {
        [$from, $to] = $this->period($filters);

        $rows = [['Reference', 'Title', 'Category', 'Source', 'Status', 'Priority', 'Risk rating',
            'Control entity', 'Reported', 'Completed', 'Confirmed loss', 'Recovered', 'Currency']];

        $this->base($user)
            ->with('controlEntity:id,name')
            ->whereBetween('reported_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('reference')
            ->chunk(200, function ($chunk) use (&$rows) {
                foreach ($chunk as $investigation) {
                    $rows[] = [
                        $investigation->reference,
                        $investigation->title,
                        $investigation->category,
                        $investigation->source,
                        $investigation->status,
                        $investigation->priority,
                        $investigation->risk_rating,
                        $investigation->controlEntity?->name,
                        $investigation->reported_date?->toDateString(),
                        $investigation->completed_date?->toDateString(),
                        $investigation->confirmed_financial_loss,
                        $investigation->amount_recovered,
                        $investigation->currency,
                    ];
                }
            });

        return $rows;
    }

    // ── Period arithmetic ────────────────────────────────────────────────

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    public function period(array $filters): array
    {
        if (! empty($filters['from']) && ! empty($filters['to'])) {
            $from = Carbon::parse($filters['from'])->startOfDay();
            $to = Carbon::parse($filters['to'])->endOfDay();

            return [$from, $to, $from->format('d M Y').' – '.$to->format('d M Y')];
        }

        $now = now();

        return match ($filters['period'] ?? 'current_quarter') {
            'current_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), $now->format('F Y')],
            'current_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear(), 'FY '.$now->year],
            'last_12_months' => [$now->copy()->subMonthsNoOverflow(11)->startOfMonth(), $now->copy()->endOfMonth(), 'Last 12 months'],
            default => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter(), 'Q'.$now->quarter.' '.$now->year],
        };
    }

    /** The same length of time, immediately before. @return array{0: Carbon, 1: Carbon} */
    private function previousPeriod(Carbon $from, Carbon $to): array
    {
        $days = max(1, $from->diffInDays($to) + 1);

        return [$from->copy()->subDays($days)->startOfDay(), $from->copy()->subDay()->endOfDay()];
    }

    private function withComparison(int|float|null $current, int|float|null $previous): array
    {
        $change = null;

        if ($current !== null && $previous !== null && $previous != 0) {
            $change = (int) round(($current - $previous) / abs($previous) * 100);
        }

        return ['value' => $current, 'previous' => $previous, 'change' => $change];
    }

    /**
     * Month grouping without a driver-specific dashboard. The source
     * module could assume MySQL; this one runs its test suite on SQLite,
     * and a widget that only aggregates correctly in production is a
     * widget nobody tested.
     */
    private function monthExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'sqlsrv' => "format({$column}, 'yyyy-MM')",
            default => "date_format({$column}, '%Y-%m')",
        };
    }
}
