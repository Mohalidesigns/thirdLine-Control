<?php

namespace App\Services;

use App\Models\CheckResult;
use App\Models\ControlException;
use App\Models\Finding;
use App\Models\TestInstance;
use App\Models\User;
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

        return ControlException::create([
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
        ]);
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

        return ControlException::create([
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
        ]);
    }

    public function assign(ControlException $exception, User $assignee, User $actor): ControlException
    {
        $this->transition($exception, in_array($exception->status, ['Open'], true) ? 'Assigned' : $exception->status, [
            'responsible_party_id' => $assignee->id,
        ]);

        $exception->logActivity('Assignment', null, $assignee->name);

        return $exception;
    }

    public function markInProgress(ControlException $exception, ?string $plan = null): ControlException
    {
        $this->transition($exception, 'In Progress', array_filter(['remediation_plan' => $plan]));
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

        $this->transition($exception, 'Verified-Closed', [
            'verification_method' => $verification['verification_method'],
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'closure_notes' => $verification['closure_notes'] ?? null,
            'is_overdue' => false,
        ]);

        $exception->logActivity('Verification', 'Remediated', 'Verified-Closed', $verification['verification_method']);
        $exception->auditAction('closed', null, $verification);

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
    public function acceptRisk(ControlException $exception, User $approver, string $reason, string $expiryDate): ControlException
    {
        if (! $approver->hasAnyRole(['Control Function Head', 'System Administrator'])) {
            throw ValidationException::withMessages([
                'risk_acceptance' => 'Risk acceptance requires senior approval.',
            ]);
        }

        $from = $exception->status;

        $this->transition($exception, 'Risk Accepted', [
            'risk_acceptance_reason' => $reason,
            'risk_accepted_by' => $approver->id,
            'risk_acceptance_expiry' => $expiryDate,
            'is_overdue' => false,
        ]);

        $exception->logActivity('Status Change', $from, 'Risk Accepted', $reason);

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
                    $exception->updateQuietly([
                        'age_days' => (int) $exception->date_raised->diffInDays(now()->startOfDay()),
                        'is_overdue' => $exception->target_closure_date !== null
                            && now()->startOfDay()->gt($exception->target_closure_date),
                    ]);
                    $count++;
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
