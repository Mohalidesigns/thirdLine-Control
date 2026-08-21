<?php

namespace App\Services;

use App\Models\CheckResult;
use App\Models\ControlException;
use App\Models\Finding;
use App\Models\MonitoringFinding;
use App\Models\MonitoringRule;
use App\Models\MonitoringRun;
use App\Models\SodViolation;
use App\Models\TestInstance;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ExceptionService
{
    /**
     * Guarded lifecycle (FR-5.3). Verified-Closed and Risk Accepted are terminal.
     */
    public const TRANSITIONS = [
        'Open' => ['Assigned', 'In Progress', 'Risk Accepted'],
        'Assigned' => ['In Progress', 'Remediated', 'Risk Accepted'],
        'In Progress' => ['Remediated', 'Risk Accepted'],
        'Remediated' => ['Verified-Closed', 'In Progress'],
        'Verified-Closed' => [],
        'Risk Accepted' => [],
    ];

    /**
     * Auto-raise an exception from a failed check item (FR-3.6), with
     * recurrence detection against prior periods (FR-5.9).
     */
    public function raiseFromCheckResult(TestInstance $instance, CheckResult $failed): ControlException
    {
        $existing = ControlException::withoutGlobalScopes()
            ->where('check_result_id', $failed->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $control = $instance->control;

        $prior = ControlException::withoutGlobalScopes()
            ->where('control_id', $control->id)
            ->where('source_type', 'Test')
            ->where('source_id', '!=', $instance->id)
            ->orderByDesc('date_raised')
            ->first();

        return $this->notifyRaised(ControlException::create([
            'tenant_id' => $instance->tenant_id,
            'reference' => ControlException::nextReference('EXC'),
            'source_type' => 'Test',
            'source_id' => $instance->id,
            'control_id' => $control->id,
            'risk_id' => $control->risks()->first()?->id,
            'check_result_id' => $failed->id,
            'title' => 'Failed check: '.str($failed->checkItem->question)->limit(120),
            'description' => $failed->comment,
            'severity' => $failed->checkItem->default_severity_on_fail,
            'owner_id' => $control->owner_id,
            'unit_id' => $control->unit_id,
            'raised_by' => $failed->tested_by,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays($this->defaultClosureDays($failed->checkItem->default_severity_on_fail))->toDateString(),
            'status' => $control->owner_id ? 'Assigned' : 'Open',
            'is_recurring' => (bool) $prior,
            'recurrence_of_exception_id' => $prior?->id,
        ]));
    }

    public function raiseFromFinding(Finding $finding): ControlException
    {
        $existing = ControlException::withoutGlobalScopes()
            ->where('source_type', 'Spot Check')
            ->where('source_id', $finding->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $spotCheck = $finding->spotCheck;

        return $this->notifyRaised(ControlException::create([
            'tenant_id' => $spotCheck->tenant_id,
            'reference' => ControlException::nextReference('EXC'),
            'source_type' => 'Spot Check',
            'source_id' => $finding->id,
            'control_id' => $finding->control_id,
            'title' => 'Spot check finding: '.str($finding->observation)->limit(120),
            'description' => $finding->observation,
            'severity' => $finding->severity,
            'owner_id' => $finding->control?->owner_id,
            'responsible_party_id' => $finding->responsible_party_id,
            'unit_id' => $spotCheck->unit_id,
            'raised_by' => $spotCheck->conducted_by,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => $finding->target_date?->toDateString()
                ?? now()->addDays($this->defaultClosureDays($finding->severity))->toDateString(),
            'status' => $finding->control?->owner_id ? 'Assigned' : 'Open',
        ]));
    }

    /**
     * A monitoring run that found failures raises ONE exception
     * summarising them (12.4). One per run, not one per row: a rule that
     * finds four thousand duplicate mandates must not create four
     * thousand exceptions and bury the register.
     */
    public function raiseFromMonitoringRun(MonitoringRun $run, MonitoringRule $rule): ControlException
    {
        $existing = ControlException::withoutGlobalScopes()
            ->where('source_type', 'Monitoring')
            ->where('source_id', $run->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $template = (array) ($rule->exception_template ?? []);
        $control = $rule->control;
        $severity = (string) ($template['severity'] ?? $rule->severity);

        return $this->notifyRaised(ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $rule->tenant_id,
            'reference' => ControlException::nextReference('EXC'),
            'source_type' => 'Monitoring',
            'source_id' => $run->id,
            'control_id' => $rule->control_id,
            'risk_id' => $control?->risks()->first()?->id,
            'title' => mb_substr(
                (string) ($template['title'] ?? "{$rule->name} — {$run->records_failed} exception(s) detected"),
                0, 255,
            ),
            'description' => (string) ($template['description'] ?? sprintf(
                'Monitoring run %s evaluated %d record(s) against rule %s and found %d exception(s) (%.2f%%).',
                $run->run_ref, $run->records_evaluated, $rule->rule_ref, $run->records_failed,
                $run->exception_rate * 100,
            )),
            'severity' => $severity,
            'owner_id' => $control?->owner_id,
            'responsible_party_id' => $control?->owner_id,
            'unit_id' => $control?->unit_id,
            'raised_by' => $rule->owner_id,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays($this->defaultClosureDays($severity))->toDateString(),
            'status' => $control?->owner_id ? 'Assigned' : 'Open',
        ]));
    }

    /**
     * A single finding a human has confirmed. Distinct from the run-level
     * exception above: this one exists because a person looked at a row
     * and said it was real.
     */
    public function raiseFromMonitoringFinding(MonitoringFinding $finding): ControlException
    {
        if ($finding->exception_id) {
            return $finding->exception;
        }

        $rule = $finding->run?->rule;
        $control = $rule?->control;

        $exception = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $finding->tenant_id,
            'reference' => ControlException::nextReference('EXC'),
            'source_type' => 'Monitoring',
            'source_id' => $finding->run_id,
            'control_id' => $rule?->control_id,
            'title' => mb_substr(
                'Confirmed monitoring finding — '.($finding->record_identifier ?: ($rule?->name ?? 'record')),
                0, 255,
            ),
            'description' => (string) $finding->failure_reason,
            'severity' => $finding->severity,
            'owner_id' => $control?->owner_id,
            'responsible_party_id' => $control?->owner_id,
            'unit_id' => $control?->unit_id,
            'raised_by' => $finding->reviewed_by,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays($this->defaultClosureDays($finding->severity))->toDateString(),
            'status' => $control?->owner_id ? 'Assigned' : 'Open',
        ]);

        $finding->update(['exception_id' => $exception->id]);

        return $this->notifyRaised($exception);
    }

    /**
     * An unmitigated toxic combination in a client system (12.3). It is
     * raised against the mitigating control where the matrix names one,
     * so the failure lands on the control that was supposed to catch it.
     */
    public function raiseFromSodViolation(SodViolation $violation): ControlException
    {
        if ($violation->exception_id) {
            return $violation->exception;
        }

        $rule = $violation->rule;

        $exception = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $violation->tenant_id,
            'reference' => ControlException::nextReference('EXC'),
            'source_type' => 'SoD',
            'source_id' => $violation->id,
            'control_id' => $rule->mitigating_control_id,
            'title' => mb_substr('SoD conflict — '.$rule->name.' ('.$violation->subject_identifier.')', 0, 255),
            'description' => sprintf(
                '%s holds both "%s" and "%s" in %s. Detected by continuous monitoring on %s.',
                $violation->subject_name ?: $violation->subject_identifier,
                $rule->function_a,
                $rule->function_b,
                $rule->system_key ?: 'the source system',
                $violation->detected_at?->toDateString() ?? now()->toDateString(),
            ),
            'severity' => $rule->risk_level,
            'unit_id' => $violation->entity_id,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays($this->defaultClosureDays($rule->risk_level))->toDateString(),
            'status' => 'Open',
        ]);

        $violation->update(['exception_id' => $exception->id]);

        return $this->notifyRaised($exception);
    }

    /**
     * CR2-A (CR2A.4): every raise path tells the co-owner units of a
     * shared control that it failed. Fire-and-log — a notification
     * hiccup never blocks the raise itself.
     */
    public function notifyRaised(ControlException $exception): ControlException
    {
        app(ControlStructureService::class)->notifySharedExceptionRaised($exception);

        return $exception;
    }

    public function assign(ControlException $exception, User $assignee, User $actor): ControlException
    {
        $this->transition($exception, in_array($exception->status, ['Open'], true) ? 'Assigned' : $exception->status, [
            'responsible_party_id' => $assignee->id,
        ]);

        $exception->logActivity('Assignment', null, $assignee->name);

        return $exception;
    }

    public function markInProgress(ControlException $exception, ?string $plan = null, ?array $planDoc = null): ControlException
    {
        $this->transition($exception, 'In Progress', array_filter([
            'remediation_plan' => $plan,
            'remediation_plan_rich' => $planDoc,
        ]));
        $exception->logActivity('Status Change', 'Assigned', 'In Progress');

        return $exception;
    }

    /**
     * The furthest a control owner can take an exception (FR-5.4).
     */
    public function markRemediated(ControlException $exception, User $user, string $note): ControlException
    {
        $from = $exception->status;

        $this->transition($exception, 'Remediated', [
            'remediated_at' => now(),
            'remediated_by' => $user->id,
        ]);

        $exception->logActivity('Status Change', $from, 'Remediated', $note);

        return $exception;
    }

    /**
     * THE closure rule (FR-5.4, FR-5.5): only the control function verifies
     * and closes, with method + date recorded; SoD is re-checked here even
     * though ExceptionPolicy already gates the route.
     */
    public function verifyAndClose(ControlException $exception, User $verifier, array $verification): ControlException
    {
        if (! $verifier->hasRole('Control Function Head')) {
            throw ValidationException::withMessages([
                'closure' => 'Only the control function may verify and close an exception.',
            ]);
        }

        if ($this->userPerformedSourceTest($exception, $verifier)) {
            throw ValidationException::withMessages([
                'closure' => 'A user cannot close an exception arising from a test they personally performed.',
            ]);
        }

        if ($exception->control && $exception->control->owner_id === $verifier->id) {
            throw ValidationException::withMessages([
                'closure' => 'A control owner cannot close an exception on their own control.',
            ]);
        }

        // DEF-003: the fourth closure rule, previously enforced only in
        // ExceptionPolicy — re-checked here so a non-HTTP caller (console
        // command, listener) cannot silently lose the guard.
        if ($exception->owner_id === $verifier->id) {
            throw ValidationException::withMessages([
                'closure' => 'The owner of an exception cannot verify and close it.',
            ]);
        }

        // CR-01 (R-D): the departmental loop must be closed before the
        // control lapse can be — the error names the open escalations.
        app(ExceptionEscalationService::class)->assertClosable($exception);

        $this->transition($exception, 'Verified-Closed', [
            'verification_method' => $verification['verification_method'],
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'closure_notes' => $verification['closure_notes'] ?? null,
            'closure_notes_rich' => $verification['closure_notes_rich'] ?? null,
            'is_overdue' => false,
            'closure_type' => 'Remediated',
        ]);

        $exception->logActivity('Verification', 'Remediated', 'Verified-Closed', $verification['verification_method']);

        // Closure is restricted to the control function, so every closure
        // event is high-value evidence: name the verifier in the record
        // itself, not only in the row diff (CR3).
        $exception->auditAction('closed', null, array_merge($verification, [
            'verified_by' => $verifier->id,
            'verified_by_name' => $verifier->name,
        ]));

        app(IntegrationService::class)->publishException($exception);

        return $exception;
    }

    /** Failed verification sends the exception back and resumes escalation (FR-8.8). */
    public function failVerification(ControlException $exception, User $verifier, string $reason): ControlException
    {
        $this->transition($exception, 'In Progress');
        $exception->logActivity('Verification', 'Remediated', 'In Progress', 'Verification failed: '.$reason);

        return $exception;
    }

    public function requestExtension(ControlException $exception, User $owner, string $newDate, string $reason): ControlException
    {
        $exception->logActivity('Extension Request', $exception->target_closure_date?->toDateString(), $newDate, $reason);

        return $exception;
    }

    public function decideExtension(ControlException $exception, User $approver, bool $approved, string $newDate, string $reason): ControlException
    {
        if ($approved) {
            $old = $exception->target_closure_date?->toDateString();
            $exception->update([
                'target_closure_date' => $newDate,
                'extension_count' => $exception->extension_count + 1,
                'is_overdue' => now()->startOfDay()->gt($newDate),
            ]);
            $exception->logActivity('Extension Decision', $old, $newDate, 'Approved: '.$reason);
        } else {
            $exception->logActivity('Extension Decision', null, null, 'Declined: '.$reason);
        }

        return $exception;
    }

    /**
     * Formal risk acceptance — senior approval, expiry, re-confirmation (FR-5.8).
     */
    public function acceptRisk(ControlException $exception, User $approver, string $reason, string $expiryDate, ?array $reasonDoc = null): ControlException
    {
        if (! $approver->hasAnyRole(['Control Function Head', 'System Administrator'])) {
            throw ValidationException::withMessages([
                'risk_acceptance' => 'Risk acceptance requires senior approval.',
            ]);
        }

        $from = $exception->status;

        $this->transition($exception, 'Risk Accepted', [
            'risk_acceptance_reason' => $reason,
            'risk_acceptance_reason_rich' => $reasonDoc,
            'risk_accepted_by' => $approver->id,
            'risk_acceptance_expiry' => $expiryDate,
            'is_overdue' => false,
            'closure_type' => 'Risk Accepted',
        ]);

        $exception->logActivity('Status Change', $from, 'Risk Accepted', $reason);

        // CR-01 (CR1.5): risk acceptance ends the departmental loop — every
        // open escalation is withdrawn with the acceptance as the reason.
        app(ExceptionEscalationService::class)->withdrawAllOpen(
            $exception,
            $approver,
            "Risk accepted on {$exception->reference}: {$reason}",
        );

        return $exception;
    }

    /**
     * Daily ageing refresh (FR-5.6): age from date_raised, overdue past
     * target closure date. Expired risk acceptances reopen for re-confirmation.
     */
    public function refreshAgeing(): int
    {
        $count = 0;

        ControlException::withoutGlobalScopes()
            ->whereIn('status', ControlException::OPEN_STATUSES)
            ->chunkById(200, function ($exceptions) use (&$count) {
                foreach ($exceptions as $exception) {
                    // DEF-006: per-row isolation. One anomalous row (e.g. a
                    // future date_raised producing a negative age for the
                    // unsigned column) must not silently disable overdue
                    // detection for every row after it. Clamp, and log
                    // rather than abort.
                    try {
                        $exception->updateQuietly([
                            'age_days' => max(0, (int) $exception->date_raised->diffInDays(now()->startOfDay())),
                            'is_overdue' => $exception->target_closure_date !== null
                                && now()->startOfDay()->gt($exception->target_closure_date),
                        ]);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::warning('Ageing sweep skipped an exception', [
                            'exception_id' => $exception->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        ControlException::withoutGlobalScopes()
            ->where('status', 'Risk Accepted')
            ->whereDate('risk_acceptance_expiry', '<', now())
            ->each(function (ControlException $exception) {
                $exception->update(['status' => 'In Progress']);
                $exception->logActivity('Status Change', 'Risk Accepted', 'In Progress', 'Risk acceptance expired — re-confirmation required.');
            });

        return $count;
    }

    public function transition(ControlException $exception, string $to, array $extra = []): void
    {
        if ($to === $exception->status) {
            $exception->update($extra);

            return;
        }

        $allowed = self::TRANSITIONS[$exception->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "An exception cannot move from {$exception->status} to {$to}.",
            ]);
        }

        $exception->update(['status' => $to, ...$extra]);
    }

    public function userPerformedSourceTest(ControlException $exception, User $user): bool
    {
        if ($exception->source_type !== 'Test') {
            return false;
        }

        $instance = TestInstance::withoutGlobalScopes()->find($exception->source_id);

        return $instance !== null && $instance->assigned_tester_id === $user->id;
    }

    private function defaultClosureDays(string $severity): int
    {
        return match ($severity) {
            'Critical' => 7,
            'High' => 14,
            'Medium' => 30,
            'Low' => 60,
            default => 30,
        };
    }
}
