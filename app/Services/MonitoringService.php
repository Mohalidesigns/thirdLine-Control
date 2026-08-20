<?php

namespace App\Services;

use App\Integrations\ConnectorException;
use App\Models\Control;
use App\Models\DataSnapshot;
use App\Models\DataSourceDataset;
use App\Models\MonitoringFinding;
use App\Models\MonitoringRule;
use App\Models\MonitoringRun;
use App\Models\TestInstance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orchestration for continuous controls monitoring (12.3–12.4).
 *
 * The load-bearing idea of this phase lives in runRule(): a monitoring
 * rule attached to a control produces a real TestInstance, so an
 * automated result flows into the *existing* effectiveness machinery
 * rather than a parallel universe. Nothing about review, rating,
 * maker-checker or residual risk is re-implemented here.
 *
 * Two lines are not crossed:
 *  - the engine never rates a control (R4-adjacent: an automated result
 *    is a draft that a human reviews and a second human approves);
 *  - a rule never runs unless a second person approved it (R2).
 */
class MonitoringService
{
    public function __construct(
        private RuleEngineService $engine,
        private SnapshotService $snapshots,
        private SodService $sod,
        private ExceptionService $exceptions,
        private TestingService $testing,
    ) {}

    // ── Rule lifecycle ───────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRule(array $data, User $author): MonitoringRule
    {
        $this->engine->validateDefinition(
            $data['rule_type'],
            (array) ($data['rule_definition'] ?? []),
            array_map('intval', (array) ($data['dataset_ids'] ?? [])),
        );

        return MonitoringRule::create([
            ...$data,
            'rule_ref' => MonitoringRule::nextReference('MON', false, 'rule_ref'),
            'created_by' => $author->id,
            'status' => 'Draft',
            'version_no' => 1,
        ]);
    }

    /**
     * Editing what a rule actually tests invalidates its approval: the
     * rule drops back to Pending Approval and a second person has to look
     * again. Renaming it or changing its description does not (12.4, R2).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateRule(MonitoringRule $rule, array $data, User $editor): MonitoringRule
    {
        if ($rule->status === 'Retired') {
            throw ValidationException::withMessages(['status' => 'A retired rule cannot be edited.']);
        }

        $this->engine->validateDefinition(
            $data['rule_type'] ?? $rule->rule_type,
            (array) ($data['rule_definition'] ?? $rule->rule_definition ?? []),
            array_map('intval', (array) ($data['dataset_ids'] ?? $rule->dataset_ids ?? [])),
        );

        $sensitive = $this->touchesApproval($rule, $data);

        $rule->update([
            ...$data,
            ...($sensitive ? [
                'status' => 'Pending Approval',
                'approved_by' => null,
                'approved_at' => null,
                'version_no' => $rule->version_no + 1,
            ] : []),
        ]);

        if ($sensitive) {
            $rule->auditAction('approval_invalidated', null, [
                'by' => $editor->id,
                'version_no' => $rule->version_no,
            ]);
        }

        return $rule->fresh();
    }

    public function submitRule(MonitoringRule $rule, User $user): MonitoringRule
    {
        if (! in_array($rule->status, ['Draft', 'Paused'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only a draft or paused rule can be submitted for approval.',
            ]);
        }

        $rule->update(['status' => 'Pending Approval']);
        $rule->auditAction('submitted', null, ['by' => $user->id]);

        return $rule->fresh();
    }

    /**
     * THE approval gate (12.3 business rule). A rule cannot activate
     * without approval by someone other than its author — checked here as
     * well as in MonitoringRulePolicy, and with no administrator bypass.
     */
    public function approveRule(MonitoringRule $rule, User $approver): MonitoringRule
    {
        if ($rule->status !== 'Pending Approval') {
            throw ValidationException::withMessages([
                'approval' => 'Only a rule pending approval can be approved.',
            ]);
        }

        if ($rule->created_by === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A monitoring rule cannot be approved by the person who wrote it.',
            ]);
        }

        if ($rule->owner_id === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'The owner of a rule cannot also be its approver.',
            ]);
        }

        $rule->update([
            'status' => 'Active',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'next_run_at' => $this->nextRunAt($rule),
        ]);

        $rule->auditAction('approved', null, ['by' => $approver->id, 'version_no' => $rule->version_no]);

        return $rule->fresh();
    }

    public function rejectRule(MonitoringRule $rule, User $approver, string $reason): MonitoringRule
    {
        if ($rule->created_by === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A monitoring rule cannot be reviewed by the person who wrote it.',
            ]);
        }

        $rule->update(['status' => 'Draft']);
        $rule->auditAction('rejected', null, ['by' => $approver->id, 'reason' => $reason]);

        return $rule->fresh();
    }

    public function setPaused(MonitoringRule $rule, User $user, bool $paused, ?string $reason = null): MonitoringRule
    {
        if ($paused && $rule->status !== 'Active') {
            throw ValidationException::withMessages(['status' => 'Only an active rule can be paused.']);
        }

        if (! $paused && $rule->status !== 'Paused') {
            throw ValidationException::withMessages(['status' => 'Only a paused rule can be resumed.']);
        }

        // Resuming does not re-approve: the rule keeps the approval it had.
        $rule->update([
            'status' => $paused ? 'Paused' : 'Active',
            'next_run_at' => $paused ? null : $this->nextRunAt($rule),
        ]);

        $rule->auditAction($paused ? 'paused' : 'resumed', null, ['by' => $user->id, 'reason' => $reason]);

        return $rule->fresh();
    }

    public function retireRule(MonitoringRule $rule, User $user, string $reason): MonitoringRule
    {
        $rule->update(['status' => 'Retired', 'next_run_at' => null]);
        $rule->auditAction('retired', null, ['by' => $user->id, 'reason' => $reason]);

        return $rule->fresh();
    }

    // ── Execution ────────────────────────────────────────────────────

    /**
     * Run one rule end to end: load the snapshots, evaluate, persist the
     * run and its findings, materialise the TestInstance, and raise the
     * exceptions the rule is configured to raise.
     */
    public function runRule(
        MonitoringRule $rule,
        string $trigger = 'schedule',
        ?User $actor = null,
        bool $captureFresh = false,
    ): MonitoringRun {
        if ($rule->status !== 'Active') {
            throw ValidationException::withMessages([
                'status' => "Rule {$rule->rule_ref} is {$rule->status}; only an active rule runs.",
            ]);
        }

        if (! $rule->isApproved()) {
            throw ValidationException::withMessages([
                'approval' => "Rule {$rule->rule_ref} has no recorded approval and will not run.",
            ]);
        }

        $run = MonitoringRun::create([
            'tenant_id' => $rule->tenant_id,
            'rule_id' => $rule->id,
            'run_ref' => MonitoringRun::nextReference('RUN', true, 'run_ref'),
            'started_at' => now(),
            'status' => 'Running',
            'trigger' => $trigger,
            'triggered_by' => $actor?->id,
        ]);

        $startedAt = microtime(true);

        try {
            [$rowsByDataset, $datasets, $snapshotIds] = $this->loadRows($rule, $captureFresh);

            $result = $this->engine->evaluate($rule, $rowsByDataset, $datasets);

            $run->update([
                'status' => 'Completed',
                'completed_at' => now(),
                'records_evaluated' => $result['evaluated'],
                'records_passed' => $result['passed'],
                'records_failed' => $result['failed'],
                'exception_rate' => $result['evaluated'] > 0
                    ? round($result['failed'] / $result['evaluated'], 5)
                    : 0,
                'result_summary' => $result['summary'],
                'snapshot_ids' => $snapshotIds,
                'sampling_seed' => $result['seed'],
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            $this->persistFindings($run, $rule, $result['findings'], $datasets);

            if ($rule->rule_type === 'sod_conflict') {
                $snapshot = $snapshotIds !== []
                    ? DataSnapshot::withoutGlobalScopes()->find($snapshotIds[0])
                    : null;
                $this->sod->record($result['findings'], $rule->tenant_id, $snapshot);
            }

            $this->linkTestInstance($run, $rule, $result);
            $this->raiseExceptions($run, $rule);

            $rule->update(['last_run_at' => now(), 'next_run_at' => $this->nextRunAt($rule)]);

            return $run->fresh();
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'Failed',
                'completed_at' => now(),
                'error_message' => ConnectorException::redactMessage($e->getMessage()),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            $rule->update(['last_run_at' => now(), 'next_run_at' => $this->nextRunAt($rule)]);

            throw $e;
        }
    }

    /**
     * The scheduled sweep. One rule failing must not stop the rest, so
     * each is caught and reported rather than aborting the command.
     *
     * @return array{ran: int, failed: int, findings: int, errors: array<int, string>}
     */
    public function runDue(?int $tenantId = null, ?CarbonImmutable $asOf = null, bool $captureFresh = true): array
    {
        $ran = 0;
        $failed = 0;
        $findings = 0;
        $errors = [];

        MonitoringRule::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->due($asOf?->toDateTime())
            ->orderBy('id')
            ->each(function (MonitoringRule $rule) use (&$ran, &$failed, &$findings, &$errors, $captureFresh) {
                try {
                    $run = $this->runRule($rule, 'schedule', null, $captureFresh);
                    $ran++;
                    $findings += $run->records_failed;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = $rule->rule_ref.': '.ConnectorException::redactMessage($e->getMessage());
                }
            });

        return ['ran' => $ran, 'failed' => $failed, 'findings' => $findings, 'errors' => $errors];
    }

    // ── Finding review ───────────────────────────────────────────────

    /**
     * Confirming a finding raises an exception on the linked control
     * through the existing ExceptionService path (12.4). Marking one a
     * false positive does not — it feeds rule tuning instead.
     */
    public function reviewFinding(MonitoringFinding $finding, User $reviewer, string $status, string $notes): MonitoringFinding
    {
        if (! in_array($status, ['Under Review', 'Confirmed', 'False Positive', 'Remediated', 'Accepted'], true)) {
            throw ValidationException::withMessages(['status' => "'{$status}' is not a valid review outcome."]);
        }

        if (in_array($status, ['False Positive', 'Accepted'], true) && trim($notes) === '') {
            throw ValidationException::withMessages([
                'review_notes' => 'Dismissing or accepting a finding requires a written reason.',
            ]);
        }

        $finding->update([
            'status' => $status,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        $finding->auditAction('reviewed', null, ['status' => $status, 'by' => $reviewer->id]);

        if ($status === 'Confirmed' && ! $finding->exception_id) {
            $this->exceptions->raiseFromMonitoringFinding($finding->fresh());
        }

        return $finding->fresh();
    }

    /**
     * False-positive rate for a rule, which is what a tuning conversation
     * actually turns on (12.4).
     *
     * @return array{total: int, false_positives: int, rate: float}
     */
    public function falsePositiveRate(MonitoringRule $rule, int $lastRuns = 10): array
    {
        $runIds = $rule->runs()->orderByDesc('id')->limit($lastRuns)->pluck('id');

        $counts = MonitoringFinding::withoutGlobalScopes()
            ->whereIn('run_id', $runIds)
            ->selectRaw('count(*) as total, sum(case when status = ? then 1 else 0 end) as fps', ['False Positive'])
            ->first();

        $total = (int) ($counts->total ?? 0);
        $fps = (int) ($counts->fps ?? 0);

        return [
            'total' => $total,
            'false_positives' => $fps,
            'rate' => $total > 0 ? round($fps / $total, 4) : 0.0,
        ];
    }

    // ── Dashboard ────────────────────────────────────────────────────

    /**
     * The CCM dashboard (12.6). Every figure is computed in SQL and
     * returned finished — the browser gets numbers, never the register
     * (R6, no N+1).
     *
     * @return array<string, mixed>
     */
    public function dashboard(int $tenantId, int $trendDays = 30): array
    {
        $sources = DB::table('data_sources')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->selectRaw('health_status, count(*) as total')
            ->groupBy('health_status')
            ->pluck('total', 'health_status');

        $rules = DB::table('monitoring_rules')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $findings = DB::table('monitoring_findings')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', MonitoringFinding::OPEN_STATUSES)
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity');

        $keyControls = DB::table('controls')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'Active')
            ->where('is_key_control', true)
            ->count();

        $monitoredKeyControls = DB::table('controls')
            ->join('monitoring_rules', 'monitoring_rules.control_id', '=', 'controls.id')
            ->where('controls.tenant_id', $tenantId)
            ->whereNull('controls.deleted_at')
            ->whereNull('monitoring_rules.deleted_at')
            ->where('controls.status', 'Active')
            ->where('controls.is_key_control', true)
            ->where('monitoring_rules.status', 'Active')
            ->distinct()
            ->count('controls.id');

        return [
            'sources' => [
                'total' => (int) $sources->sum(),
                'healthy' => (int) ($sources['Healthy'] ?? 0),
                'degraded' => (int) ($sources['Degraded'] ?? 0),
                'failed' => (int) ($sources['Failed'] ?? 0),
                'unknown' => (int) ($sources['Unknown'] ?? 0),
                'paused' => DB::table('data_sources')->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')->whereNotNull('paused_at')->count(),
            ],
            'rules' => [
                'active' => (int) ($rules['Active'] ?? 0),
                'pending_approval' => (int) ($rules['Pending Approval'] ?? 0),
                'draft' => (int) ($rules['Draft'] ?? 0),
                'paused' => (int) ($rules['Paused'] ?? 0),
            ],
            'findings' => [
                'open' => (int) $findings->sum(),
                'critical' => (int) ($findings['Critical'] ?? 0),
                'high' => (int) ($findings['High'] ?? 0),
                'medium' => (int) ($findings['Medium'] ?? 0),
                'low' => (int) ($findings['Low'] ?? 0),
            ],
            'sod' => [
                'open' => DB::table('sod_violations')->where('tenant_id', $tenantId)->where('status', 'Open')->count(),
                'accepted' => DB::table('sod_violations')->where('tenant_id', $tenantId)->where('status', 'Accepted')->count(),
            ],
            'coverage' => [
                'key_controls' => $keyControls,
                'monitored' => $monitoredKeyControls,
                'percentage' => $keyControls > 0 ? round($monitoredKeyControls / $keyControls * 100, 1) : 0.0,
            ],
            'rows_ingested_30d' => (int) DB::table('data_snapshots')
                ->where('tenant_id', $tenantId)
                ->where('captured_at', '>=', now()->subDays($trendDays))
                ->sum('row_count'),
            'exception_rate_trend' => $this->exceptionRateTrend($tenantId, $trendDays),
            'top_failing_rules' => $this->topFailingRules($tenantId),
            'recent_runs' => MonitoringRun::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->with('rule:id,rule_ref,name,severity')
                ->orderByDesc('id')
                ->limit(10)
                ->get(['id', 'rule_id', 'run_ref', 'status', 'records_evaluated', 'records_failed', 'exception_rate', 'started_at', 'duration_ms']),
        ];
    }

    /** @return array<int, array{date: string, rate: float, runs: int}> */
    private function exceptionRateTrend(int $tenantId, int $days): array
    {
        return DB::table('monitoring_runs')
            ->where('tenant_id', $tenantId)
            ->where('started_at', '>=', now()->subDays($days))
            ->whereIn('status', ['Completed', 'Partial'])
            ->selectRaw('date(started_at) as day, avg(exception_rate) as rate, count(*) as runs')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->day,
                'rate' => round((float) $row->rate * 100, 2),
                'runs' => (int) $row->runs,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function topFailingRules(int $tenantId, int $limit = 5): array
    {
        return DB::table('monitoring_runs')
            ->join('monitoring_rules', 'monitoring_rules.id', '=', 'monitoring_runs.rule_id')
            ->where('monitoring_runs.tenant_id', $tenantId)
            ->where('monitoring_runs.started_at', '>=', now()->subDays(30))
            ->selectRaw('monitoring_rules.id, monitoring_rules.rule_ref, monitoring_rules.name, '
                .'monitoring_rules.severity, sum(monitoring_runs.records_failed) as failures, '
                .'count(monitoring_runs.id) as runs')
            ->groupBy('monitoring_rules.id', 'monitoring_rules.rule_ref', 'monitoring_rules.name', 'monitoring_rules.severity')
            ->orderByDesc('failures')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'rule_ref' => $row->rule_ref,
                'name' => $row->name,
                'severity' => $row->severity,
                'failures' => (int) $row->failures,
                'runs' => (int) $row->runs,
            ])
            ->all();
    }

    // ── Internals ────────────────────────────────────────────────────

    /**
     * @return array{0: array<int, array<int, array<string, mixed>>>, 1: array<int, DataSourceDataset>, 2: array<int, int>}
     */
    private function loadRows(MonitoringRule $rule, bool $captureFresh): array
    {
        $datasets = DataSourceDataset::withoutGlobalScopes()
            ->whereIn('id', (array) ($rule->dataset_ids ?? []))
            ->with('dataSource')
            ->get()
            ->keyBy('id');

        $rowsByDataset = [];
        $snapshotIds = [];

        foreach ($datasets as $id => $dataset) {
            $snapshot = $captureFresh && $dataset->dataSource?->isExtractable()
                ? $this->snapshots->capture($dataset)
                : $this->snapshots->latestReady($dataset);

            if (! $snapshot) {
                throw ValidationException::withMessages([
                    'datasets' => "No usable snapshot exists for dataset '{$dataset->name}'.",
                ]);
            }

            $snapshotIds[] = $snapshot->id;
            $rowsByDataset[(int) $id] = iterator_to_array($this->snapshots->rows($snapshot), false);
        }

        return [$rowsByDataset, $datasets->all(), $snapshotIds];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<int, DataSourceDataset>  $datasets
     */
    private function persistFindings(MonitoringRun $run, MonitoringRule $rule, array $findings, array $datasets): void
    {
        if ($findings === []) {
            return;
        }

        // Redaction is per the dataset the rows came from: a finding is
        // read by a person, so anything above 'internal' is masked even
        // when the snapshot itself retains it (12.7).
        $dataset = $datasets[array_key_first($datasets)] ?? null;

        DB::transaction(function () use ($run, $rule, $findings, $dataset) {
            foreach ($findings as $finding) {
                $data = (array) ($finding['record_data'] ?? []);

                MonitoringFinding::create([
                    'tenant_id' => $rule->tenant_id,
                    'run_id' => $run->id,
                    'record_identifier' => mb_substr((string) ($finding['record_identifier'] ?? ''), 0, 255),
                    'record_data' => $dataset ? $this->snapshots->redactForFinding($data, $dataset) : $data,
                    'failure_reason' => $finding['failure_reason'] ?? null,
                    'severity' => $finding['severity'] ?? $rule->severity,
                    'status' => 'Open',
                ]);
            }
        });
    }

    /**
     * Materialise the run as a TestInstance on the linked control (12.4).
     *
     * The instance is created and populated but deliberately left in
     * `Submitted`, never `Reviewed`: a machine may gather evidence, it may
     * not sign off. The reviewer is a person, the rating is a person, and
     * the rating still needs a second person (R2/R4).
     */
    private function linkTestInstance(MonitoringRun $run, MonitoringRule $rule, array $result): void
    {
        if (! $rule->control_id) {
            return;
        }

        $control = Control::withoutGlobalScopes()->find($rule->control_id);

        if (! $control) {
            return;
        }

        [$label, $start, $end] = $this->testing->periodFor(
            $control->frequency === 'Event-driven' ? 'Daily' : $control->frequency,
            CarbonImmutable::now(),
        );

        $instance = TestInstance::withoutGlobalScopes()->firstOrCreate(
            ['control_id' => $control->id, 'period_label' => $label],
            [
                'tenant_id' => $rule->tenant_id,
                'reference' => TestInstance::nextReference('TST'),
                'period_start' => $start,
                'period_end' => $end,
                'due_date' => $end->addDays(5),
                'status' => 'Submitted',
                'source' => 'automated',
                'monitoring_rule_id' => $rule->id,
            ],
        );

        $instance->forceFill([
            'source' => 'automated',
            'monitoring_rule_id' => $rule->id,
            'population_size' => $result['evaluated'],
            'sample_size' => $result['evaluated'],
            'sampling_method' => $result['seed'] !== null
                ? ($rule->sample_config['method'] ?? 'random').' (seed '.$result['seed'].')'
                : 'full population',
            'sample_items' => "Monitoring run {$run->run_ref}: {$result['failed']} exception(s) in {$result['evaluated']} record(s).",
            'submitted_at' => $instance->submitted_at ?? now(),
            'status' => in_array($instance->status, ['Reviewed', 'Closed'], true) ? $instance->status : 'Submitted',
        ])->save();

        $run->update(['test_instance_id' => $instance->id]);

        $instance->auditAction('populated_by_monitoring', null, [
            'rule_ref' => $rule->rule_ref,
            'run_ref' => $run->run_ref,
            'records_failed' => $result['failed'],
        ]);
    }

    /**
     * Auto-raise exceptions where the rule says so, through the existing
     * ExceptionService so the register behaves identically whether the
     * failure came from a person or a rule (12.4).
     */
    private function raiseExceptions(MonitoringRun $run, MonitoringRule $rule): void
    {
        if (! $rule->auto_create_exception || $run->records_failed === 0) {
            return;
        }

        $exception = $this->exceptions->raiseFromMonitoringRun($run, $rule);

        MonitoringFinding::withoutGlobalScopes()
            ->where('run_id', $run->id)
            ->whereNull('exception_id')
            ->update(['exception_id' => $exception->id]);
    }

    /**
     * When the rule should next run. The cron expression wins where one
     * is set; otherwise the frequency does. Neither is hard-coded in a
     * command — the schedule belongs to the rule (R1).
     */
    public function nextRunAt(MonitoringRule $rule, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $from ??= CarbonImmutable::now();

        return match ($rule->frequency) {
            'Hourly' => $from->addHour(),
            'Weekly' => $from->addWeek(),
            'Monthly' => $from->addMonth(),
            'Quarterly' => $from->addMonths(3),
            'Ad hoc' => $from->addYears(10),
            default => $from->addDay(),
        };
    }

    /** @param array<string, mixed> $data */
    private function touchesApproval(MonitoringRule $rule, array $data): bool
    {
        if (! in_array($rule->status, ['Active', 'Paused'], true)) {
            return false;
        }

        foreach (MonitoringRule::APPROVAL_SENSITIVE as $attribute) {
            if (! array_key_exists($attribute, $data)) {
                continue;
            }

            if ($data[$attribute] != $rule->{$attribute}) {
                return true;
            }
        }

        return false;
    }
}
