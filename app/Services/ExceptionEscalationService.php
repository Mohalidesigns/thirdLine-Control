<?php

namespace App\Services;

use App\Models\ControlException;
use App\Models\ExceptionAction;
use App\Models\ExceptionEscalation;
use App\Models\ExceptionResponse;
use App\Models\ExceptionSlaPolicy;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ExceptionEscalationClosedNotification;
use App\Notifications\ExceptionEscalationIssuedNotification;
use App\Notifications\ExceptionEscalationWithdrawnNotification;
use App\Notifications\ExceptionResponseAcceptedNotification;
use App\Notifications\ExceptionResponseRejectedNotification;
use App\Notifications\ExceptionResponseSubmittedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * CR-01: the Exception Manager's issue → answer → review → close loop.
 *
 * This service WRAPS the FR-5 exception lifecycle in ExceptionService — it
 * never replaces it. Issuing does not move the exception's own status, and
 * Verified-Closed stays the control function's act, now additionally
 * blocked while any escalation here is open (R-D).
 */
class ExceptionEscalationService
{
    public function __construct(
        private ExceptionSlaCalculator $slaCalculator,
        private ExceptionRoutingResolver $routingResolver,
        private NotificationDispatcher $dispatcher,
    ) {}

    /**
     * CR1.1 — fan-out issue: one escalation per target, each with its own
     * clock, respondent and response thread. A target with no resolvable
     * respondent fails the whole issue LOUDLY (R-G): validation error to
     * the issuer, logged warning, and no escalation rows written.
     *
     * @param  array<int, array{
     *     target_type: string, unit_id?: ?int, process_id?: ?int,
     *     respondent_id?: ?int, external_name?: ?string, external_email?: ?string,
     *     external_organisation?: ?string, cc_user_ids?: ?array,
     *     issue_note: string, required_response?: string,
     * }>  $targets
     * @return array<int, ExceptionEscalation>
     */
    public function issue(ControlException $exception, array $targets, User $actor): array
    {
        $tenant = Tenant::findOrFail($exception->tenant_id);
        $resolved = [];

        // Resolve every target BEFORE writing anything: a three-department
        // issue where the third has nobody to answer must create zero rows.
        foreach ($targets as $index => $target) {
            $isExternal = ($target['target_type'] ?? null) === 'external_party';

            if ($isExternal) {
                if (empty($target['external_email']) || empty($target['external_name'])) {
                    throw ValidationException::withMessages([
                        "targets.{$index}" => 'An external party needs a contact name and email address.',
                    ]);
                }

                $resolved[$index] = ['respondent' => null, 'target' => $target];

                continue;
            }

            $respondent = $this->routingResolver->resolveRespondent(
                $exception,
                $target['target_type'],
                $target['unit_id'] ?? null,
                $target['process_id'] ?? null,
                $target['respondent_id'] ?? null,
            );

            if (! $respondent) {
                Log::warning('Exception escalation refused: no resolvable respondent.', [
                    'exception' => $exception->reference,
                    'tenant_id' => $exception->tenant_id,
                    'target' => $target,
                    'remedy' => 'Name a respondent, appoint a unit head or process owner, or add a routing rule.',
                ]);

                throw ValidationException::withMessages([
                    "targets.{$index}" => 'No respondent could be resolved for this target — an escalation must never be created with nobody to answer it. Name a respondent, appoint a unit head/process owner, or configure a routing rule.',
                ]);
            }

            $resolved[$index] = ['respondent' => $respondent, 'target' => $target];
        }

        $policy = $this->policyFor($exception, $tenant);

        $escalations = DB::transaction(function () use ($exception, $resolved, $actor, $policy, $tenant) {
            $rows = [];
            $issuedAt = now();

            foreach ($resolved as ['respondent' => $respondent, 'target' => $target]) {
                $ccUsers = $this->ccUsers($exception->tenant_id, $target['cc_user_ids'] ?? []);

                $escalation = ExceptionEscalation::create([
                    'tenant_id' => $exception->tenant_id,
                    'exception_id' => $exception->id,
                    'reference' => ExceptionEscalation::nextReference('EXE'),
                    'round_no' => 1,
                    'target_type' => $target['target_type'],
                    'unit_id' => $target['unit_id'] ?? null,
                    'process_id' => $target['process_id'] ?? null,
                    'respondent_id' => $respondent?->id,
                    'external_name' => $target['external_name'] ?? null,
                    'external_email' => $target['external_email'] ?? null,
                    'external_organisation' => $target['external_organisation'] ?? null,
                    'cc_user_ids' => $ccUsers->pluck('id')->all() ?: null,
                    'severity' => $exception->severity,
                    'issued_by' => $actor->id,
                    'issued_at' => $issuedAt,
                    'issue_note' => $target['issue_note'],
                    'required_response' => $target['required_response'] ?? 'both',
                    'acknowledge_due_at' => $this->slaCalculator->acknowledgeDueAt($issuedAt->copy(), $policy, $tenant),
                    'response_due_at' => $this->slaCalculator->responseDueAt($issuedAt->copy(), $policy, $tenant),
                    'sla_policy_id' => $policy->id,
                    'status' => 'Issued',
                    'notified_to' => $this->recipientSnapshot($respondent, $ccUsers, $target),
                ]);

                $exception->logActivity(
                    'Escalation Issued',
                    null,
                    $escalation->targetLabel(),
                    "{$escalation->reference}: ".str($target['issue_note'])->limit(180),
                );
                $escalation->auditAction('issued', null, [
                    'target' => $escalation->targetLabel(),
                    'respondent' => $respondent?->name ?? $escalation->external_email,
                    'response_due_at' => $escalation->response_due_at?->toIso8601String(),
                ]);

                $rows[] = $escalation;
            }

            return $rows;
        });

        foreach ($escalations as $escalation) {
            $this->deliverIssueNotice($escalation);
        }

        $this->refreshExceptionCache($exception->fresh());

        return $escalations;
    }

    /**
     * CR1.4 — Accept: the response stands, its committed action becomes an
     * exception_action owned by the department, and the response clock stops.
     * Only a holder of 'close exceptions' may accept (R-C) — enforced by
     * ExceptionEscalationPolicy; the SoD re-check here is the last line.
     */
    public function acceptResponse(ExceptionEscalation $escalation, ExceptionResponse $response, User $reviewer, array $review = []): ExceptionEscalation
    {
        $this->assertReviewable($escalation, $response, $reviewer);

        if (! $reviewer->can('close exceptions') || ! $reviewer->can('review exception-responses')) {
            throw ValidationException::withMessages([
                'review' => "Accepting a response is a closure act — it requires both 'review exception-responses' and 'close exceptions' (R-C).",
            ]);
        }

        DB::transaction(function () use ($escalation, $response, $reviewer, $review) {
            $response->update([
                'review_status' => 'Accepted',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $review['review_note'] ?? null,
            ]);

            $escalation->update(['status' => 'Accepted']);

            if ($response->agreed_action_plan) {
                $targetDate = $review['action_target_date']
                    ?? $response->proposed_target_date?->toDateString();

                if (! $targetDate) {
                    throw ValidationException::withMessages([
                        'action_target_date' => 'The committed action needs a target date — the response proposed none.',
                    ]);
                }

                $action = ExceptionAction::create([
                    'tenant_id' => $escalation->tenant_id,
                    'exception_id' => $escalation->exception_id,
                    'escalation_id' => $escalation->id,
                    'response_id' => $response->id,
                    'reference' => ExceptionAction::nextReference('EXA'),
                    'title' => $review['action_title'] ?? str($response->agreed_action_plan)->limit(200)->toString(),
                    'description' => $response->agreed_action_plan,
                    'action_type' => $review['action_type'] ?? 'corrective',
                    'owner_id' => $response->responder_id ?? $escalation->respondent_id ?? $reviewer->id,
                    'unit_id' => $escalation->unit_id,
                    'target_date' => $targetDate,
                    'status' => 'Not Started',
                ]);

                $escalation->exception->logActivity('Action Committed', null, $action->reference, $action->title);
            }

            $escalation->exception->logActivity('Response Accepted', "Round {$response->round_no}", $escalation->targetLabel(), $review['review_note'] ?? null);
            $escalation->auditAction('response_accepted', null, ['round' => $response->round_no]);
        });

        $this->notifyRespondent($escalation, 'exception.response.accepted', new ExceptionResponseAcceptedNotification($escalation));
        $this->refreshExceptionCache($escalation->exception->fresh());

        return $escalation->fresh();
    }

    /**
     * CR1.4 — Reject & re-issue: the round is preserved immutable, the
     * escalation moves to a new round with a fresh SLA clock, and the
     * department is notified again. This is the loop the client means by
     * "we would expect response and closure".
     */
    public function rejectResponse(ExceptionEscalation $escalation, ExceptionResponse $response, User $reviewer, string $reason): ExceptionEscalation
    {
        $this->assertReviewable($escalation, $response, $reviewer);

        $tenant = Tenant::findOrFail($escalation->tenant_id);
        $policy = $escalation->slaPolicy
            ?? $this->policyFor($escalation->exception, $tenant);

        DB::transaction(function () use ($escalation, $response, $reviewer, $reason, $policy, $tenant) {
            $response->update([
                'review_status' => 'Rejected',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $escalation->update(['status' => 'Rejected']);

            $this->reissueRound($escalation, $policy, $tenant);

            $escalation->exception->logActivity('Response Rejected', "Round {$response->round_no}", $escalation->targetLabel(), $reason);
            $escalation->exception->logActivity('Reissued', "Round {$response->round_no}", "Round {$escalation->round_no}");
            $escalation->auditAction('response_rejected', null, ['round' => $response->round_no, 'reason' => $reason]);
            $escalation->auditAction('reissued', null, ['round' => $escalation->round_no]);
        });

        $this->notifyRespondent($escalation->fresh(), 'exception.response.rejected', new ExceptionResponseRejectedNotification($escalation->fresh()));
        $this->refreshExceptionCache($escalation->exception->fresh());

        return $escalation->fresh();
    }

    /**
     * CR1.5 — Close the escalation against evidence: every committed action
     * must be Completed, and the control function records validation.
     * Partially Effective or Ineffective RE-ISSUES rather than closes.
     */
    public function close(ExceptionEscalation $escalation, User $closer, string $validationStatus, ?string $note = null): ExceptionEscalation
    {
        if ($escalation->status !== 'Accepted') {
            throw ValidationException::withMessages([
                'closure' => "Escalation {$escalation->reference} is {$escalation->status} — only an accepted escalation can be validated and closed.",
            ]);
        }

        $openActions = $escalation->actions()->open()->get();

        if ($openActions->isNotEmpty()) {
            throw ValidationException::withMessages([
                'closure' => 'Committed actions are still open: '.$openActions->pluck('reference')->implode(', '),
            ]);
        }

        DB::transaction(function () use ($escalation, $closer, $validationStatus, $note) {
            $escalation->actions()
                ->where('status', 'Completed')
                ->each(fn (ExceptionAction $action) => $action->update([
                    'validation_status' => $validationStatus,
                    'validated_by' => $closer->id,
                    'validated_at' => now(),
                    'validation_note' => $note,
                ]));

            if ($validationStatus === 'Effective') {
                $escalation->update([
                    'status' => 'Closed',
                    'closed_at' => now(),
                    'closed_by' => $closer->id,
                    'closure_note' => $note,
                ]);

                $escalation->exception->logActivity('Status Change', 'Escalation open', 'Escalation closed', "{$escalation->reference}: validated {$validationStatus}");
                $escalation->auditAction('closed', null, ['validation' => $validationStatus]);
            }
        });

        if ($validationStatus !== 'Effective') {
            // Remediation that did not hold goes back to the department for
            // a fresh round rather than quietly closing.
            $tenant = Tenant::findOrFail($escalation->tenant_id);
            $policy = $escalation->slaPolicy ?? $this->policyFor($escalation->exception, $tenant);

            DB::transaction(function () use ($escalation, $validationStatus, $note, $policy, $tenant) {
                $this->reissueRound($escalation, $policy, $tenant);

                $escalation->exception->logActivity(
                    'Reissued',
                    "Validated {$validationStatus}",
                    "Round {$escalation->round_no}",
                    $note,
                );
                $escalation->auditAction('validation_failed', null, ['validation' => $validationStatus, 'note' => $note]);
            });

            $this->notifyRespondent($escalation->fresh(), 'exception.response.rejected', new ExceptionResponseRejectedNotification($escalation->fresh()));
            $this->refreshExceptionCache($escalation->exception->fresh());

            return $escalation->fresh();
        }

        $this->notifyRespondent($escalation, 'exception.escalation.closed', new ExceptionEscalationClosedNotification($escalation));
        $this->refreshExceptionCache($escalation->exception->fresh());

        return $escalation->fresh();
    }

    /**
     * CR1.4 — Withdraw an escalation issued in error. Control function only,
     * reason mandatory, audited.
     */
    public function withdraw(ExceptionEscalation $escalation, User $actor, string $reason): ExceptionEscalation
    {
        if (! $escalation->isOpen()) {
            throw ValidationException::withMessages([
                'withdrawal' => "Escalation {$escalation->reference} is already {$escalation->status}.",
            ]);
        }

        $escalation->update([
            'status' => 'Withdrawn',
            'withdrawn_at' => now(),
            'withdrawn_by' => $actor->id,
            'withdrawal_reason' => $reason,
        ]);

        $escalation->exception->logActivity('Escalation Withdrawn', $escalation->targetLabel(), null, $reason);
        $escalation->auditAction('withdrawn', null, ['reason' => $reason]);

        $this->notifyRespondent($escalation, 'exception.escalation.withdrawn', new ExceptionEscalationWithdrawnNotification($escalation));
        $this->refreshExceptionCache($escalation->exception->fresh());

        return $escalation;
    }

    /**
     * Risk-accepted closure of the parent exception withdraws every open
     * escalation, carrying the acceptance reference as the reason (CR1.5).
     */
    public function withdrawAllOpen(ControlException $exception, User $actor, string $reason): void
    {
        $exception->openEscalations()->get()
            ->each(fn (ExceptionEscalation $escalation) => $this->withdraw($escalation, $actor, $reason));
    }

    /**
     * R-D — the guard ExceptionService::verifyAndClose calls: an exception
     * cannot reach Verified-Closed with an escalation still open. The error
     * NAMES the open escalations so the closer knows what is outstanding.
     */
    public function assertClosable(ControlException $exception): void
    {
        $open = $exception->openEscalations()->get(['id', 'reference', 'status']);

        if ($open->isNotEmpty()) {
            throw ValidationException::withMessages([
                'closure' => 'Open escalations must be closed or withdrawn first: '
                    .$open->map(fn ($e) => "{$e->reference} ({$e->status})")->implode(', '),
            ]);
        }
    }

    /** Notify the control function that a response has landed (CR1.2). */
    public function notifyResponseSubmitted(ExceptionEscalation $escalation, ExceptionResponse $response): void
    {
        $reviewers = collect([$escalation->issuer])->filter();

        if ($reviewers->isNotEmpty()) {
            $this->dispatcher->send(
                $reviewers,
                'exception.response.submitted',
                new ExceptionResponseSubmittedNotification($escalation),
            );
        }
    }

    /**
     * The denormalised caches on control_exceptions (CR-01) — recomputed
     * from the escalation rows, which stay the source of truth.
     */
    public function refreshExceptionCache(ControlException $exception): void
    {
        $statuses = $exception->escalations()->pluck('status');

        $escalationStatus = match (true) {
            $statuses->isEmpty() => 'None',
            $statuses->contains(fn ($s) => in_array($s, ['Rejected', 'Reissued'], true)) => 'Response Rejected',
            $statuses->contains(fn ($s) => in_array($s, ['Responded', 'Under Review'], true)) => 'Response Under Review',
            $statuses->contains('Acknowledged') => 'Awaiting Response',
            $statuses->contains('Issued') => 'Issued',
            $statuses->contains('Accepted') => 'Response Accepted',
            default => 'All Closed',
        };

        $exception->updateQuietly([
            'escalation_status' => $escalationStatus,
            'first_escalated_at' => $exception->first_escalated_at
                ?? $exception->escalations()->min('issued_at'),
            'last_response_at' => $exception->escalations()->max('last_response_at'),
            'open_escalation_count' => $exception->openEscalations()->count(),
            'is_response_overdue' => $exception->escalations()->responseOverdue()->exists(),
        ]);
    }

    // ── CR1.8: reporting aggregates ─────────────────────────────────

    /**
     * Department scorecard: how each addressed unit behaves under the loop.
     *
     * @return array<int, array<string, mixed>>
     */
    public function departmentScorecard(int $tenantId): array
    {
        $escalations = ExceptionEscalation::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->with('unit:id,name')
            ->get();

        return $escalations
            ->groupBy(fn ($e) => $e->unit?->name ?? ($e->external_organisation ?? 'Unassigned'))
            ->map(function ($group, $department) {
                $issued = $group->count();
                $acknowledged = $group->whereNotNull('acknowledged_at');
                $responded = $group->whereNotNull('first_response_at');
                $closed = $group->where('status', 'Closed');
                $responseDays = $group
                    ->filter(fn ($e) => $e->issued_at && $e->first_response_at)
                    ->map(fn ($e) => $e->issued_at->diffInDays($e->first_response_at));

                return [
                    'department' => $department,
                    'issued' => $issued,
                    'acknowledged_on_time_pct' => $acknowledged->count()
                        ? round($acknowledged->where('is_acknowledgement_late', false)->count() / $acknowledged->count() * 100)
                        : null,
                    'responded_on_time_pct' => $responded->count()
                        ? round($group->filter(fn ($e) => $e->first_response_at && ! $e->responses->first()?->is_late)->count() / $responded->count() * 100)
                        : null,
                    'average_response_days' => $responseDays->count() ? round($responseDays->average(), 1) : null,
                    'open_overdue' => $group->filter(fn ($e) => $e->isAwaitingResponse()
                        && $e->response_due_at?->isPast())->count(),
                    'closure_rate_pct' => $issued ? round($closed->count() / $issued * 100) : 0,
                    'reissue_rate_pct' => $issued ? round($group->where('round_no', '>', 1)->count() / $issued * 100) : 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Ageing of open escalations by department and severity (CR1.8).
     *
     * @return array<int, array<string, mixed>>
     */
    public function ageing(int $tenantId): array
    {
        $buckets = ['0-30' => [0, 30], '31-60' => [31, 60], '61-90' => [61, 90], '90+' => [91, PHP_INT_MAX]];

        return ExceptionEscalation::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->open()
            ->with('unit:id,name')
            ->get()
            ->groupBy(fn ($e) => ($e->unit?->name ?? $e->external_organisation ?? 'Unassigned').'|'.$e->severity)
            ->map(function ($group, $key) use ($buckets) {
                [$department, $severity] = explode('|', $key, 2);
                $row = ['department' => $department, 'severity' => $severity];

                foreach ($buckets as $label => [$from, $to]) {
                    $row[$label] = $group->filter(function ($e) use ($from, $to) {
                        $age = (int) $e->issued_at?->diffInDays(now());

                        return $age >= $from && $age <= $to;
                    })->count();
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * The board/ARCC extract: everything escalated, unanswered past SLA, or
     * re-issued more than once (CR1.8).
     */
    public function boardExtract(int $tenantId)
    {
        return ExceptionEscalation::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q
                ->whereNotNull('matrix_escalated_at')
                ->orWhere(fn ($w) => $w->responseOverdue())
                ->orWhere('round_no', '>', 2))
            ->with(['exception:id,reference,title,severity', 'unit:id,name', 'respondent:id,name'])
            ->orderByRaw("case severity when 'Critical' then 0 when 'High' then 1 when 'Medium' then 2 else 3 end")
            ->get();
    }

    // ── internals ───────────────────────────────────────────────────

    /**
     * A fresh round: new response clock from the SLA policy, reminder and
     * ladder state reset so the chase and the matrix start over for the new
     * round. Prior rounds stay visible and immutable (R-F).
     */
    private function reissueRound(ExceptionEscalation $escalation, ExceptionSlaPolicy $policy, Tenant $tenant): void
    {
        $escalation->update([
            'status' => 'Reissued',
            'round_no' => $escalation->round_no + 1,
            'response_due_at' => $this->slaCalculator->responseDueAt(now(), $policy, $tenant),
            'is_response_late' => false,
            'reminder_count' => 0,
            'last_reminder_at' => null,
            'matrix_escalated_at' => null,
        ]);
    }

    private function policyFor(ControlException $exception, Tenant $tenant): ExceptionSlaPolicy
    {
        $policy = ExceptionSlaPolicy::activeFor($tenant->id, $exception->severity);

        if (! $policy) {
            throw ValidationException::withMessages([
                'sla' => "No active exception SLA policy exists for {$exception->severity} severity — configure one under Settings → Exception SLAs (R1: SLAs are data, never code).",
            ]);
        }

        return $policy;
    }

    private function deliverIssueNotice(ExceptionEscalation $escalation): void
    {
        $notification = new ExceptionEscalationIssuedNotification($escalation);

        if ($escalation->respondent) {
            $this->dispatcher->send($escalation->respondent, 'exception.escalation.issued', $notification);
        }

        foreach ($this->ccUsers($escalation->tenant_id, $escalation->cc_user_ids ?? []) as $cc) {
            $this->dispatcher->send($cc, 'exception.escalation.issued', new ExceptionEscalationIssuedNotification($escalation));
        }

        // External processors receive their notice only once a secure link
        // exists to carry — PublicExceptionResponseController generates it
        // and ExceptionResponseService sends the mail with the one-time URL.
    }

    private function notifyRespondent(ExceptionEscalation $escalation, string $eventKey, $notification): void
    {
        if ($escalation->respondent) {
            $this->dispatcher->send($escalation->respondent, $eventKey, $notification);
        } elseif ($escalation->external_email) {
            $this->dispatcher->sendExternal($escalation->external_email, $eventKey, $notification);
        }
    }

    private function ccUsers(int $tenantId, array $ids)
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('id', array_map('intval', $ids))
            ->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function recipientSnapshot(?User $respondent, $ccUsers, array $target): array
    {
        $snapshot = [];

        if ($respondent) {
            $snapshot[] = ['id' => $respondent->id, 'name' => $respondent->name, 'email' => $respondent->email, 'via' => 'respondent'];
        }

        if (! empty($target['external_email'])) {
            $snapshot[] = [
                'id' => null,
                'name' => $target['external_name'] ?? $target['external_email'],
                'email' => $target['external_email'],
                'via' => 'secure_link',
            ];
        }

        foreach ($ccUsers as $cc) {
            $snapshot[] = ['id' => $cc->id, 'name' => $cc->name, 'email' => $cc->email, 'via' => 'cc'];
        }

        return $snapshot;
    }

    /**
     * R-B: nobody reviews their own answer, and a review must have a
     * submitted, still-pending response in front of it.
     */
    private function assertReviewable(ExceptionEscalation $escalation, ExceptionResponse $response, User $reviewer): void
    {
        if ($response->escalation_id !== $escalation->id) {
            throw ValidationException::withMessages(['review' => 'That response does not belong to this escalation.']);
        }

        if (! $response->submitted_at || $response->review_status !== 'Pending Review') {
            throw ValidationException::withMessages([
                'review' => 'Only a submitted, pending response can be reviewed.',
            ]);
        }

        if ($response->responder_id === $reviewer->id) {
            throw ValidationException::withMessages([
                'review' => 'The user who submitted a response cannot review it — no exceptions, no admin bypass (R-B).',
            ]);
        }
    }
}
