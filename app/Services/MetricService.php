<?php

namespace App\Services;

use App\Models\ControlException;
use App\Models\Metric;
use App\Models\MetricBreach;
use App\Models\MetricThreshold;
use App\Models\MetricValue;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ExpressionEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The KRI/KPI engine (10.5). A metric definition, its threshold bands and
 * its readings are all data; this service only captures values, decides
 * which band a reading falls in, and opens a breach when it should.
 *
 * Calculated metrics go through ExpressionEvaluator — a whitelisted
 * recursive-descent parser. eval() is never called (R4-adjacent: nothing
 * the platform executes comes from user text unvalidated).
 */
class MetricService
{
    /** Levels that open a breach record by default. */
    public const DEFAULT_BREACH_LEVELS = ['Red', 'Critical'];

    public function __construct(private EscalationService $escalationService, private LinkageService $linkage) {}

    // ── Configuration ────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function config(?int $tenantId): array
    {
        $settings = $tenantId
            ? (Tenant::withoutGlobalScopes()->find($tenantId)?->settings['risk'] ?? [])
            : [];

        return [
            // 'exception' | 'incident' | 'none'. The incident module lands in
            // Phase 11; until then 'incident' behaves as 'exception' and the
            // breach records which it was.
            'kri_breach_action' => $settings['kri_breach_action'] ?? 'exception',
            'breach_levels' => $settings['kri_breach_levels'] ?? self::DEFAULT_BREACH_LEVELS,
            'breach_severity' => $settings['kri_breach_severity'] ?? ['Critical' => 'Critical', 'Red' => 'High', 'Amber' => 'Medium'],
        ];
    }

    // ── Periods ──────────────────────────────────────────────────────

    /**
     * The reporting period a date falls in, for the metric's frequency.
     * Computed in the tenant's timezone, never the server's (R7).
     *
     * @return array{label: string, start: CarbonImmutable, end: CarbonImmutable}
     */
    public function periodFor(Metric $metric, ?CarbonImmutable $moment = null): array
    {
        $timezone = Tenant::withoutGlobalScopes()->find($metric->tenant_id)?->timezone ?? 'Africa/Lagos';
        $moment = ($moment ?? CarbonImmutable::now())->setTimezone($timezone);

        return match ($metric->frequency) {
            'Daily' => [
                'label' => $moment->format('Y-m-d'),
                'start' => $moment->startOfDay(),
                'end' => $moment->endOfDay(),
            ],
            'Weekly' => [
                'label' => $moment->format('o-\WW'),
                'start' => $moment->startOfWeek(),
                'end' => $moment->endOfWeek(),
            ],
            'Quarterly' => [
                'label' => $moment->format('Y').'-Q'.$moment->quarter,
                'start' => $moment->firstOfQuarter(),
                'end' => $moment->lastOfQuarter(),
            ],
            'Semi-annual' => [
                'label' => $moment->format('Y').'-H'.($moment->month <= 6 ? 1 : 2),
                'start' => $moment->setMonth($moment->month <= 6 ? 1 : 7)->startOfMonth(),
                'end' => $moment->setMonth($moment->month <= 6 ? 6 : 12)->endOfMonth(),
            ],
            'Annual' => [
                'label' => $moment->format('Y'),
                'start' => $moment->startOfYear(),
                'end' => $moment->endOfYear(),
            ],
            default => [
                'label' => $moment->format('Y-m'),
                'start' => $moment->startOfMonth(),
                'end' => $moment->endOfMonth(),
            ],
        };
    }

    // ── Capture ──────────────────────────────────────────────────────

    /**
     * Record a reading and evaluate it. Re-capturing the same period
     * updates that period rather than creating a second row, so a
     * corrected number never double-counts.
     */
    public function capture(Metric $metric, array $data, ?User $capturedBy = null, string $source = 'manual'): MetricValue
    {
        $period = isset($data['period_label'])
            ? $this->periodFromLabel($metric, $data['period_label'])
            : $this->periodFor($metric);

        $value = (float) $data['value'];
        $target = array_key_exists('target', $data) && $data['target'] !== null
            ? (float) $data['target']
            : $metric->target_value;

        $threshold = $this->evaluateThreshold($metric, $value);

        $record = MetricValue::updateOrCreate(
            ['metric_id' => $metric->id, 'period_label' => $period['label']],
            [
                'tenant_id' => $metric->tenant_id,
                'period_start' => $period['start']->toDateString(),
                'period_end' => $period['end']->toDateString(),
                'value' => $value,
                'target' => $target,
                'threshold_level_hit' => $threshold?->level,
                'breach_flag' => $threshold !== null
                    && in_array($threshold->level, $this->config($metric->tenant_id)['breach_levels'], true),
                'variance_from_target' => $target !== null ? round($value - $target, 6) : null,
                'source' => $source,
                'captured_by' => $capturedBy?->id,
                'captured_at' => now(),
                'comment' => $data['comment'] ?? null,
                'supporting_evidence_id' => $data['supporting_evidence_id'] ?? null,
            ],
        );

        if ($record->breach_flag) {
            $this->openBreach($metric, $record->fresh());
        }

        return $record->fresh();
    }

    /**
     * First matching band wins, in the tenant's configured order — so a
     * Critical band listed before Red is what a value hits when both match.
     */
    public function evaluateThreshold(Metric $metric, float $value): ?MetricThreshold
    {
        return $metric->thresholds()
            ->withoutGlobalScopes()
            ->orderBy('sort_order')
            ->get()
            ->first(fn (MetricThreshold $threshold) => $threshold->matches($value));
    }

    /**
     * A Red or Critical reading opens a breach, escalates it, and — per
     * tenant configuration — raises a linked exception. The link is written
     * both ways through the graph so the KRI shows on the exception and the
     * exception shows on the KRI (10.5, 10.6).
     */
    public function openBreach(Metric $metric, MetricValue $value): ?MetricBreach
    {
        $existing = MetricBreach::withoutGlobalScopes()
            ->where('metric_value_id', $value->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $breach = MetricBreach::create([
            'tenant_id' => $metric->tenant_id,
            'metric_id' => $metric->id,
            'metric_value_id' => $value->id,
            'level' => $value->threshold_level_hit,
            'detected_at' => now(),
        ]);

        $action = $this->config($metric->tenant_id)['kri_breach_action'];

        if (in_array($action, ['exception', 'incident'], true)) {
            $exception = $this->raiseException($metric, $value, $breach, $action);
            $breach->update(['exception_id' => $exception->id]);

            $this->linkage->link(
                'metric', $metric->id, 'exception', $exception->id,
                'indicates', null, "Opened by {$value->period_label} breach at {$breach->level}."
            );
        }

        $this->escalationService->escalateMetricBreach($breach->fresh());

        $metric->auditAction('breached', null, [
            'period' => $value->period_label,
            'level' => $breach->level,
            'value' => $value->value,
        ]);

        return $breach->fresh();
    }

    public function acknowledgeBreach(MetricBreach $breach, User $user, array $data): MetricBreach
    {
        $breach->update([
            'acknowledged_by' => $user->id,
            'acknowledged_at' => now(),
            'root_cause' => $data['root_cause'] ?? $breach->root_cause,
            'action_taken' => $data['action_taken'] ?? $breach->action_taken,
        ]);

        return $breach;
    }

    public function resolveBreach(MetricBreach $breach, User $user, string $note): MetricBreach
    {
        if (! $breach->acknowledged_at) {
            throw ValidationException::withMessages([
                'resolution' => 'A breach must be acknowledged before it can be resolved.',
            ]);
        }

        $breach->update(['resolved_at' => now(), 'action_taken' => $note]);
        $breach->auditAction('resolved', null, ['by' => $user->id]);

        return $breach;
    }

    // ── Calculated metrics ───────────────────────────────────────────

    /** Reject an unsafe or unresolvable expression before it is saved. */
    public function validateExpression(int $tenantId, string $expression): void
    {
        ExpressionEvaluator::assertSafe($expression);

        $codes = ExpressionEvaluator::referencedCodes($expression);

        $known = Metric::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('code', $codes)
            ->pluck('code')
            ->all();

        $unknown = array_diff($codes, $known);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'calculation_expression' => 'Unknown metric reference(s): '.implode(', ', $unknown),
            ]);
        }
    }

    /**
     * Evaluate one calculated metric for the current period, using the
     * referenced metrics' readings for that same period.
     */
    public function computeCalculated(Metric $metric, ?CarbonImmutable $moment = null): ?MetricValue
    {
        if ($metric->source !== 'calculated' || ! $metric->calculation_expression) {
            return null;
        }

        $period = $this->periodFor($metric, $moment);
        $codes = ExpressionEvaluator::referencedCodes($metric->calculation_expression);

        $references = MetricValue::withoutGlobalScopes()
            ->where('metric_values.tenant_id', $metric->tenant_id)
            ->where('period_label', $period['label'])
            ->join('metrics', 'metrics.id', '=', 'metric_values.metric_id')
            ->whereIn('metrics.code', $codes)
            ->pluck('metric_values.value', 'metrics.code')
            ->map(fn ($value) => (float) $value)
            ->all();

        $missing = array_diff($codes, array_keys($references));

        if ($missing !== []) {
            Log::info('Calculated metric skipped — missing inputs', [
                'metric' => $metric->code,
                'period' => $period['label'],
                'missing' => array_values($missing),
            ]);

            return null;
        }

        try {
            $value = (new ExpressionEvaluator($references))->evaluate($metric->calculation_expression);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Calculated metric expression failed', [
                'metric' => $metric->code,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->capture($metric, [
            'value' => $value,
            'period_label' => $period['label'],
            'comment' => 'Computed from '.$metric->calculation_expression,
        ], null, 'calculated');
    }

    /**
     * The daily engine run: compute every calculated metric, re-evaluate
     * thresholds on the latest readings, open breaches, escalate.
     *
     * @return array{calculated: int, breaches: int, evaluated: int}
     */
    public function runEvaluation(?int $tenantId = null): array
    {
        $calculated = 0;
        $breaches = 0;
        $evaluated = 0;

        Metric::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->active()
            ->calculated()
            ->each(function (Metric $metric) use (&$calculated) {
                $this->computeCalculated($metric) && $calculated++;
            });

        Metric::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->active()
            ->each(function (Metric $metric) use (&$breaches, &$evaluated) {
                $latest = $metric->values()->withoutGlobalScopes()->reorder()->orderByDesc('period_start')->first();

                if (! $latest) {
                    return;
                }

                $evaluated++;
                $threshold = $this->evaluateThreshold($metric, (float) $latest->value);
                $breachLevels = $this->config($metric->tenant_id)['breach_levels'];
                $isBreach = $threshold !== null && in_array($threshold->level, $breachLevels, true);

                if ($latest->threshold_level_hit !== $threshold?->level || $latest->breach_flag !== $isBreach) {
                    $latest->update([
                        'threshold_level_hit' => $threshold?->level,
                        'breach_flag' => $isBreach,
                    ]);
                }

                if ($isBreach && $this->openBreach($metric, $latest->fresh())) {
                    $breaches++;
                }
            });

        return ['calculated' => $calculated, 'breaches' => $breaches, 'evaluated' => $evaluated];
    }

    // ── Presentation helpers ─────────────────────────────────────────

    /**
     * The trend series behind a sparkline or the full chart on the metric
     * page — server-computed and capped, so a page never ships years of
     * readings to a phone (R6).
     *
     * @return array<int, array<string, mixed>>
     */
    public function trend(Metric $metric, int $limit = 24): array
    {
        return $metric->values()
            ->reorder()
            ->orderByDesc('period_start')
            ->limit($limit)
            ->get(['period_label', 'period_start', 'value', 'target', 'threshold_level_hit', 'breach_flag'])
            ->reverse()
            ->map(fn (MetricValue $value) => [
                'period' => $value->period_label,
                'date' => $value->period_start->toDateString(),
                'value' => (float) $value->value,
                'target' => $value->target !== null ? (float) $value->target : null,
                'level' => $value->threshold_level_hit,
                'breach' => (bool) $value->breach_flag,
            ])
            ->values()
            ->all();
    }

    /**
     * Sparkline series for a list of metrics in one query — the index page
     * must not run one query per row (R6, no N+1).
     *
     * @param  Collection<int, Metric>  $metrics
     * @return array<int, array<int, float>>
     */
    public function sparklines(Collection $metrics, int $points = 12): array
    {
        if ($metrics->isEmpty()) {
            return [];
        }

        return MetricValue::withoutGlobalScopes()
            ->whereIn('metric_id', $metrics->pluck('id'))
            ->orderBy('period_start')
            ->get(['metric_id', 'value'])
            ->groupBy('metric_id')
            ->map(fn ($values) => $values->pluck('value')
                ->map(fn ($value) => (float) $value)
                ->slice(-$points)
                ->values()
                ->all())
            ->all();
    }

    private function periodFromLabel(Metric $metric, string $label): array
    {
        $existing = MetricValue::withoutGlobalScopes()
            ->where('metric_id', $metric->id)
            ->where('period_label', $label)
            ->first();

        if ($existing) {
            return [
                'label' => $label,
                'start' => CarbonImmutable::parse($existing->period_start),
                'end' => CarbonImmutable::parse($existing->period_end),
            ];
        }

        // An explicit label for a period that has not been captured yet:
        // anchor it on the label's own date where we can parse one.
        try {
            $anchor = CarbonImmutable::parse(preg_replace('/-Q(\d)/', '-'.'01', $label));

            return $this->periodFor($metric, $anchor);
        } catch (\Throwable) {
            $period = $this->periodFor($metric);
            $period['label'] = $label;

            return $period;
        }
    }

    private function raiseException(Metric $metric, MetricValue $value, MetricBreach $breach, string $action): ControlException
    {
        $severityMap = $this->config($metric->tenant_id)['breach_severity'];

        return ControlException::create([
            'tenant_id' => $metric->tenant_id,
            'reference' => ControlException::nextReference('EXC'),
            'source_type' => 'KRI',
            'source_id' => $breach->id,
            'title' => "{$metric->code} {$breach->level} breach — {$metric->name}",
            'description' => "{$metric->name} recorded {$value->value} {$metric->unit} for {$value->period_label}"
                .($value->target !== null ? " against a target of {$value->target}." : '.')
                .($action === 'incident' ? ' Tenant policy routes KRI breaches to incident management.' : ''),
            'severity' => $severityMap[$breach->level] ?? 'Medium',
            'owner_id' => $metric->owner_id,
            'raised_by' => $metric->owner_id,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays(match ($breach->level) {
                'Critical' => 7,
                'Red' => 14,
                default => 30,
            })->toDateString(),
            'status' => $metric->owner_id ? 'Assigned' : 'Open',
            'responsible_party_id' => $metric->owner_id,
        ]);
    }
}
